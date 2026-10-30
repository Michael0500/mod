/**
 * Mixin раздела архива сквитованных записей.
 *
 * Управляет таблицей `nostro_entries_archive`, фильтрами, ручным batch-архивом,
 * очисткой просроченных строк, восстановлением группы по `match_id`, историей,
 * статистикой, настройками хранения и пользовательскими колонками. Сервер
 * сохраняет `matched_at`, проверяет `company_id` и выполняет операции архива
 * транзакционно.
 */
var ArchiveMixin = {
    created: function () {
        StateStorage.remove('archive_filters');
    },

    /**
     * Начальное состояние страницы архива.
     *
     * @returns {Object} Vue data для таблицы, фильтров, статистики, операций и колонок.
     */
    data: function () {
        return {
            // ── Таблица архива ────────────────────────────────────
            archiveRows:            [],
            archiveTotal:           0,
            archivePage:            1,
            archiveLimit:           50,
            archiveLoading:         false,
            archiveLoadingMore:     false,

            archiveSortCol: 'archived_at',
            archiveSortDir: 'desc',

            archiveFilters:     {},
            archiveFiltersOpen: false,

            // Список счетов для фильтра
            archiveAccounts: [],
            archiveAccountPools: [],

            // ── Статистика ────────────────────────────────────────
            archiveStats:        null,
            archiveStatsLoading: false,

            /**
             * Настройки архива текущей компании.
             *
             * @type {{archive_after_days: number, retention_years: number, auto_archive_enabled: boolean}}
             */
            archiveSettings: {
                archive_after_days:   90,
                retention_years:       5,
                auto_archive_enabled:  true,
            },
            archiveSavedSettings: {
                archive_after_days:   90,
                retention_years:       5,
                auto_archive_enabled:  true,
            },
            archiveSettingsOpen:   false,
            archiveSettingsSaving: false,

            // ── Архивирование вручную ─────────────────────────────
            archiveRunning:       false,
            archivePurging:       false,
            archiveProgressOpen:  false,
            archiveProgressDone:  0,
            archiveProgressAll:   0,
            archiveProgressPct:   0,

            // ── История ────────────────────────────────────
            historyLoading: false,
            historyItems:   [],
            historyEntry:   null,

            _archiveDebounceTimer:        null,
            _archivePoolSelect2Inited:    false,
            _archiveAccountSelect2Inited: false,
            _archiveCurrencySelect2Inited: false,
            _archiveSubmitGuardBound:     false,

            /**
             * Конфигурация колонок таблицы архива.
             *
             * `key` соответствует полю archive API/шаблона, пользовательские
             * `visible` и `width` сохраняются в `user_preferences.archive_table_columns`.
             *
             * @type {Array<{key: string, label: string, visible: boolean, width: number}>}
             */
            archiveTableColumns: [
                { key: 'id',             label: 'ID',           visible: false, width: 60  },
                { key: 'account_id',     label: 'Счёт',         visible: true,  width: 140 },
                { key: 'match_id',       label: 'Match ID',     visible: true,  width: 110 },
                { key: 'ls',             label: 'L/S',          visible: true,  width: 55  },
                { key: 'dc',             label: 'D/C',          visible: true,  width: 55  },
                { key: 'amount',         label: 'Сумма',        visible: true,  width: 120 },
                { key: 'currency',       label: 'Вал.',         visible: true,  width: 55  },
                { key: 'value_date',     label: 'Value Date',   visible: true,  width: 105 },
                { key: 'instruction_id', label: 'Instr. ID',    visible: true,  width: 110 },
                { key: 'end_to_end_id',  label: 'E2E ID',       visible: true,  width: 110 },
                { key: 'transaction_id', label: 'Txn ID',       visible: true,  width: 110 },
                { key: 'message_id',     label: 'Msg ID',       visible: true,  width: 110 },
                { key: 'comment',        label: 'Комментарий',  visible: false, width: 140 },
                { key: 'archived_at',    label: 'Архивирован',  visible: true,  width: 115 },
                { key: 'expires_at',     label: 'Хранить до',   visible: true,  width: 115 },
            ],
            showArchiveColsDropdown: false,
            _archiveTableColumnsLoaded: false,
            _archiveColsSaveTimer: null,
        };
    },

    watch: {
        archiveTableColumns: {
            /**
             * Сохраняет пользовательские настройки колонок архива.
             *
             * @returns {void}
             */
            handler: function () { this.saveArchiveTableColumnsPrefs(); },
            deep: true
        }
    },

    computed: {
        /**
         * Проверяет наличие следующей страницы архива.
         *
         * @returns {boolean} `true`, если загружено меньше строк, чем `archiveTotal`.
         */
        hasMoreArchive: function () {
            return this.archiveRows.length < this.archiveTotal;
        },
    },

    methods: {

        /**
         * Блокирует нативную отправку форм внутри страницы архива.
         *
         * Используется как защитный слой для Vue-форм, чтобы Enter или вложенная
         * форма не отправили страницу целиком и не потеряли состояние фильтров.
         *
         * @returns {void}
         */
        bindArchiveSubmitGuard: function () {
            if (this._archiveSubmitGuardBound) return;
            this._archiveSubmitGuardBound = true;

            document.addEventListener('submit', function (e) {
                var root = document.getElementById('archive-app');
                if (!root || !e.target) return;
                if (!root.contains(e.target) && !e.target.contains(root)) return;

                e.preventDefault();
                e.stopPropagation();
            }, true);
        },

        /**
         * Загружает страницу архивных записей.
         *
         * Вызывает GET `archiveList` с пагинацией, сортировкой и JSON-фильтрами.
         * При `reset` очищает текущие строки; `keepFiltersOpen` удерживает
         * панель фильтров открытой после обновления.
         *
         * @param {boolean} reset Нужно ли начать загрузку с первой страницы.
         * @param {boolean=} keepFiltersOpen Сохранять открытой панель фильтров.
         * @returns {void}
         */
        loadArchive: function (reset, keepFiltersOpen) {
            if (keepFiltersOpen) {
                this.archiveFiltersOpen = true;
                this.saveArchiveFilterState();
            }

            if (reset) {
                this.archiveRows  = [];
                this.archivePage  = 1;
            }
            var self    = this;
            var isFirst = this.archivePage === 1;
            if (isFirst) self.archiveLoading     = true;
            else         self.archiveLoadingMore  = true;

            SmartMatchApi.get(AppRoutes.archiveList, {
                page:    self.archivePage,
                limit:   self.archiveLimit,
                sort:    self.archiveSortCol,
                dir:     self.archiveSortDir,
                filters: JSON.stringify(self.archiveFilters),
            }).then(function (r) {
                var d = r.data;
                if (d && d.success) {
                    self.archiveRows  = reset ? d.data : self.archiveRows.concat(d.data);
                    self.archiveTotal = d.total;
                }
            }).finally(function () {
                self.archiveLoading     = false;
                self.archiveLoadingMore = false;
                if (keepFiltersOpen) {
                    self.archiveFiltersOpen = true;
                    self.saveArchiveFilterState();
                }
            });
        },

        /**
         * Догружает следующую страницу архива для infinite scroll.
         *
         * @returns {void}
         */
        loadMoreArchive: function () {
            if (this.archiveLoadingMore || !this.hasMoreArchive) return;
            this.archivePage++;
            this.loadArchive(false);
        },

        /**
         * Обрабатывает прокрутку таблицы архива.
         *
         * @param {Event} e Событие scroll от контейнера таблицы.
         * @returns {void}
         */
        onArchiveScroll: function (e) {
            var el = e.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 160) {
                this.loadMoreArchive();
            }
        },

        /**
         * Меняет сортировку архива и перезагружает данные.
         *
         * @param {string} col Ключ колонки сортировки.
         * @returns {void}
         */
        sortArchive: function (col) {
            if (this.archiveSortCol === col) {
                this.archiveSortDir = this.archiveSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.archiveSortCol = col;
                this.archiveSortDir = 'asc';
            }
            this.loadArchive(true);
        },

        /**
         * Возвращает CSS-класс иконки сортировки архива.
         *
         * @param {string} col Ключ колонки.
         * @returns {string} CSS-класс Font Awesome.
         */
        archiveSortIcon: function (col) {
            if (this.archiveSortCol !== col) return 'fas fa-sort';
            return this.archiveSortDir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        },

        /**
         * Применяет фильтр архива и перезагружает таблицу.
         *
         * Пустое значение удаляет фильтр. Фильтры живут только в текущем
         * экземпляре страницы и сбрасываются при переходе на другой раздел.
         *
         * @param {string} field Имя фильтра, ожидаемое API.
         * @param {*} value Значение фильтра.
         * @returns {void}
         */
        applyArchiveFilter: function (field, value) {
            this.archiveFiltersOpen = true;
            if (Array.isArray(value)) {
                var values = value.map(function (item) {
                    return String(item || '').trim();
                }).filter(function (item) {
                    return item !== '';
                });
                if (values.length) {
                    this.$set(this.archiveFilters, field, values);
                } else {
                    this.$delete(this.archiveFilters, field);
                }
            } else {
                var v = (value === null || value === undefined) ? '' : String(value).trim();
                if (v === '') {
                    this.$delete(this.archiveFilters, field);
                } else {
                    this.$set(this.archiveFilters, field, v);
                }
            }
            this.saveArchiveFilterState();
            this.loadArchive(true, true);
        },

        /**
         * Удаляет один фильтр архива.
         *
         * @param {string} field Имя фильтра.
         * @returns {void}
         */
        clearArchiveFilter: function (field) {
            this.archiveFiltersOpen = true;
            this.$delete(this.archiveFilters, field);
            this.saveArchiveFilterState();
            this.loadArchive(true, true);
        },

        /**
         * Очищает все фильтры архива и связанные Select2.
         *
         * @returns {void}
         */
        clearAllArchiveFilters: function () {
            this.archiveFiltersOpen = true;
            this.archiveFilters = {};
            this.saveArchiveFilterState();
            var $fp = $('#archive-pool-select2');
            if ($fp.length && $fp.data('select2')) $fp.val(null).trigger('change');
            var $fa = $('#archive-account-select2');
            if ($fa.length && $fa.data('select2')) $fa.val(null).trigger('change');
            var $fc = $('#archive-currency-select2');
            if ($fc.length && $fc.data('select2')) $fc.val(null).trigger('change');
            this.loadArchive(true, true);
        },

        /**
         * Применяет фильтр архива с задержкой ввода.
         *
         * @param {string} field Имя фильтра.
         * @param {*} value Значение из поля ввода.
         * @returns {void}
         */
        debouncedArchiveFilter: function (field, value) {
            var self = this;
            clearTimeout(self._archiveDebounceTimer);
            self._archiveDebounceTimer = setTimeout(function () {
                self.applyArchiveFilter(field, value);
            }, 400);
        },

        /**
         * Считает активные фильтры архива.
         *
         * @returns {number} Количество непустых фильтров.
         */
        activeArchiveFilterCount: function () {
            return Object.keys(this.archiveFilters).filter(function (k) {
                return !!this.archiveFilters[k];
            }, this).length;
        },

        /**
         * Удаляет старое сохранённое состояние фильтров архива.
         *
         * Фильтры не должны переживать переходы между страницами, поэтому метод
         * оставлен только как точка очистки legacy-ключа.
         *
         * @returns {void}
         */
        saveArchiveFilterState: function () {
            StateStorage.remove('archive_filters');
        },

        /**
         * Переключает панель фильтров архива.
         *
         * При открытии инициализирует Select2 банков и счетов, при закрытии
         * уничтожает виджеты, чтобы избежать повторных обработчиков.
         *
         * @returns {void}
         */
        toggleArchiveFilters: function () {
            if (this.archiveFiltersOpen) {
                this.destroyArchiveFilterSelect2();
                this.archiveFiltersOpen = false;
                return;
            }

            this.archiveFiltersOpen = !this.archiveFiltersOpen;
            this.saveArchiveFilterState();
            this._archivePoolSelect2Inited = false;
            this._archiveAccountSelect2Inited = false;
            this._archiveCurrencySelect2Inited = false;
            var self = this;
            this.$nextTick(function () {
                self.initArchivePoolSelect2();
                self.initArchiveAccountSelect2();
                self.initArchiveCurrencySelect2();
            });
        },

        /**
         * Уничтожает Select2 виджеты фильтров архива.
         *
         * @returns {void}
         */
        destroyArchiveFilterSelect2: function () {
            var $pool = $('#archive-pool-select2');
            if ($pool.length && $pool.data('select2')) {
                $pool.off('select2:select select2:clear');
                $pool.select2('destroy');
            }

            var $account = $('#archive-account-select2');
            if ($account.length && $account.data('select2')) {
                $account.off('select2:select select2:clear');
                $account.select2('destroy');
            }

            var $currency = $('#archive-currency-select2');
            if ($currency.length && $currency.data('select2')) {
                $currency.off('change.archiveCurrency');
                $currency.select2('destroy');
            }

            this._archivePoolSelect2Inited = false;
            this._archiveAccountSelect2Inited = false;
            this._archiveCurrencySelect2Inited = false;
        },

        /**
         * Инициализирует Select2 фильтра ностро-банка архива.
         *
         * Использует `archiveAccountPools`, синхронизирует выбранный
         * `account_pool_id` и применяет/очищает фильтр при событиях Select2.
         *
         * @returns {void}
         */
        initArchivePoolSelect2: function () {
            var self = this;
            var $el  = $('#archive-pool-select2');
            if (!$el.length || self._archivePoolSelect2Inited) return;
            self._archivePoolSelect2Inited = true;

            var poolData = (self.archiveAccountPools || []).map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                theme:       'bootstrap-5',
                placeholder: 'Все банки...',
                allowClear:  true,
                data:        poolData,
                language: { noResults: function () { return 'Нет ностробанков'; } }
            });
            $el.val(self.archiveFilters.account_pool_id || null).trigger('change.select2');

            $el.on('select2:select', function (e) {
                self.applyArchiveFilter('account_pool_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearArchiveFilter('account_pool_id');
            });
        },

        /**
         * Обновляет options уже созданного Select2 ностро-банков архива.
         *
         * @returns {void}
         */
        refreshArchivePoolSelect2: function () {
            var $el = $('#archive-pool-select2');
            if (!$el.length || !$el.data('select2')) return;
            $el.empty();
            (this.archiveAccountPools || []).forEach(function (p) {
                $el.append(new Option(p.name, String(p.id), false, false));
            });
            $el.val(this.archiveFilters.account_pool_id || null).trigger('change.select2');
        },

        /**
         * Инициализирует Select2 фильтра счёта архива.
         *
         * Использует `archiveAccounts`, применяет фильтр `account_id` и
         * отображает валюту счёта в подписи option.
         *
         * @returns {void}
         */
        initArchiveAccountSelect2: function () {
            var self = this;
            var $el  = $('#archive-account-select2');
            if (!$el.length || self._archiveAccountSelect2Inited) return;
            self._archiveAccountSelect2Inited = true;

            var accData = (self.archiveAccounts || []).map(function (a) {
                return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
            });

            $el.select2({
                theme:       'bootstrap-5',
                placeholder: 'Все счета...',
                allowClear:  true,
                data:        accData,
                language: { noResults: function () { return 'Нет счетов'; } }
            });
            $el.val(self.archiveFilters.account_id || null).trigger('change.select2');

            $el.on('select2:select', function (e) {
                self.applyArchiveFilter('account_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearArchiveFilter('account_id');
            });
        },

        /**
         * Обновляет options уже созданного Select2 счетов архива.
         *
         * @returns {void}
         */
        refreshArchiveAccountSelect2: function () {
            var $el = $('#archive-account-select2');
            if (!$el.length || !$el.data('select2')) return;
            $el.empty();
            (this.archiveAccounts || []).forEach(function (a) {
                var text = a.name + (a.currency ? ' (' + a.currency + ')' : '');
                $el.append(new Option(text, String(a.id), false, false));
            });
            $el.val(this.archiveFilters.account_id || null).trigger('change.select2');
        },

        /**
         * Инициализирует Select2 мультивыбора валют архива.
         *
         * @returns {void}
         */
        initArchiveCurrencySelect2: function () {
            var self = this;
            var $el = $('#archive-currency-select2');
            if (!$el.length || self._archiveCurrencySelect2Inited) return;
            self._archiveCurrencySelect2Inited = true;

            var data = (self.dictCurrencies || []).map(function (c) {
                return { id: c.code, text: c.code };
            });

            $el.select2({
                theme:       'bootstrap-5',
                placeholder: 'Все валюты...',
                allowClear:  true,
                closeOnSelect: false,
                data:        data,
                language: { noResults: function () { return 'Нет валют'; } }
            });

            var current = self.archiveFilters.currency;
            if (current) {
                $el.val(Array.isArray(current) ? current : [current]).trigger('change.select2');
            }

            $el.off('change.archiveCurrency').on('change.archiveCurrency', function () {
                self.applyArchiveFilter('currency', $el.val() || []);
            });
        },

        /**
         * Запрашивает подтверждение запуска ручного архивирования.
         *
         * Показывает пользователю текущий порог `archive_after_days`; после
         * подтверждения запускает batch-процесс `_startProgressArchive()`.
         *
         * @returns {void}
         */
        runArchive: function () {
            var self = this;

            Swal.fire({
                title: 'Запустить архивирование?',
                html:  'Все сквитованные записи старше <strong>' +
                    self.archiveSettings.archive_after_days +
                    ' дней</strong> от даты квитования будут перенесены в архив.',
                icon:  'question',
                showCancelButton: true,
                confirmButtonText: 'Да, архивировать',
                cancelButtonText:  'Отмена',
                confirmButtonColor: '#4f46e5',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                self._startProgressArchive();
            });
        },

        /**
         * Запускает batch-архивирование сквитованных записей.
         *
         * Сначала вызывает POST `archiveCount` для прогресс-бара, затем
         * запускает порционную обработку через `_runNextBatch()`. Сервер
         * переносит подходящие matched строки в архив и пишет аудит.
         *
         * @returns {void}
         */
        _startProgressArchive: function () {
            var self = this;
            self.archiveRunning      = true;
            self.archiveProgressDone = 0;
            self.archiveProgressAll  = 0;
            self.archiveProgressPct  = 0;
            self.archiveProgressOpen = true;

            // Сначала узнаём общее кол-во — для прогресс-бара
            SmartMatchApi.post(AppRoutes.archiveCount, {}).then(function (d) {
                if (!d.success) {
                    self._closeProgressArchive();
                    Swal.fire('Ошибка', d.message || 'Не удалось начать', 'error');
                    return;
                }
                if (d.total === 0) {
                    self._closeProgressArchive();
                    Swal.fire('Готово', 'Нет записей для архивирования', 'info');
                    return;
                }
                self.archiveProgressAll = d.total;
                // Запускаем первую порцию
                self._runNextBatch(0, d.total);
            }).catch(function () {
                self._closeProgressArchive();
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            });
        },

        /**
         * Выполняет следующую порцию архивирования.
         *
         * Вызывает POST `archiveRunBatch` с прогрессом, обновляет UI и повторяет
         * запросы до `is_finished`. После завершения обновляет таблицу и
         * статистику архива.
         *
         * @param {number} totalDone Количество уже обработанных записей.
         * @param {number} totalAll Общее количество записей для обработки.
         * @returns {void}
         */
        _runNextBatch: function (totalDone, totalAll) {
            var self = this;
            SmartMatchApi.post(AppRoutes.archiveRunBatch, {
                total_done: totalDone,
                total_all:  totalAll,
            }).then(function (d) {
                if (!d.success) {
                    self._closeProgressArchive();
                    Swal.fire('Ошибка', d.message, 'error');
                    return;
                }

                self.archiveProgressDone = d.total_done;
                self.archiveProgressPct  = d.percent || 0;

                if (d.is_finished) {
                    self._closeProgressArchive();
                    self.loadArchive(true);
                    self.loadArchiveStats();
                    Swal.fire({
                        title: 'Архивирование завершено!',
                        html:  'Заархивировано записей: <strong>' + d.total_done + '</strong>',
                        icon:  'success',
                        confirmButtonColor: '#4f46e5',
                    });
                } else {
                    // Небольшая пауза чтобы браузер не подвисал
                    setTimeout(function () {
                        self._runNextBatch(d.total_done, d.total_all);
                    }, 50);
                }
            }).catch(function (err) {
                self._closeProgressArchive();
                Swal.fire('Ошибка', 'Ошибка соединения: ' + (err.message || ''), 'error');
            });
        },

        /**
         * Закрывает окно прогресса архивирования и сбрасывает флаг запуска.
         *
         * @returns {void}
         */
        _closeProgressArchive: function () {
            this.archiveRunning      = false;
            this.archiveProgressOpen = false;
        },

        /**
         * Удаляет просроченные архивные записи после подтверждения.
         *
         * Вызывает POST `archivePurgeExpired`; операция необратимо удаляет
         * записи, у которых истёк `expires_at`, затем обновляет таблицу и
         * статистику.
         *
         * @returns {void}
         */
        purgeExpired: function () {
            var self = this;
            Swal.fire({
                title: 'Удалить просроченные?',
                text:  'Архивные записи с истёкшим сроком хранения будут безвозвратно удалены.',
                icon:  'warning',
                showCancelButton: true,
                confirmButtonText: 'Удалить',
                cancelButtonText:  'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                self.archivePurging = true;
                SmartMatchApi.post(AppRoutes.archivePurgeExpired, {}).then(function (d) {
                    if (d.success) {
                        Swal.fire('Готово', d.message, 'success');
                        self.loadArchive(true);
                        self.loadArchiveStats();
                    } else {
                        Swal.fire('Ошибка', d.message, 'error');
                    }
                }).finally(function () {
                    self.archivePurging = false;
                });
            });
        },

        /**
         * Формирует текст одной строки для preview восстановления.
         *
         * @param {Object} row Архивная запись или строка preview.
         * @param {number} index Индекс строки в списке preview.
         * @returns {string} Краткое описание строки восстановления.
         */
        archiveRestoreRowText: function (row, index) {
            var parts = [
                (index + 1) + '. ID=' + (row.original_id || row.id || '—'),
                row.ls || '—',
                row.dc === 'Debit' ? 'D' : (row.dc === 'Credit' ? 'C' : (row.dc || '—')),
                this.formatAmount(row.amount, row.currency) + ' ' + (row.currency || ''),
            ];
            if (row.value_date) parts.push(row.value_date);
            return parts.join(' | ');
        },

        /**
         * Формирует HTML-блок списка строк восстановления.
         *
         * Значения экранируются через `escapeArchiveHtml`, потому что строки
         * могут содержать данные из комментариев или внешних ID.
         *
         * @param {Array<Object>} rows Строки preview восстановления.
         * @returns {string} HTML для SweetAlert.
         */
        archiveRestoreRowsHtml: function (rows) {
            var self = this;
            return '<div style="max-height:220px;overflow:auto;text-align:left;margin-top:10px;' +
                'border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#f9fafb">' +
                rows.map(function (item, idx) {
                    return '<div style="font-family:monospace;font-size:12px;line-height:1.5">' +
                        self.escapeArchiveHtml(self.archiveRestoreRowText(item, idx)) +
                        '</div>';
                }).join('') +
                '</div>';
        },

        /**
         * Экранирует значение для безопасной вставки в HTML SweetAlert.
         *
         * @param {*} value Исходное значение.
         * @returns {string} HTML-экранированная строка.
         */
        escapeArchiveHtml: function (value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        /**
         * Восстанавливает архивную группу записей по `match_id`.
         *
         * Сначала получает preview через GET `archiveRestorePreview`, затем
         * после подтверждения вызывает POST `archiveRestore`. Сервер возвращает
         * все связанные строки в активную таблицу в одной транзакции и сохраняет
         * исходный `matched_at`.
         *
         * @param {Object} row Архивная запись, с которой начато восстановление.
         * @returns {void}
         */
        restoreFromArchive: function (row) {
            var self = this;
            SmartMatchApi.get(AppRoutes.archiveRestorePreview, { id: row.id }).then(function (r) {
                var preview = r.data;
                if (!preview || !preview.success) {
                    Swal.fire('Ошибка', (preview && preview.message) || 'Не удалось получить связанные строки', 'error');
                    return;
                }

                var rows = preview.data || [];
                var matchId = self.escapeArchiveHtml(preview.match_id || row.match_id || '—');
                Swal.fire({
                    title: rows.length === 1 ? 'Восстановить запись?' : 'Восстановить связанные записи?',
                    html:  'В активные записи будут возвращены строки по Match ID: <strong>' + matchId + '</strong>.' +
                        self.archiveRestoreRowsHtml(rows),
                    icon:  'question',
                    showCancelButton: true,
                    confirmButtonText: rows.length === 1 ? 'Восстановить' : 'Восстановить все',
                    cancelButtonText:  'Отмена',
                    confirmButtonColor: '#059669',
                    width: 620,
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    SmartMatchApi.post(AppRoutes.archiveRestore, { id: row.id }).then(function (d) {
                        if (d.success) {
                            var restoredRows = d.data || rows;
                            Swal.fire({
                                title: 'Восстановлено',
                                html: self.escapeArchiveHtml(d.message || 'Записи восстановлены из архива') +
                                    self.archiveRestoreRowsHtml(restoredRows),
                                icon: 'success',
                                confirmButtonColor: '#4f46e5',
                                width: 620,
                            });
                            self.loadArchive(true);
                            self.loadArchiveStats();
                        } else {
                            Swal.fire('Ошибка', d.message, 'error');
                        }
                    }).catch(function () {
                        Swal.fire('Ошибка', 'Ошибка соединения', 'error');
                    });
                });
            }).catch(function () {
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            });
        },

        /**
         * Загружает статистику и текущие настройки архива.
         *
         * Вызывает GET `archiveStats`, заполняет `archiveStats` и синхронизирует
         * `archiveSettings` из ответа.
         *
         * @returns {void}
         */
        loadArchiveStats: function () {
            var self = this;
            self.archiveStatsLoading = true;
            SmartMatchApi.get(AppRoutes.archiveStats, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.archiveStats = r.data.data;
                    self.archiveSavedSettings = self.cloneArchiveSettings(r.data.data.settings);
                    self.archiveSettings = self.cloneArchiveSettings(self.archiveSavedSettings);
                }
            }).finally(function () {
                self.archiveStatsLoading = false;
            });
        },

        /**
         * Загружает настройки архива текущей компании.
         *
         * @returns {void}
         */
        loadArchiveSettings: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.archiveSettings, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.archiveSavedSettings = self.cloneArchiveSettings(r.data.data);
                    self.archiveSettings = self.cloneArchiveSettings(self.archiveSavedSettings);
                }
            });
        },

        /**
         * Возвращает нормализованную копию настроек архива.
         *
         * @param {Object} settings Настройки из API или локального состояния.
         * @returns {{archive_after_days: number, retention_years: number, auto_archive_enabled: boolean}}
         */
        cloneArchiveSettings: function (settings) {
            settings = settings || {};
            var hasAutoArchive = Object.prototype.hasOwnProperty.call(settings, 'auto_archive_enabled');
            return {
                archive_after_days: Number(settings.archive_after_days || 90),
                retention_years: Number(settings.retention_years || 5),
                auto_archive_enabled: hasAutoArchive ? !!settings.auto_archive_enabled : true,
            };
        },

        /**
         * Открывает форму настроек с копией последнего сохранённого состояния.
         *
         * @returns {void}
         */
        openArchiveSettings: function () {
            this.archiveSettings = this.cloneArchiveSettings(this.archiveSavedSettings);
            this.archiveSettingsOpen = true;
        },

        /**
         * Закрывает форму настроек и сбрасывает несохранённые изменения.
         *
         * @returns {void}
         */
        closeArchiveSettings: function () {
            this.archiveSettings = this.cloneArchiveSettings(this.archiveSavedSettings);
            this.archiveSettingsOpen = false;
        },

        /**
         * Сохраняет настройки архива.
         *
         * Вызывает POST `archiveSaveSettings` с `archive_after_days`,
         * `retention_years` и `auto_archive_enabled`, закрывает панель настроек
         * и обновляет статистику.
         *
         * @returns {void}
         */
        saveArchiveSettings: function () {
            var self = this;
            self.archiveSettingsSaving = true;
            SmartMatchApi.post(AppRoutes.archiveSaveSettings, self.archiveSettings).then(function (d) {
                if (d.success) {
                    self.archiveSavedSettings = self.cloneArchiveSettings(d.data || self.archiveSettings);
                    self.archiveSettings = self.cloneArchiveSettings(self.archiveSavedSettings);
                    Swal.fire({ title: 'Сохранено', icon: 'success', timer: 1400, showConfirmButton: false });
                    self.archiveSettingsOpen = false;
                    self.loadArchiveStats();
                } else {
                    Swal.fire('Ошибка', d.message, 'error');
                }
            }).finally(function () {
                self.archiveSettingsSaving = false;
            });
        },

        /**
         * Загружает счета для фильтра архива.
         *
         * Вызывает GET `archiveAccounts`; если панель фильтров открыта,
         * обновляет или инициализирует Select2 счетов.
         *
         * @returns {void}
         */
        loadArchiveAccounts: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.archiveAccounts, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.archiveAccounts = r.data.data;
                    if (self.archiveFiltersOpen) {
                        setTimeout(function () {
                            if (self._archiveAccountSelect2Inited) {
                                self.refreshArchiveAccountSelect2();
                            } else {
                                self.initArchiveAccountSelect2();
                            }
                        }, 0);
                    }
                }
            });
        },

        /**
         * Загружает ностро-банки для фильтра архива.
         *
         * Вызывает GET `accountPoolList`; при открытых фильтрах обновляет или
         * инициализирует Select2 банков.
         *
         * @returns {void}
         */
        loadArchiveAccountPools: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.accountPoolList, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.archiveAccountPools = r.data.data || [];
                    if (self.archiveFiltersOpen) {
                        setTimeout(function () {
                            if (self._archivePoolSelect2Inited) {
                                self.refreshArchivePoolSelect2();
                            } else {
                                self.initArchivePoolSelect2();
                            }
                        }, 0);
                    }
                }
            });
        },

        /**
         * Переключает общий интерфейс на раздел архива.
         *
         * Используется в старых/общих шаблонах: выставляет `activeSection` и
         * лениво загружает строки и статистику, если они ещё не были получены.
         *
         * @returns {void}
         */
        switchToArchive: function () {
            this.activeSection = 'archive';
            if (!this.archiveRows.length && !this.archiveLoading) {
                this.loadArchive(true);
            }
            if (!this.archiveStats) {
                this.loadArchiveStats();
            }
        },

        /**
         * Открывает модалку истории архивной записи.
         *
         * Вызывает GET `archiveHistory`, где сервер ищет аудит по
         * `nostro_entries_archive.original_id` и учитывает restore/archive
         * переходы между физическими строками.
         *
         * @param {Object} row Архивная запись из таблицы.
         * @returns {void}
         */
        showArchiveHistory: function (row) {
            var self = this;
            // Создаём объект entry для отображения в заголовке
            self.historyEntry = {
                id: row.original_id,
                ls: row.ls,
                dc: row.dc,
                amount: row.amount,
                currency: row.currency,
            };
            self.historyItems = [];
            self.historyLoading = true;
            self._showModal('entryHistoryModal');

            SmartMatchApi.get(AppRoutes.archiveHistory, { id: row.id })
                .then(function (r) {
                    let response = r.data
                    if (response.success) {
                        self.historyItems = response.data || [];
                    } else {
                        self.historyItems = [];
                    }
                })
                .catch(function () {
                    self.historyItems = [];
                })
                .finally(function () {
                    self.historyLoading = false;
                });
        },

        /**
         * Проверяет видимость колонки архива.
         *
         * @param {string} key Ключ колонки.
         * @returns {boolean} `true`, если колонка видима.
         */
        archiveColVisible: function (key) {
            var col = this.archiveTableColumns.find(function (c) { return c.key === key; });
            return col ? col.visible : true;
        },
        /**
         * Возвращает описание колонки архива по ключу.
         *
         * @param {string} key Ключ колонки.
         * @returns {Object|undefined} Объект колонки или `undefined`.
         */
        archiveColByKey: function (key) {
            return this.archiveTableColumns.find(function (c) { return c.key === key; });
        },
        /**
         * Переключает dropdown управления колонками архива.
         *
         * @returns {void}
         */
        toggleArchiveColsDropdown: function () {
            this.showArchiveColsDropdown = !this.showArchiveColsDropdown;
        },
        /**
         * Запускает изменение ширины колонки архива.
         *
         * @param {MouseEvent} e Событие mousedown на ресайзере.
         * @param {Object} col Колонка из `archiveTableColumns`.
         * @returns {void}
         */
        startArchiveColResize: function (e, col) {
            e.preventDefault();
            e.stopPropagation();
            var startX = e.clientX;
            var startW = col.width || 100;

            var onMove = function (ev) {
                ev.preventDefault();
                col.width = Math.max(50, startW + (ev.clientX - startX));
            };
            var onUp = function () {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.style.userSelect = '';
                document.body.style.cursor     = '';
                document.body.classList.remove('resizing-col');
            };
            document.body.style.userSelect = 'none';
            document.body.style.cursor     = 'col-resize';
            document.body.classList.add('resizing-col');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
        /**
         * Загружает персональные настройки колонок архива.
         *
         * Вызывает GET `userPreferenceGet` с ключом `archive_table_columns` и
         * применяет сохранённые `visible`/`width` к известным колонкам.
         *
         * @returns {void}
         */
        loadArchiveTableColumnsPrefs: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.userPreferenceGet, { key: 'archive_table_columns' })
                .then(function (response) {
                    var r = response.data !== undefined ? response.data : response;
                    if (r && r.success && Array.isArray(r.value)) {
                        var saved = {};
                        r.value.forEach(function (c) {
                            if (c && typeof c.key === 'string') saved[c.key] = c;
                        });
                        self.archiveTableColumns.forEach(function (col) {
                            var s = saved[col.key];
                            if (!s) return;
                            if (typeof s.visible === 'boolean') col.visible = s.visible;
                            if (typeof s.width === 'number' && s.width >= 40) col.width = s.width;
                        });
                    }
                })
                .catch(function () { /* no-op */ })
                .then(function () {
                    self.$nextTick(function () { self._archiveTableColumnsLoaded = true; });
                });
        },
        /**
         * Сохраняет персональные настройки колонок архива.
         *
         * После debounce вызывает POST `userPreferenceSave` с ключом
         * `archive_table_columns`.
         *
         * @returns {void}
         */
        saveArchiveTableColumnsPrefs: function () {
            if (!this._archiveTableColumnsLoaded) return;
            var self = this;
            if (self._archiveColsSaveTimer) clearTimeout(self._archiveColsSaveTimer);
            self._archiveColsSaveTimer = setTimeout(function () {
                var payload = self.archiveTableColumns.map(function (c) {
                    return { key: c.key, visible: !!c.visible, width: c.width };
                });
                SmartMatchApi.post(AppRoutes.userPreferenceSave, {
                    key: 'archive_table_columns',
                    value: payload
                });
            }, 600);
        },
        /**
         * Подключает глобальные обработчики закрытия dropdown колонок архива.
         *
         * @returns {void}
         */
        _initArchiveColManagement: function () {
            var self = this;
            document.addEventListener('click', function (e) {
                if (!self.showArchiveColsDropdown) return;
                if (e.target.closest && (e.target.closest('.col-mgr-dropdown') || e.target.closest('[data-archive-col-toggle]'))) return;
                self.showArchiveColsDropdown = false;
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') self.showArchiveColsDropdown = false;
            });
        }
    },
};
