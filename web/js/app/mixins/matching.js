/**
 * Mixin квитования, автоквитования и правил matching.
 *
 * Работает с массивом `entries` из EntriesMixin и управляет выбранными
 * NostroEntry, ручным квитованием, просмотром групп по `match_id`,
 * расквитованием, пошаговым автоквитованием и CRUD правил. Сервер проверяет
 * баланс выбранных записей, уникальность `match_id`, область компании и
 * допустимость операций с архивом.
 */
var MatchingMixin = {
    /**
     * Начальное состояние подсистемы квитования.
     *
     * @returns {Object} Vue data для выбора строк, модалок, правил и прогресса автоквитования.
     */
    data: function () {
        return {
            selectedIds:      [],
            selectionSummary: null,
            matchGroupEntries: [],
            matchGroupId:      null,
            matchGroupLoading: false,
            matchingRules:    [],
            loadingRules:     false,
            reopenRulesListAfterRule: false,
            /**
             * Форма создания/редактирования правила автоквитования.
             *
             * @type {Object}
             * @property {?number} id ID правила; `null` означает создание.
             * @property {string} pair_type Тип пары: `LS`, `LL` или `SS`.
             * @property {boolean} cross_id_search Включён ли поиск совпадений между любыми ID-полями.
             * @property {boolean} id_prefix_match Включено ли сравнение отмеченных ID-полей по префиксу.
             * @property {?number} id_prefix_length Количество первых символов ID для сравнения.
             * @property {number} priority Приоритет выполнения правила.
             */
            editingRule: {
                id: null, name: '', pool_id: null, pool_ids: [], section: 'NRE', pair_type: 'LS',
                match_dc: true, match_amount: true, match_value_date: true,
                match_instruction_id: false, match_end_to_end_id: false,
                match_transaction_id: false, match_message_id: false,
                reference_value: '',
                group_match_enabled: false, group_ls_type: null,
                cross_id_search: false, id_prefix_match: false, id_prefix_length: null, is_active: true, priority: 100, description: ''
            },
            autoMatchRunning: false,
            /**
             * Состояние пошагового автоквитования с живым прогрессом (вариант B).
             *
             * @type {?Object}
             * @property {string} job_id ID задания в таблице automatch_jobs.
             * @property {number} percent Процент выполнения (по сквитованным записям).
             * @property {number} total_matched Сквитовано пар на текущий момент.
             * @property {number} remaining_unmatched Оценка оставшихся несквитованных записей.
             * @property {string} phase 'searching' (поиск пар) | 'matching' (квитование).
             * @property {string} current_rule_name Имя текущего правила.
             * @property {string} eta_text Человекочитаемая оценка оставшегося времени.
             */
            autoMatchProgress: null,
            autoMatchScope: {
                type: 'all',   // 'all' | 'pool' | 'group' | 'category'
                poolId: null,
                poolName: ''
            }
        };
    },

    computed: {
        /**
         * Возвращает раздел компании пользователя, влияющий на проверку баланса.
         *
         * @returns {string} Раздел компании, например `NRE` или `INV`.
         */
        userSection: function () {
            return (window.AppConfig && window.AppConfig.companySection) || '';
        },
        /**
         * Проверяет, достаточно ли выбранных записей для ручного квитования.
         *
         * В INV допускается одна запись с нулевой разницей, в остальных случаях
         * требуется минимум две записи.
         *
         * @returns {boolean} `true`, если кнопка квитования может быть активна.
         */
        hasSelection: function () {
            // Разрешаем 1 запись если diff = 0 (например, сумма записи = 0)
            if (this.selectedIds.length === 1 && this.selectionSummary && this.summaryDiff === 0) {
                return true;
            }
            return this.selectedIds.length >= 2;
        },
        /**
         * Возвращает актуальную разницу выбранных записей.
         *
         * Для INV и записей одного счёта сравнение идёт по Debit/Credit, для
         * остальных NRE-сценариев — по Ledger/Statement.
         *
         * @returns {number|null} Разница сумм или `null`, если нет summary.
         */
        summaryDiff: function () {
            if (!this.selectionSummary) return null;
            // В INV всегда сравниваем Debit-Credit. В NRE — если все выбранные
            // записи на одном счёте, тоже сравниваем по D/C; иначе по L/S.
            if (this.userSection === 'INV' || this.selectionSummary.same_account) {
                return this.selectionSummary.diff_dc;
            }
            return this.selectionSummary.diff;
        },
        /**
         * Проверяет, сбалансирована ли текущая выборка для квитования.
         *
         * @returns {boolean|null} `true`, если разница равна нулю.
         */
        summaryBalanced: function () { return this.selectionSummary && this.summaryDiff === 0; },

        /**
         * Проверяет, можно ли сквитовать выбранный набор записей.
         *
         * Используется на странице "Выверка по всем ностро-банкам", где в
         * выборе могут оказаться записи из разных банков и валют. Возвращает
         * объект `{ok, reason}`; на главной странице выверки записи всегда
         * принадлежат одному выбранному пулу, поэтому проверка тривиально
         * проходит.
         *
         * @returns {{ok: boolean, reason: string}} Признак валидности и причина блокировки.
         */
        matchValidity: function () {
            var ids = this.selectedIds || [];
            if (!ids.length) return { ok: true, reason: '' };

            var entries = (this.entries || []).filter(function (e) {
                return ids.indexOf(e.id) !== -1;
            });
            if (!entries.length) return { ok: true, reason: '' };

            var currencies = {};
            var pools      = {};
            var poolUnknown = false;
            entries.forEach(function (e) {
                currencies[String(e.currency || '').toUpperCase()] = true;
                var pid = (e.pool_id !== undefined && e.pool_id !== null && e.pool_id !== '')
                    ? String(e.pool_id)
                    : null;
                if (pid === null) poolUnknown = true;
                else pools[pid] = true;
            });

            if (Object.keys(currencies).length > 1) {
                return {
                    ok: false,
                    reason: 'Нельзя квитовать записи в разных валютах (' + Object.keys(currencies).join(', ') + ')'
                };
            }
            if (Object.keys(pools).length > 1 || (poolUnknown && Object.keys(pools).length > 0)) {
                return { ok: false, reason: 'Нельзя квитовать записи из разных ностро-банков' };
            }
            return { ok: true, reason: '' };
        }
    },

    methods: {

        /**
         * Переключает выбор записи выверки.
         *
         * Изменяет `selectedIds` и пересчитывает summary для панели ручного
         * квитования.
         *
         * @param {number|string} id Идентификатор NostroEntry.
         * @returns {void}
         */
        toggleEntrySelection: function (id) {
            var i = this.selectedIds.indexOf(id);
            if (i === -1) this.selectedIds.push(id);
            else          this.selectedIds.splice(i, 1);
            this.updateSummary();
        },

        /**
         * Проверяет, выбрана ли запись.
         *
         * @param {number|string} id Идентификатор NostroEntry.
         * @returns {boolean} `true`, если ID присутствует в `selectedIds`.
         */
        isSelected: function (id) { return this.selectedIds.indexOf(id) !== -1; },

        /**
         * Очищает текущий выбор и summary ручного квитования.
         *
         * @returns {void}
         */
        clearSelection: function () {
            this.selectedIds      = [];
            this.selectionSummary = null;
        },

        /**
         * Пересчитывает суммы выбранных записей для проверки баланса.
         *
         * Читает `entries` и `selectedIds`, заполняет `selectionSummary` суммами
         * Ledger/Statement и Debit/Credit. Значения округляются до копеек для
         * отображения UI; окончательная проверка выполняется на сервере.
         *
         * @returns {void}
         */
        updateSummary: function () {
            var self = this;
            if (!self.selectedIds.length) { self.selectionSummary = null; return; }
            var sL = 0, sS = 0, cL = 0, cS = 0;
            var sD = 0, sCr = 0, cD = 0, cCr = 0;
            var accountIds = {};
            (self.entries || []).forEach(function (e) {
                if (self.selectedIds.indexOf(e.id) === -1) return;
                var a = parseFloat(e.amount || 0);
                if (e.ls === 'L') { sL += a; cL++; } else { sS += a; cS++; }
                if (e.dc === 'Debit') { sD += a; cD++; } else { sCr += a; cCr++; }
                accountIds[e.account_id] = true;
            });
            self.selectionSummary = {
                sum_ledger:    Math.round(sL * 100) / 100,
                sum_statement: Math.round(sS * 100) / 100,
                diff:          Math.round((sL - sS) * 100) / 100,
                cnt_ledger:    cL,
                cnt_statement: cS,
                sum_debit:     Math.round(sD * 100) / 100,
                cnt_debit:     cD,
                sum_credit:    Math.round(sCr * 100) / 100,
                cnt_credit:    cCr,
                diff_dc:       Math.round((sD - sCr) * 100) / 100,
                same_account:  Object.keys(accountIds).length === 1,
            };
        },

        /**
         * Запускает сценарий ручного квитования выбранных записей.
         *
         * Проверяет минимальное количество выбранных записей и запрещает
         * квитование, если выбранные суммы не сбалансированы.
         *
         * @returns {void}
         */
        matchSelected: function () {
            var self = this;
            if (!self.hasSelection) {
                var minMsg = (self.userSection === 'INV')
                    ? 'Выберите одну запись с нулевой суммой или 2+ сбалансированные записи'
                    : 'Выберите минимум 2 записи';
                Swal.fire({ icon: 'warning', title: minMsg, toast: true,
                    position: 'top-end', timer: 2500, showConfirmButton: false });
                return;
            }
            // На /all-nostro в выборе могут оказаться записи разных банков/валют —
            // показываем пользователю причину блокировки.
            var v = self.matchValidity;
            if (v && !v.ok) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Квитование невозможно',
                    text: v.reason,
                    confirmButtonText: 'Понятно',
                    confirmButtonColor: '#6366f1'
                });
                return;
            }
            if (self.summaryDiff !== 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Квитование невозможно',
                    html: 'Выбранные записи имеют дисбаланс сумм.<br>' +
                        'Разница: <b>' + self.formatAmount(Math.abs(self.summaryDiff)) + '</b>.<br>' +
                        'Сначала устраните дисбаланс, затем повторите квитование.',
                    confirmButtonText: 'Понятно',
                    confirmButtonColor: '#6366f1'
                });
                return;
            }
            self._doMatch();
        },

        /**
         * Отправляет выбранные записи на серверное ручное квитование.
         *
         * Вызывает POST `matchManual` с `ids` и section, затем перезагружает
         * таблицу записей при успехе. Сервер присваивает `match_id`, `matched_at`
         * и пишет аудит.
         *
         * @returns {void}
         */
        _doMatch: function () {
            var self = this;
            if (!self.summaryBalanced) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Квитование невозможно',
                    text: 'Нельзя сквитовать записи с дисбалансом сумм.',
                    confirmButtonText: 'Понятно',
                    confirmButtonColor: '#6366f1'
                });
                return;
            }

            var payload = { ids: self.selectedIds };
            if (self.userSection) payload.section = self.userSection;

            SmartMatchApi.post(window.AppRoutes.matchManual, payload).then(function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: res.message, toast: true,
                        position: 'top-end', timer: 2000, showConfirmButton: false });
                    self.loadEntries(true);
                } else {
                    Swal.fire({ icon: 'error', title: res.message });
                }
            });
        },

        /**
         * Открывает модалку состава сквитованной группы.
         *
         * Загружает все активные записи с указанным `match_id` через GET
         * `matchGroup`; при ошибке закрывает модалку и показывает SweetAlert.
         *
         * @param {string} matchId Идентификатор группы квитования.
         * @returns {void}
         */
        showMatchGroup: function (matchId) {
            if (!matchId) return;
            var self = this;
            self.matchGroupId = matchId;
            self.matchGroupEntries = [];
            self.matchGroupLoading = true;
            self._showModal('matchGroupModal');

            SmartMatchApi.get(window.AppRoutes.matchGroup, { match_id: matchId })
                .then(function (r) {
                    var data = r.data || r;
                    if (data.success) {
                        self.matchGroupEntries = data.data;
                    } else {
                        Swal.fire({ icon: 'error', title: data.message, toast: true,
                            position: 'top-end', timer: 2500, showConfirmButton: false });
                        self._hideModal('matchGroupModal');
                    }
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', title: 'Ошибка загрузки', toast: true,
                        position: 'top-end', timer: 2500, showConfirmButton: false });
                    self._hideModal('matchGroupModal');
                })
                .finally(function () { self.matchGroupLoading = false; });
        },

        /**
         * Закрывает модалку группы квитования и очищает её состояние.
         *
         * @returns {void}
         */
        closeMatchGroupModal: function () {
            this._hideModal('matchGroupModal');
            this.matchGroupEntries = [];
            this.matchGroupId = null;
        },

        /**
         * Запускает расквитование из модалки группы.
         *
         * Использует текущий `matchGroupId`, закрывает модалку и передаёт ID в
         * `unmatchEntry()`.
         *
         * @returns {void}
         */
        unmatchFromGroup: function () {
            var self = this;
            var matchId = self.matchGroupId;
            if (!matchId) return;
            self._hideModal('matchGroupModal');
            self.unmatchEntry(matchId);
        },

        /**
         * Расквитовывает все активные записи с указанным `match_id`.
         *
         * После подтверждения вызывает POST `unmatch`; сервер очищает `match_id`,
         * `matched_at`, возвращает `match_status = U` и пишет аудит.
         *
         * @param {string} matchId Идентификатор группы квитования.
         * @returns {void}
         */
        unmatchEntry: function (matchId) {
            if (!matchId) return;
            var self = this;
            Swal.fire({
                title: 'Расквитовать?',
                html: 'Match ID: <code>' + matchId + '</code><br>Все записи вернутся в статус «Ожидает».',
                icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#ef4444', confirmButtonText: 'Расквитовать', cancelButtonText: 'Отмена'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                // ИСПРАВЛЕНО: было window.window.AppRoutes
                SmartMatchApi.post(window.AppRoutes.unmatch, { match_id: encodeURIComponent(matchId) }).then(function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: res.message, toast: true,
                            position: 'top-end', timer: 2000, showConfirmButton: false });
                        self.loadEntries(true);
                    } else {
                        Swal.fire({ icon: 'error', title: res.message });
                    }
                });
            });
        },

        /**
         * Открывает модалку выбора области автоквитования.
         *
         * По умолчанию область ограничивается выбранным ностро-банком, чтобы
         * пользователь явно подтвердил запуск правил.
         *
         * @returns {void}
         */
        runAutoMatch: function () {
            var self = this;

            if (self.selectedPool) {
                // Главная страница выверки: дефолт — текущий пул из сайдбара.
                self.autoMatchScope = {
                    type:     'pool',
                    poolId:   self.selectedPool.id,
                    poolName: self.selectedPool.name,
                };
            } else {
                // /all-nostro: сайдбара нет. Если в фильтре выбран один банк —
                // предлагаем его, иначе — `all` без привязки к конкретному пулу.
                var poolIds = (self.filters && Array.isArray(self.filters.pool_ids))
                    ? self.filters.pool_ids
                    : [];
                if (poolIds.length === 1) {
                    var pid = parseInt(poolIds[0], 10);
                    var pool = (self.accountPools || self.pools || []).find(function (p) {
                        return parseInt(p.id, 10) === pid;
                    });
                    self.autoMatchScope = {
                        type:     'pool',
                        poolId:   pid,
                        poolName: pool ? pool.name : '',
                    };
                } else {
                    self.autoMatchScope = { type: 'all', poolId: null, poolName: '' };
                }
            }

            self._showModal('autoMatchScopeModal');
        },

        /**
         * Создаёт серверную задачу пошагового автоквитования.
         *
         * Вызывает POST `autoMatchStart`, сохраняет `job_id`, список правил и
         * счётчики прогресса, затем запускает `_runAutoMatchNextStep()`.
         *
         * @returns {void}
         */
        confirmAutoMatch: function () {
            var self = this;
            self._hideModal('autoMatchScopeModal');
            self.autoMatchRunning = true;
            self.autoMatchProgress = null;

            var payload = { scope_type: self.autoMatchScope.type };
            if (self.autoMatchScope.type === 'pool' && self.autoMatchScope.poolId) {
                payload.scope_id = self.autoMatchScope.poolId;
            } else if (self.autoMatchScope.type === 'category' && self.selectedCategory) {
                payload.scope_id = self.selectedCategory.id;
            }

            SmartMatchApi.post(window.AppRoutes.autoMatchStart, payload).then(function (res) {
                if (!res.success) {
                    // Уже выполняется (защита от двойного запуска) — отдельный текст.
                    Swal.fire({
                        icon: res.already_running ? 'warning' : 'error',
                        title: res.already_running ? 'Уже выполняется' : 'Ошибка',
                        text: res.message
                    });
                    self.autoMatchRunning = false;
                    return;
                }

                self.autoMatchProgress = {
                    job_id:              res.job_id,
                    total_steps:         res.total_steps,
                    current_rule_index:  0,
                    current_rule_name:   (res.rules && res.rules[0]) ? res.rules[0].name : '',
                    total_matched:       0,
                    matched_pairs:       0,
                    rules:               res.rules,
                    step_results:        [],
                    unmatched_count:     res.unmatched_count,
                    remaining_unmatched: res.unmatched_count,
                    percent:             0,
                    phase:               'searching',
                    eta_text:            'оценка…',
                    started_ts:          Date.now()
                };

                self._runAutoMatchNextStep();
            }).catch(function () {
                self.autoMatchRunning = false;
                Swal.fire({ icon: 'error', title: 'Ошибка сети' });
            });
        },

        /**
         * Человекочитаемая оценка оставшегося времени по проценту прогресса.
         *
         * @param {number} percent Текущий процент (0..100).
         * @param {number} startedTs Метка времени старта (Date.now()).
         * @returns {string} Например «~1 мин 20 сек» или «оценка…».
         */
        _autoMatchEta: function (percent, startedTs) {
            if (!percent || percent <= 0 || percent >= 100) return percent >= 100 ? '' : 'оценка…';
            var elapsed = (Date.now() - startedTs) / 1000;
            if (elapsed < 2) return 'оценка…';
            var remain = elapsed * (100 - percent) / percent;
            if (remain < 1) return 'меньше секунды';
            var m = Math.floor(remain / 60);
            var s = Math.round(remain % 60);
            return '~' + (m > 0 ? (m + ' мин ' + s + ' сек') : (s + ' сек'));
        },

        /**
         * Выполняет следующий ограниченный шаг серверного автоквитования.
         *
         * Рекурсивно вызывает POST `autoMatchStep` до `is_finished`, обновляя на
         * каждом ответе живой прогресс (сквитовано / осталось / процент / ETA),
         * затем перезагружает таблицу и показывает итог. Ошибка шага или сети
         * останавливает процесс и снимает задание (cancel).
         *
         * @returns {void}
         */
        _runAutoMatchNextStep: function () {
            var self = this;
            if (!self.autoMatchProgress) return;

            SmartMatchApi.post(window.AppRoutes.autoMatchStep, {
                job_id: self.autoMatchProgress.job_id
            }).then(function (res) {
                if (!res.success) {
                    self.autoMatchRunning = false;
                    self.autoMatchProgress = null;
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: res.message });
                    return;
                }

                // Живой прогресс
                var p = self.autoMatchProgress;
                p.total_matched       = res.total_matched;
                p.matched_pairs       = res.matched_pairs;
                p.remaining_unmatched = res.remaining_unmatched;
                p.percent             = res.percent;
                p.phase               = res.phase;
                p.current_rule_name   = res.current_rule_name || p.current_rule_name;
                p.current_rule_index  = res.current_rule_index;
                p.step_results        = res.step_results || p.step_results;
                p.eta_text            = self._autoMatchEta(res.percent, p.started_ts);

                if (res.is_finished) {
                    self.autoMatchRunning = false;
                    self.loadEntries(true);

                    var html = '<div class="text-start">';
                    html += '<p><b>Сквитовано пар: ' + res.total_matched + '</b></p>';
                    if (res.step_results && res.step_results.length) {
                        html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Правило</th><th class="text-end">Пар</th></tr></thead><tbody>';
                        res.step_results.forEach(function (sr) {
                            var badge = sr.matched > 0
                                ? '<span class="badge bg-success">' + sr.matched + '</span>'
                                : '<span class="badge bg-secondary">0</span>';
                            var err = sr.error ? ' <span class="text-danger">⚠ ' + sr.error + '</span>' : '';
                            html += '<tr><td>' + sr.rule_name + err + '</td><td class="text-end">' + badge + '</td></tr>';
                        });
                        html += '</tbody></table>';
                    }
                    html += '</div>';

                    Swal.fire({
                        icon: res.total_matched > 0 ? 'success' : 'info',
                        title: 'Автоквитование завершено',
                        html: html,
                        width: 500
                    });
                    self.autoMatchProgress = null;
                } else {
                    self._runAutoMatchNextStep();
                }
            }).catch(function () {
                // Сеть упала посреди прогона — снимаем задание, чтобы освободить замок.
                self._cancelAutoMatch(true);
                Swal.fire({ icon: 'error', title: 'Ошибка сети' });
            });
        },

        /**
         * Останавливает текущее автоквитование (по кнопке или при сбое).
         *
         * @param {boolean} silent Не показывать тост об остановке.
         * @returns {void}
         */
        _cancelAutoMatch: function (silent) {
            var self = this;
            var job = self.autoMatchProgress ? self.autoMatchProgress.job_id : null;
            self.autoMatchRunning = false;
            self.autoMatchProgress = null;
            if (!job) return;
            SmartMatchApi.post(window.AppRoutes.autoMatchCancel, { job_id: job }).then(function () {
                if (!silent) {
                    Swal.fire({ icon: 'info', title: 'Автоквитование остановлено', toast: true,
                        position: 'top-end', timer: 2000, showConfirmButton: false });
                }
                self.loadEntries(true);
            }).catch(function () {});
        },

        /**
         * Останавливает автоквитование по нажатию пользователя.
         *
         * @returns {void}
         */
        cancelAutoMatch: function () { this._cancelAutoMatch(false); },

        /**
         * Загружает правила автоквитования.
         *
         * Вызывает GET `getRules` и заполняет `matchingRules` для таблицы правил.
         *
         * @returns {void}
         */
        loadMatchingRules: function () {
            var self = this;
            self.loadingRules = true;
            // ИСПРАВЛЕНО: было window.window.AppRoutes
            SmartMatchApi.get(window.AppRoutes.getRules, {})
                .then(function (r) {
                    // SmartMatchApi.get возвращает axios response, данные в r.data
                    if (r.data && r.data.success) self.matchingRules = r.data.data;
                })
                .finally(function () { self.loadingRules = false; });
        },

        /**
         * Открывает форму правила из списка правил без наложения Bootstrap modal.
         *
         * Список закрывается перед открытием формы, иначе из-за одинакового
         * z-index форма может оказаться под списком правил.
         *
         * @returns {void}
         */
        openRuleModal: function () {
            var self = this;
            var listEl = document.getElementById('rulesListModal');
            var listIsOpen = listEl && listEl.classList.contains('show');

            self.reopenRulesListAfterRule = !!listIsOpen;

            var showRuleModal = function () {
                var ruleEl = document.getElementById('ruleModal');
                if (ruleEl) {
                    ruleEl.addEventListener('hidden.bs.modal', function () {
                        if (!self.reopenRulesListAfterRule) return;
                        self.reopenRulesListAfterRule = false;
                        self._showModal('rulesListModal');
                    }, { once: true });
                    // Инициализируем Select2 ностро-банка после открытия модалки
                    // (нужен готовый DOM и правильный dropdownParent).
                    ruleEl.addEventListener('shown.bs.modal', function () {
                        self._initRulePoolSelect2();
                    }, { once: true });
                }
                self._showModal('ruleModal');
            };

            if (!listIsOpen) {
                showRuleModal();
                return;
            }

            var listModal = bootstrap.Modal.getInstance(listEl);
            if (!listModal) {
                showRuleModal();
                return;
            }

            listEl.addEventListener('hidden.bs.modal', function () {
                showRuleModal();
            }, { once: true });
            listModal.hide();
        },

        /**
         * Инициализирует Select2 выбора ностро-банка в форме правила.
         *
         * Пустой выбор (clear/placeholder) означает общее правило
         * (`editingRule.pool_id = null`), выбор банка ограничивает правило им.
         * Пересоздаётся при каждом открытии модалки, чтобы подхватить актуальный
         * список `accountPools` и текущее значение `editingRule.pool_id`
         * (в т.ч. авто-подставленный банк при создании из сайдбара).
         *
         * @returns {void}
         */
        _initRulePoolSelect2: function () {
            var self = this;
            if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;
            var $el = $('#rule-pool-select2');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('change');
                $el.select2('destroy');
            }

            var data = (self.accountPools || []).map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                dropdownParent:     $('#ruleModal'),
                theme:              'bootstrap-5',
                placeholder:        'Общее правило — все ностро-банки',
                allowClear:         true,
                minimumInputLength: 0,
                multiple:           true,
                data:               data,
                language: { noResults: function () { return 'Нет ностро-банков'; } }
            });

            $el.on('change', function () {
                var values = $el.val() || [];
                self.editingRule.pool_ids = values.map(function (id) {
                    return parseInt(id, 10);
                }).filter(function (id) {
                    return !!id;
                });
                self.editingRule.pool_id = self.editingRule.pool_ids.length === 1
                    ? self.editingRule.pool_ids[0]
                    : null;
            });

            // Отображаем текущее значение (правило банка / авто-подставленный банк).
            if (self.editingRule.pool_ids && self.editingRule.pool_ids.length) {
                $el.val(self.editingRule.pool_ids.map(function (id) { return String(id); })).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }
        },

        /**
         * Открывает форму создания правила автоквитования.
         *
         * Сбрасывает `editingRule` к дефолтному LS/NRE правилу и показывает
         * `ruleModal`.
         *
         * @returns {void}
         */
        showAddRuleModal: function () {
            // Автоподстановка ностро-банка, если пользователь уже находится в
            // одном банке: сайдбар выверки (selectedPool) или единственный банк
            // в фильтре на /all-nostro.
            var autoPoolIds = [];
            if (this.selectedPool) {
                autoPoolIds = [this.selectedPool.id];
            } else if (this.filters && Array.isArray(this.filters.pool_ids) && this.filters.pool_ids.length === 1) {
                autoPoolIds = [parseInt(this.filters.pool_ids[0], 10)];
            }

            this.editingRule = {
                id: null, name: '', pool_id: autoPoolIds.length === 1 ? autoPoolIds[0] : null, pool_ids: autoPoolIds, section: 'NRE', pair_type: 'LS',
                match_dc: true, match_amount: true, match_value_date: true,
                match_instruction_id: false, match_end_to_end_id: false,
                match_transaction_id: false, match_message_id: false,
                reference_value: '',
                group_match_enabled: false, group_ls_type: null,
                cross_id_search: false, id_prefix_match: false, id_prefix_length: null, is_active: true, priority: 100, description: ''
            };
            this.openRuleModal();
        },

        /**
         * Открывает форму редактирования правила автоквитования.
         *
         * @param {Object} rule Правило из `matchingRules`.
         * @returns {void}
         */
        editRule:       function (rule) {
            this.editingRule = Object.assign({ pool_ids: [] }, rule);
            if (!Array.isArray(this.editingRule.pool_ids)) {
                this.editingRule.pool_ids = this.editingRule.pool_id ? [this.editingRule.pool_id] : [];
            }
            if (this.editingRule.id_prefix_length === undefined) {
                this.editingRule.id_prefix_length = null;
            }
            if (this.editingRule.id_prefix_match === undefined) {
                this.editingRule.id_prefix_match = false;
            }
            if (this.editingRule.reference_value === undefined || this.editingRule.reference_value === null) {
                this.editingRule.reference_value = '';
            }
            if (this.editingRule.group_match_enabled === undefined) {
                this.editingRule.group_match_enabled = false;
            }
            if (this.editingRule.group_ls_type === undefined) {
                this.editingRule.group_ls_type = null;
            }
            this.openRuleModal();
        },
        /**
         * Закрывает модалку правила без сохранения.
         *
         * @returns {void}
         */
        closeRuleModal: function ()     { this._hideModal('ruleModal'); },

        /**
         * Сохраняет правило автоквитования.
         *
         * Валидирует имя, вызывает POST `saveRule`, закрывает модалку и
         * перезагружает список правил. Сервер хранит приоритет, pair_type,
         * набор критериев и флаг `cross_id_search`.
         *
         * @returns {void}
         */
        saveRule: function () {
            var self = this;
            if (!self.editingRule.name) {
                Swal.fire({ icon: 'warning', title: 'Введите название', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            if (self.editingRule.group_match_enabled && !self.editingRule.group_ls_type) {
                Swal.fire({ icon: 'warning', title: 'Укажите тип бух.проводки для группы', toast: true,
                    position: 'top-end', timer: 2500, showConfirmButton: false });
                return;
            }
            // ИСПРАВЛЕНО: было window.window.AppRoutes
            // SmartMatchApi.post возвращает r.data уже распакованным (см. api.js)
            var payload = Object.assign({}, self.editingRule, {
                pool_ids: Array.isArray(self.editingRule.pool_ids) ? self.editingRule.pool_ids : []
            });
            SmartMatchApi.post(window.AppRoutes.saveRule, payload).then(function (r) {
                if (r.success) {
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 2000, showConfirmButton: false });
                    self.closeRuleModal();
                    self.loadMatchingRules();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message || JSON.stringify(r.errors) });
                }
            });
        },

        /**
         * Удаляет правило автоквитования после подтверждения.
         *
         * @param {Object} rule Правило из `matchingRules`.
         * @returns {void}
         */
        deleteRule: function (rule) {
            var self = this;
            Swal.fire({
                title: 'Удалить правило?', text: rule.name, icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#ef4444',
                confirmButtonText: 'Удалить', cancelButtonText: 'Отмена'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                // ИСПРАВЛЕНО: было window.window.AppRoutes
                SmartMatchApi.post(window.AppRoutes.deleteRule, { id: rule.id }).then(function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Удалено', toast: true,
                            position: 'top-end', timer: 1500, showConfirmButton: false });
                        self.loadMatchingRules();
                    } else {
                        Swal.fire({ icon: 'error', title: res.message });
                    }
                });
            });
        }
    }
};
