/**
 * Mixin таблицы активных записей NostroEntry.
 *
 * Обслуживает основную таблицу выверки: загрузку записей выбранного
 * ностро-банка, сортировку, фильтры, Select2, CRUD, inline-комментарии,
 * историю аудита, детальную панель и пользовательские настройки колонок.
 * Сервер применяет `company_id`, проверку прав, денежную точность и статусы
 * квитования; клиент хранит только состояние UI и отправляет параметры.
 */
var EntriesMixin = {
    /**
     * Начальное состояние таблицы выверки и связанных модалок.
     *
     * @returns {Object} Vue data для записей, фильтров, форм, истории и колонок.
     */
    data: function () {
        return {
            // ── Таблица ───────────────────────────────────
            entries:            [],
            entriesTotal:       0,
            entriesPage:        1,
            entriesLimit:       50,
            entriesLoading:     false,
            entriesLoadingMore: false,

            // Составная сортировка: порядок элементов равен приоритету сортировки.
            sorts: [
                { field: 'value_date', dir: 'asc' },
                { field: 'amount', dir: 'asc' }
            ],

            // Фильтры
            filters:      {},
            filtersOpen:  false,

            // Select2 флаги
            _filterSelect2Inited:     false,
            _filterPoolSelect2Inited: false,
            _entrySelect2Inited:      false,
            _entryPoolSelect2Inited:  false,

            /**
             * Форма создания/редактирования NostroEntry.
             *
             * @type {Object}
             * @property {?number} id ID записи; `null` означает создание.
             * @property {?number} account_id ID счёта текущей компании.
             * @property {string} ls Сторона записи: `L` Ledger или `S` Statement.
             * @property {string} dc Направление суммы: `Debit` или `Credit`.
             * @property {string} amount Сумма в формате decimal-строки.
             * @property {string} currency Код валюты.
             * @property {?number} pool_id ID ностро-банка (заполняется только когда в форме есть селект банка — `/all-nostro`).
             * @property {string} pool_name Имя выбранного банка для display в Select2.
             */
            editingEntry: {
                id: null, account_id: null, account_name: '',
                pool_id: null, pool_name: '',
                ls: 'L', dc: 'Debit', amount: '', currency: '',
                value_date: '', post_date: '',
                instruction_id: '', end_to_end_id: '',
                transaction_id: '', message_id: '', comment: ''
            },
            entrySaving: false,

            // ── Выделение (selectedIds / selectionSummary / summaryBalanced
            //    объявлены в MatchingMixin) ─────────────────

            // ── inline-комментарий ────────────────────────
            editingCommentId:    null,
            editingCommentValue: '',

            // Ностро банки (для фильтра)
            accountPools: [],

            // debounce timer
            _filterDebounceTimer: null,

            // ── История ────────────────────────────────────
            historyLoading: false,
            historyItems:   [],
            historyEntry:   null,

            /**
             * Конфигурация колонок таблицы выверки.
             *
             * `key` должен совпадать с полем API/шаблона; `visible` и `width`
             * сохраняются в `user_preferences.entries_table_columns`.
             *
             * @type {Array<{key: string, label: string, visible: boolean, width: number}>}
             */
            tableColumns: [
                { key: 'id',             label: 'ID',             visible: false, width: 60  },
                { key: 'account_id',     label: 'Счёт',           visible: false, width: 120 },
                { key: 'match_id',       label: 'Match ID',       visible: true, width: 100 },
                { key: 'ls',             label: 'L/S',            visible: true, width: 55  },
                { key: 'dc',             label: 'D/C',            visible: true, width: 55  },
                { key: 'amount',         label: 'Сумма',          visible: true, width: 110 },
                { key: 'currency',       label: 'Вал.',           visible: true, width: 55  },
                { key: 'value_date',     label: 'Value Date',     visible: true, width: 100 },
                { key: 'post_date',      label: 'Post Date',      visible: true, width: 100 },
                { key: 'instruction_id', label: 'Instr.ID',       visible: true, width: 100 },
                { key: 'end_to_end_id',  label: 'E2E ID',         visible: true, width: 95  },
                { key: 'transaction_id', label: 'Txn ID',         visible: true, width: 95  },
                { key: 'message_id',     label: 'Msg ID',         visible: true, width: 95  },
                { key: 'comment',        label: 'Комментарий',    visible: true, width: 130 },
                { key: 'match_status',   label: 'Статус',         visible: false, width: 95  },
            ],
            showColsDropdown: false,
            detailEntry:      null,
            // ── Ширины колонок ────────────────────────────
            colWidths: {},

            // Флаг: настройки колонок загружены с сервера (чтобы не сохранять до загрузки)
            _tableColumnsLoaded: false,
            _colsSaveTimer: null,
        };
    },

    watch: {
        tableColumns: {
            /**
             * Сохраняет пользовательские настройки колонок после изменения.
             *
             * @returns {void}
             */
            handler: function () { this.saveTableColumnsPrefs(); },
            deep: true
        }
    },

    computed: {
        /**
         * Проверяет наличие следующей страницы записей.
         *
         * @returns {boolean} `true`, если загружено меньше строк, чем `entriesTotal`.
         */
        hasMoreEntries: function () {
            return this.entries.length < this.entriesTotal;
        },
        /**
         * Возвращает несквитованные записи текущей страницы.
         *
         * @returns {Array<Object>} Записи со статусом `match_status = U`.
         */
        unmatchedEntries: function () {
            return this.entries.filter(function (e) { return e.match_status === 'U'; });
        },
        /**
         * Возвращает ID несквитованных записей текущей страницы.
         *
         * @returns {Array<number|string>} ID записей, доступных для массового выбора.
         */
        unmatchedIds: function () {
            return this.unmatchedEntries.map(function (e) { return e.id; });
        },
        /**
         * Проверяет, выбраны ли все несквитованные записи текущей страницы.
         *
         * @returns {boolean} `true`, если каждый ID из `unmatchedIds` выбран.
         */
        allUnmatchedSelected: function () {
            var uids = this.unmatchedIds;
            if (!uids.length) return false;
            var sel  = this.selectedIds;
            return uids.every(function (id) { return sel.indexOf(id) !== -1; });
        },
        /**
         * Проверяет частичный выбор для tri-state checkbox.
         *
         * @returns {boolean} `true`, если выбрана часть записей.
         */
        someSelected: function () {
            return this.selectedIds.length > 0 && !this.allUnmatchedSelected;
        },
        /**
         * Проверяет минимальное количество записей для ручного квитования.
         *
         * @returns {boolean} `true`, если выбрано минимум две записи.
         */
        hasSelection: function () {
            return this.selectedIds.length >= 2;
        }
    },

    methods: {
        /**
         * Подбирает русскую форму слова "запись" для счётчиков таблицы.
         *
         * @param {number} count Количество записей.
         * @returns {string} Подходящая форма: `запись`, `записи` или `записей`.
         */
        recordText: function (count) {
            var n = Math.abs(count) % 100;
            var n1 = n % 10;

            if (n > 10 && n < 20) return 'записей';
            if (n1 > 1 && n1 < 5) return 'записи';
            if (n1 === 1) return 'запись';
            return 'записей';
        },
        /**
         * Загружает список ностро-банков для фильтров таблицы.
         *
         * Вызывает GET `accountPoolList` и заполняет `accountPools` короткими
         * объектами `{id, name}`. Ошибки API не блокируют основную таблицу.
         *
         * @returns {void}
         */
        loadAccountPools: function () {
            var self = this;
            SmartMatchApi.get(window.AppRoutes.accountPoolList).then(function (r) {
                var data = r.data !== undefined ? r.data : r;
                if (data.success && Array.isArray(data.data)) {
                    self.accountPools = data.data.map(function (p) {
                        return { id: p.id, name: p.name };
                    });
                }
            });
        },

        /**
         * Загружает страницу активных записей выбранного ностро-банка.
         *
         * Читает `selectedPool`, пагинацию, сортировку и `filters`, вызывает GET
         * `entryList`, обновляет `entries` и `entriesTotal`. При `reset` очищает
         * выбор строк и summary квитования.
         *
         * @param {boolean} reset Нужно ли начать загрузку с первой страницы.
         * @returns {void}
         */
        loadEntries: function (reset) {
            if (!this.selectedPool) return;
            if (reset) {
                this.entries          = [];
                this.entriesPage      = 1;
                this.selectedIds      = [];
                this.selectionSummary = null;
            }
            var self    = this;
            var isFirst = this.entriesPage === 1;
            if (isFirst) self.entriesLoading     = true;
            else         self.entriesLoadingMore = true;

            SmartMatchApi.get(window.AppRoutes.entryList, {
                pool_id: self.selectedPool.id,
                page:    self.entriesPage,
                limit:   self.entriesLimit,
                sorts:   JSON.stringify(self.sorts),
                filters: JSON.stringify(self.filters)
            }).then(function (response) {
                var r = response.data !== undefined ? response.data : response;
                if (r.success) {
                    self.entries      = reset ? r.data : self.entries.concat(r.data);
                    self.entriesTotal = r.total;
                }
            }).finally(function () {
                self.entriesLoading     = false;
                self.entriesLoadingMore = false;
            });
        },

        /**
         * Догружает следующую страницу записей для infinite scroll.
         *
         * @returns {void}
         */
        loadMoreEntries: function () {
            if (this.entriesLoadingMore || !this.hasMoreEntries) return;
            this.entriesPage++;
            this.loadEntries(false);
        },

        /**
         * Меняет сортировку таблицы и перезагружает записи.
         *
         * Новая колонка добавляется в конец составной сортировки, повторный клик
         * по уже выбранной колонке переключает её направление.
         *
         * @param {string} col Ключ колонки API-сортировки.
         * @returns {void}
         */
        sortBy: function (col) {
            var index = this.sorts.findIndex(function (item) { return item.field === col; });
            if (index !== -1) {
                this.sorts[index].dir = this.sorts[index].dir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sorts.push({ field: col, dir: 'asc' });
            }
            this.loadEntries(true);
        },

        /**
         * Возвращает сортировку к стандартной: Value Date, затем сумма, обе по возрастанию.
         *
         * @returns {void}
         */
        resetSorting: function () {
            this.sorts = [
                { field: 'value_date', dir: 'asc' },
                { field: 'amount', dir: 'asc' }
            ];
            this.loadEntries(true);
        },

        /**
         * Возвращает CSS-класс иконки сортировки для колонки.
         *
         * @param {string} col Ключ колонки.
         * @returns {string} CSS-класс Font Awesome.
         */
        sortIcon: function (col) {
            var item = this.sorts.find(function (sort) { return sort.field === col; });
            if (!item) return 'fas fa-sort';
            return item.dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        },

        /**
         * Возвращает приоритет колонки в составной сортировке (начиная с 1).
         *
         * @param {string} col Ключ колонки.
         * @returns {number|null} Приоритет или `null`, если колонка не выбрана.
         */
        sortPriority: function (col) {
            var index = this.sorts.findIndex(function (item) { return item.field === col; });
            return index === -1 ? null : index + 1;
        },

        /**
         * Применяет фильтр таблицы и перезагружает записи.
         *
         * Пустое значение удаляет фильтр. Итоговый набор фильтров сериализуется
         * в JSON и отправляется в GET `entryList`.
         *
         * @param {string} field Имя фильтра, ожидаемое сервером.
         * @param {*} value Значение фильтра из input или Select2.
         * @returns {void}
         */
        applyFilter: function (field, value) {
            if (Array.isArray(value)) {
                if (value.length === 0) {
                    this.$delete(this.filters, field);
                } else {
                    this.$set(this.filters, field, value);
                }
            } else {
                var v = (value === null || value === undefined) ? '' : String(value).trim();
                if (v === '') {
                    this.$delete(this.filters, field);
                } else {
                    this.$set(this.filters, field, v);
                    if (field === 'amount_min') {
                        var amountMax = this.filters.amount_max;
                        if (amountMax === null || amountMax === undefined || String(amountMax).trim() === '') {
                            this.$set(this.filters, 'amount_max', v);
                        }
                    }
                }
            }
            this.loadEntries(true);
        },

        /**
         * Применяет текстовый фильтр с задержкой ввода.
         *
         * @param {string} field Имя фильтра.
         * @param {*} value Значение из поля ввода.
         * @returns {void}
         */
        debouncedFilter: function (field, value) {
            var self = this;
            if (self._filterDebounceTimer) clearTimeout(self._filterDebounceTimer);
            self._filterDebounceTimer = setTimeout(function () {
                self.applyFilter(field, value);
            }, 400);
        },

        /**
         * Удаляет один фильтр таблицы и перезагружает записи.
         *
         * @param {string} field Имя фильтра.
         * @returns {void}
         */
        clearFilter: function (field) {
            this.$delete(this.filters, field);
            this.loadEntries(true);
        },

        /**
         * Очищает все фильтры таблицы и связанные Select2.
         *
         * Побочные эффекты: сбрасывает `filters`, значения `#filter-account-select2`
         * и `#filter-pool-select2`, затем загружает первую страницу.
         *
         * @returns {void}
         */
        clearAllFilters: function () {
            this.filters = {};
            var $fs = $('#filter-account-select2');
            if ($fs.length && $fs.data('select2')) $fs.val(null).trigger('change');
            var $fp = $('#filter-pool-select2');
            if ($fp.length && $fp.data('select2')) $fp.val(null).trigger('change');
            var $fc = $('#' + (this.currencyFilterSelectId || 'filter-currency-select2'));
            if ($fc.length && $fc.data('select2')) $fc.val(null).trigger('change');
            this.loadEntries(true);
        },

        /**
         * Проверяет, активен ли конкретный фильтр.
         *
         * @param {string} field Имя фильтра.
         * @returns {boolean} `true`, если фильтр заполнен.
         */
        hasFilter: function (field) {
            return this.filters[field] !== undefined && this.filters[field] !== '';
        },

        /**
         * Считает количество активных фильтров таблицы.
         *
         * @returns {number} Количество непустых фильтров.
         */
        activeFilterCount: function () {
            var self = this, cnt = 0;
            Object.keys(self.filters).forEach(function (k) {
                if (self.filters[k] !== undefined && self.filters[k] !== '') cnt++;
            });
            return cnt;
        },

        /**
         * Переключает панель фильтров и инициализирует Select2 после открытия.
         *
         * @returns {void}
         */
        toggleFiltersPanel: function () {
            var self = this;
            self.filtersOpen = !self.filtersOpen;
            if (self.filtersOpen) {
                setTimeout(function () {
                    self.initFilterAccountSelect2();
                    self.initFilterPoolSelect2();
                    self.initFilterCurrencySelect2();
                }, 120);
            }
        },

        /**
         * Инициализирует Select2 мультивыбора валют для фильтра выверки.
         *
         * Берёт значения из глобального `dictCurrencies` и сохраняет выбранные
         * коды в `filters.currency` как массив. Если выбрано ничего — фильтр
         * удаляется.
         *
         * @returns {void}
         */
        initFilterCurrencySelect2: function () {
            var self  = this;
            var elId  = self.currencyFilterSelectId || 'filter-currency-select2';
            var $el   = $('#' + elId);
            if (!$el.length || self._filterCurrencySelect2Inited) return;
            self._filterCurrencySelect2Inited = true;

            var data = (self.dictCurrencies || []).map(function (c) {
                return { id: c.code, text: c.code };
            });

            $el.select2({
                theme:       'bootstrap-5',
                placeholder: 'Все валюты...',
                allowClear:  true,
                data:        data,
                language: { noResults: function () { return 'Нет валют'; } }
            });

            // Если фильтр уже содержит валюты — отобразить их
            var current = self.filters.currency;
            if (current) {
                $el.val(Array.isArray(current) ? current : [current]).trigger('change.select2');
            }

            $el.on('change', function () {
                var vals = $el.val() || [];
                self.applyFilter('currency', vals);
            });
        },

        /**
         * Инициализирует Select2 фильтра счёта.
         *
         * Виджет ищет счета через GET `entrySearchAccounts` с текущим
         * `pool_id`, а выбор/очистка синхронизируются с фильтром `account_id`.
         *
         * @returns {void}
         */
        initFilterAccountSelect2: function () {
            var self = this;
            var $el  = $('#filter-account-select2');
            if (!$el.length || self._filterSelect2Inited) return;
            self._filterSelect2Inited = true;

            $el.select2({
                theme:              'bootstrap-5',
                placeholder:        'Все счета...',
                allowClear:         true,
                minimumInputLength: 0,
                ajax: {
                    url: function () {
                        return window.AppRoutes.entrySearchAccounts +
                            '?pool_id=' + (self.selectedPool ? self.selectedPool.id : 0);
                    },
                    dataType: 'json', delay: 200,
                    data:     function (p) { return { q: p.term || '' }; },
                    processResults: function (d) { return d; },
                    cache: true
                },
                templateResult: function (item) {
                    if (item.loading) return item.text;
                    var tag = item.currency
                        ? '<span style="background:#e0e7ff;color:#4338ca;border-radius:4px;' +
                        'padding:1px 6px;font-size:10px;font-weight:700;margin-left:5px">' +
                        item.currency + '</span>'
                        : '';
                    return $('<span>' + item.text + tag + '</span>');
                }
            });

            $el.on('select2:select', function (e) {
                self.applyFilter('account_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearFilter('account_id');
            });
        },

        /**
         * Инициализирует Select2 фильтра ностро-банка.
         *
         * Использует локально загруженный `accountPools`; выбор записывает
         * `account_pool_id` в фильтры, очистка удаляет фильтр.
         *
         * @returns {void}
         */
        initFilterPoolSelect2: function () {
            var self = this;
            var $el  = $('#filter-pool-select2');
            if (!$el.length || self._filterPoolSelect2Inited) return;
            self._filterPoolSelect2Inited = true;

            var poolData = self.accountPools.map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                theme:            'bootstrap-5',
                placeholder:      'Все банки...',
                allowClear:       true,
                data:             poolData,
                language: {
                    noResults: function () { return 'Нет ностробанков'; }
                }
            });

            // Если уже есть авто-выбранный пул — отображаем его, иначе явно сбрасываем
            if (self.filters.account_pool_id) {
                $el.val(String(self.filters.account_pool_id)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.on('select2:select', function (e) {
                self.applyFilter('account_pool_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearFilter('account_pool_id');
            });
        },

        /**
         * Программно обновляет выбранное значение фильтра ностро-банка.
         *
         * Не пересоздаёт Select2 и не вызывает бизнес-логику загрузки записей;
         * только синхронизирует визуальное состояние виджета.
         *
         * @param {number|string|null} poolId ID ностро-банка или пустое значение.
         * @returns {void}
         */
        updateFilterPoolSelect2: function (poolId) {
            var $el = $('#filter-pool-select2');
            if (!$el.length || !$el.data('select2')) return;
            if (poolId) {
                $el.val(String(poolId)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }
        },

        /**
         * Инициализирует Select2 выбора счёта в форме записи.
         *
         * Ищет счета через GET `entrySearchAccounts` с текущим `pool_id`,
         * записывает выбранный `account_id` и `account_name` в `editingEntry`,
         * а валюту подставляет из счёта, если поле ещё пустое.
         *
         * @returns {void}
         */
        initEntryAccountSelect2: function () {
            var self = this;
            var $el  = $('#entry-account-select2');
            if (!$el.length || self._entrySelect2Inited) return;
            self._entrySelect2Inited = true;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
            }

            $el.select2({
                dropdownParent:     $('#entryModal'),
                theme:              'bootstrap-5',
                placeholder:        'Начните вводить название счёта...',
                allowClear:         true,
                minimumInputLength: 0,
                ajax: {
                    url: function () {
                        // На /all-nostro pool_id берётся из editingEntry.pool_id (выбран в модалке),
                        // на /site/index — из selectedPool (выбран в сайдбаре).
                        var poolId = self.editingEntry && self.editingEntry.pool_id
                            ? self.editingEntry.pool_id
                            : (self.selectedPool ? self.selectedPool.id : 0);
                        return window.AppRoutes.entrySearchAccounts + '?pool_id=' + poolId;
                    },
                    dataType: 'json', delay: 200,
                    data:     function (p) { return { q: p.term || '' }; },
                    processResults: function (d) { return d; },
                    cache: false
                },
                templateResult: function (item) {
                    if (item.loading) return item.text;
                    var badges = '';
                    if (item.currency) {
                        badges += '<span style="background:#e0e7ff;color:#4338ca;border-radius:4px;' +
                            'padding:1px 6px;font-size:10px;font-weight:700;margin-left:6px">' +
                            item.currency + '</span>';
                    }
                    if (item.is_suspense) {
                        badges += '<span style="background:#fde68a;color:#92400e;border-radius:4px;' +
                            'padding:1px 6px;font-size:10px;font-weight:700;margin-left:3px">Suspense</span>';
                    }
                    return $('<span style="display:flex;align-items:center">' + item.text + badges + '</span>');
                },
                templateSelection: function (item) { return item.text || item.id; }
            });

            $el.on('select2:select', function (e) {
                self.editingEntry.account_id   = e.params.data.id;
                self.editingEntry.account_name = e.params.data.text;
                if (e.params.data.currency && !self.editingEntry.currency) {
                    self.editingEntry.currency = e.params.data.currency;
                }
            });
            $el.on('select2:clear', function () {
                self.editingEntry.account_id   = null;
                self.editingEntry.account_name = '';
            });

            // Сбрасываем выбранное значение после инициализации
            if (!self.editingEntry.id) {
                $el.val(null).trigger('change');
            }
        },

        /**
         * Инициализирует Select2 выбора ностро-банка в форме записи.
         *
         * Виджет существует только на странице `/all-nostro` (там есть DOM-узел
         * `#entry-pool-select2`). На странице выверки банк уже задан через
         * `selectedPool` в сайдбаре, поэтому метод просто выходит, если
         * виджета нет.
         *
         * Источник данных — массив `accountPools`, который страница загружает
         * на старте (см. `loadAccountPools`). После выбора банка пересоздаётся
         * Select2 счёта, чтобы поиск шёл по новому `pool_id`.
         *
         * @returns {void}
         */
        initEntryPoolSelect2: function () {
            var self = this;
            var $el = $('#entry-pool-select2');
            if (!$el.length || self._entryPoolSelect2Inited) return;
            self._entryPoolSelect2Inited = true;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
            }

            var data = (self.accountPools || []).map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                dropdownParent:     $('#entryModal'),
                theme:              'bootstrap-5',
                placeholder:        'Выберите ностро-банк...',
                allowClear:         true,
                minimumInputLength: 0,
                data:               data,
                language: { noResults: function () { return 'Нет ностро-банков'; } }
            });

            $el.on('select2:select', function (e) {
                self.editingEntry.pool_id   = parseInt(e.params.data.id, 10);
                self.editingEntry.pool_name = e.params.data.text;
                // Счёт мог быть выбран от другого банка — сбрасываем
                self.editingEntry.account_id   = null;
                self.editingEntry.account_name = '';
                var $acc = $('#entry-account-select2');
                if ($acc.length && $acc.data('select2')) $acc.val(null).trigger('change');
                // Пересоздаём Select2 счёта, чтобы AJAX подхватил новый pool_id
                self._entrySelect2Inited = false;
                self.initEntryAccountSelect2();
            });
            $el.on('select2:clear', function () {
                self.editingEntry.pool_id   = null;
                self.editingEntry.pool_name = '';
                self.editingEntry.account_id   = null;
                self.editingEntry.account_name = '';
                var $acc = $('#entry-account-select2');
                if ($acc.length && $acc.data('select2')) $acc.val(null).trigger('change');
            });

            if (!self.editingEntry.id && !self.editingEntry.pool_id) {
                $el.val(null).trigger('change');
            }
        },

        /**
         * Открывает модалку создания записи выверки.
         *
         * Сбрасывает `editingEntry`, Select2 счёта и привязывает обработчик
         * статического backdrop, чтобы пользователь подтверждал отмену.
         *
         * @returns {void}
         */
        showAddEntryModal: function () {
            var self = this;
            self._entrySelect2Inited = false;
            self._entryPoolSelect2Inited = false;
            self.entrySaving = false;
            self.editingEntry = {
                id: null, account_id: null, account_name: '',
                pool_id: null, pool_name: '',
                ls: 'L', dc: 'Debit', amount: '', currency: '',
                value_date: '', post_date: '',
                instruction_id: '', end_to_end_id: '',
                transaction_id: '', message_id: '', comment: ''
            };
            var $pool = $('#entry-pool-select2');
            if ($pool.length && $pool.data('select2')) $pool.val(null).trigger('change');
            var $el = $('#entry-account-select2');
            if ($el.length && $el.data('select2')) $el.val(null).trigger('change');
            self._bindEntryModalHidePrevented();
            self._showModal('entryModal');
            setTimeout(function () {
                self.initEntryPoolSelect2();
                self.initEntryAccountSelect2();
            }, 300);
        },

        /**
         * Открывает модалку редактирования записи выверки.
         *
         * Копирует запись в `editingEntry`, инициализирует Select2 и добавляет
         * текущий счёт как выбранную option, чтобы форма отобразила значение.
         *
         * @param {Object} entry Запись NostroEntry из таблицы.
         * @returns {void}
         */
        editEntry: function (entry) {
            var self = this;
            self._entrySelect2Inited = false;
            self._entryPoolSelect2Inited = false;
            self.entrySaving = false;
            self.editingEntry = JSON.parse(JSON.stringify(entry));
            // pool_id для редактирования: берём из entry.account_pool_id / entry.pool_id, если есть
            if (!self.editingEntry.pool_id && entry.pool_id) {
                self.editingEntry.pool_id = entry.pool_id;
            }
            if (!self.editingEntry.pool_name && entry.pool_name) {
                self.editingEntry.pool_name = entry.pool_name;
            }
            self._bindEntryModalHidePrevented();
            self._showModal('entryModal');
            setTimeout(function () {
                self.initEntryPoolSelect2();
                self.initEntryAccountSelect2();
                // Подставляем уже выбранный банк в Select2
                if (self.editingEntry.pool_id && self.editingEntry.pool_name) {
                    var $p = $('#entry-pool-select2');
                    if ($p.length && $p.data('select2')) {
                        $p.val(String(self.editingEntry.pool_id)).trigger('change.select2');
                    }
                }
                if (entry.account_id && entry.account_name) {
                    var $el = $('#entry-account-select2');
                    if ($el.length) {
                        var opt = new Option(entry.account_name, entry.account_id, true, true);
                        $el.append(opt).trigger('change');
                    }
                }
            }, 300);
        },

        /**
         * Подключает подтверждение закрытия формы записи при клике по backdrop.
         *
         * @returns {void}
         */
        _bindEntryModalHidePrevented: function () {
            var self = this;
            var el = document.getElementById('entryModal');
            if (!el) return;
            $(el).off('hidePrevented.bs.modal.entry').on('hidePrevented.bs.modal.entry', function () {
                self.closeEntryModal();
            });
        },

        /**
         * Запрашивает подтверждение закрытия модалки записи без сохранения.
         *
         * Побочный эффект: показывает SweetAlert и при подтверждении закрывает
         * `entryModal`.
         *
         * @returns {void}
         */
        closeEntryModal: function () {
            var self = this;
            Swal.fire({
                title: 'Отменить изменения?',
                text: 'Введённые данные будут потеряны.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, отменить',
                cancelButtonText: 'Нет, продолжить',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    self._forceCloseEntryModal();
                }
            });
        },

        /**
         * Закрывает модалку записи без подтверждения после успешного сохранения.
         *
         * @returns {void}
         */
        _forceCloseEntryModal: function () {
            this._hideModal('entryModal');
            this._entrySelect2Inited = false;
            this.entrySaving = false;
        },

        /**
         * Создаёт или обновляет запись выверки.
         *
         * Валидирует счёт, сумму и валюту, нормализует decimal-ввод без float,
         * отправляет POST `entryCreate` или `entryUpdate`, закрывает модалку и
         * перезагружает таблицу. Сервер пишет аудит и проверяет `company_id`.
         *
         * @returns {void}
         */
        saveEntry: function () {
            var self = this;
            if (self.entrySaving) return;

            // На /all-nostro форма требует явный выбор ностро-банка (Select2 в DOM).
            var poolSelectExists = document.getElementById('entry-pool-select2') !== null;
            if (poolSelectExists && !self.editingEntry.pool_id) {
                Swal.fire({ icon: 'warning', title: 'Выберите ностро-банк', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            if (!self.editingEntry.account_id) {
                Swal.fire({ icon: 'warning', title: 'Выберите счёт', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            self.editingEntry.amount = self.normalizeAmount(self.editingEntry.amount);
            if (self.editingEntry.amount === '' || self.editingEntry.amount === null || isNaN(parseFloat(self.editingEntry.amount))) {
                Swal.fire({ icon: 'warning', title: 'Укажите сумму', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            var amountError = self.validateMoneyAmount(self.editingEntry.amount, false);
            if (amountError) {
                Swal.fire({ icon: 'warning', title: amountError, toast: true,
                    position: 'top-end', timer: 3500, showConfirmButton: false });
                return;
            }
            if (!self.editingEntry.currency) {
                Swal.fire({ icon: 'warning', title: 'Выберите валюту', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            var isNew = !self.editingEntry.id;
            var url   = isNew ? window.AppRoutes.entryCreate : window.AppRoutes.entryUpdate;

            self.entrySaving = true;
            SmartMatchApi.post(url, self.editingEntry).then(function (r) {
                if (r.success) {
                    self._forceCloseEntryModal();
                    self.loadEntries(true);
                    Swal.fire({ icon: 'success',
                        title: isNew ? 'Запись добавлена' : 'Запись обновлена',
                        toast: true, position: 'top-end', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message || JSON.stringify(r.errors) });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Ошибка сети', text: 'Не удалось сохранить запись' });
            }).finally(function () {
                self.entrySaving = false;
            });
        },

        /**
         * Удаляет запись выверки после подтверждения.
         *
         * Вызывает POST `entryDelete`; при успехе локально удаляет строку из
         * текущей страницы и уменьшает `entriesTotal`.
         *
         * @param {Object} entry Запись NostroEntry из таблицы.
         * @returns {void}
         */
        deleteEntry: function (entry) {
            var self = this;
            Swal.fire({
                title: 'Удалить запись?',
                html: '<span style="font-family:monospace;font-size:13px">ID ' +
                    entry.id + ' · ' + entry.ls + ' · ' + entry.dc.charAt(0) +
                    ' · ' + entry.amount + ' ' + entry.currency + '</span>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: '<i class="fas fa-trash me-1"></i>Удалить',
                cancelButtonText: 'Отмена'
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(window.AppRoutes.entryDelete, { id: entry.id })
                    .then(function (r) {
                        if (r.success) {
                            var idx = self.entries.findIndex(function (e) { return e.id === entry.id; });
                            if (idx !== -1) self.entries.splice(idx, 1);
                            self.entriesTotal = Math.max(0, self.entriesTotal - 1);
                            Swal.fire({ icon: 'success', title: 'Удалено', toast: true,
                                position: 'top-end', timer: 1500, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: r.message });
                        }
                    });
            });
        },

        /**
         * Массово выбирает или снимает выбор со всех несквитованных записей.
         *
         * Меняет `selectedIds` только для записей со статусом `U` и
         * пересчитывает summary квитования.
         *
         * @param {boolean} checked Состояние checkbox "выбрать все".
         * @returns {void}
         */
        toggleSelectAll: function (checked) {
            this.selectedIds = checked ? this.unmatchedIds.slice() : [];
            this.updateSummary();
        },

        /**
         * Включает inline-редактирование комментария записи.
         *
         * @param {Object} entry Запись NostroEntry из таблицы.
         * @returns {void}
         */
        startEditComment: function (entry) {
            this.editingCommentId    = entry.id;
            this.editingCommentValue = entry.comment || '';
        },

        /**
         * Сохраняет inline-комментарий записи.
         *
         * Вызывает POST `entryUpdateComment`, при успехе обновляет поле строки
         * локально и выходит из режима редактирования.
         *
         * @param {Object} entry Запись NostroEntry из таблицы.
         * @returns {void}
         */
        saveComment: function (entry) {
            var self = this;
            SmartMatchApi.post(window.AppRoutes.entryUpdateComment, {
                id: entry.id, comment: self.editingCommentValue
            }).then(function (r) {
                if (r.success) {
                    entry.comment        = self.editingCommentValue || null;
                    self.editingCommentId = null;
                }
            });
        },

        /**
         * Отменяет inline-редактирование комментария без запроса к API.
         *
         * @returns {void}
         */
        cancelEditComment: function () {
            this.editingCommentId = null;
        },

        /**
         * Обрабатывает прокрутку контейнера таблицы для догрузки записей.
         *
         * @param {Event} e Событие scroll от контейнера таблицы.
         * @returns {void}
         */
        onTableScroll: function (e) {
            var el = e.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 160) {
                this.loadMoreEntries();
            }
        },

        /**
         * Форматирует сумму для таблицы записей.
         *
         * Не использует `parseFloat` для итоговой строки, чтобы не терять
         * точность отображения decimal(20,2). Некорректные значения показываются
         * как прочерк.
         *
         * @param {string|number|null|undefined} val Значение суммы из API.
         * @returns {string} Сумма в формате `1,234.00` или `—`.
         */
        formatAmount: function (val) {
            if (val === null || val === undefined || val === '') return '—';
            var s = String(val).trim();
            var sign = '';
            if (s.charAt(0) === '-') {
                sign = '-';
                s = s.slice(1);
            }
            s = s.replace(/\s/g, '').replace(/,/g, '');
            if (!/^\d+(\.\d+)?$/.test(s)) return '—';
            var parts = s.split('.');
            var intPart = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            var decPart = ((parts[1] || '') + '00').slice(0, 2);
            return sign + intPart + '.' + decPart;
        },

        /**
         * Нормализует пользовательский ввод суммы записи.
         *
         * Делегирует `normalizeMoneyInput()` без разрешения отрицательных сумм:
         * записи выверки хранят положительную сумму и отдельный признак D/C.
         *
         * @param {string|number|null|undefined} val Пользовательский ввод суммы.
         * @returns {string} Нормализованная сумма в формате с точкой.
         */
        normalizeAmount: function (val) {
            return this.normalizeMoneyInput(val, false);
        },

        /**
         * Нормализует денежный ввод с поддержкой разных разделителей.
         *
         * Если встречаются точка и запятая, крайний правый разделитель считается
         * десятичным, остальные удаляются. Возвращает строку с двумя знаками
         * после точки, чтобы сохранить контракт decimal(20,2).
         *
         * @param {string|number|null|undefined} val Пользовательский ввод.
         * @param {boolean} allowNegative Разрешать ли отрицательные значения.
         * @returns {string} Нормализованное значение или пустая строка.
         */
        normalizeMoneyInput: function (val, allowNegative) {
            if (val === null || val === undefined || val === '') return '';
            var s = String(val).trim();
            var sign = '';
            if (allowNegative && s.charAt(0) === '-') {
                sign = '-';
                s = s.slice(1);
            }
            s = s.replace(/\s/g, '');

            var hasDot = s.indexOf('.') !== -1;
            var hasComma = s.indexOf(',') !== -1;
            if (hasDot && hasComma) {
                var lastDot = s.lastIndexOf('.');
                var lastComma = s.lastIndexOf(',');
                if (lastComma > lastDot) {
                    s = s.replace(/\./g, '');
                    var pos = s.lastIndexOf(',');
                    s = s.slice(0, pos).replace(/,/g, '') + '.' + s.slice(pos + 1);
                } else {
                    s = s.replace(/,/g, '');
                }
            } else if (hasComma) {
                var commaCount = (s.match(/,/g) || []).length;
                var afterLast = s.slice(s.lastIndexOf(',') + 1);
                s = (commaCount === 1 && afterLast.length <= 2) ? s.replace(',', '.') : s.replace(/,/g, '');
            }

            if (s.charAt(0) === '.') s = '0' + s;
            if (/^\d+(\.\d{0,2})?$/.test(s)) {
                var parts = s.split('.');
                s = parts[0] + '.' + ((parts[1] || '') + '00').slice(0, 2);
            }
            return sign + s;
        },

        /**
         * Валидирует денежное значение по ограничениям базы.
         *
         * Проверяет числовой формат, максимум 2 знака после точки и не более
         * 18 цифр до точки для decimal(20,2).
         *
         * @param {string|number|null|undefined} val Значение суммы.
         * @param {boolean} allowNegative Разрешать ли отрицательные значения.
         * @returns {string} Текст ошибки или пустая строка.
         */
        validateMoneyAmount: function (val, allowNegative) {
            var s = this.normalizeMoneyInput(val, allowNegative);
            var re = allowNegative ? /^-?\d+(\.\d{1,2})?$/ : /^\d+(\.\d{1,2})?$/;
            if (!re.test(s)) return 'Сумма должна быть числом с максимум 2 знаками после точки';

            var integerPart = s.replace('-', '').split('.')[0].replace(/^0+/, '');
            if (integerPart.length > 18) {
                return 'Сумма слишком большая: максимум 18 цифр до точки и 2 после';
            }
            return '';
        },

        /**
         * Открывает модалку истории изменений активной записи.
         *
         * Вызывает GET `entryHistory`, заполняет `historyItems` и показывает
         * общий Bootstrap modal `entryHistoryModal`. Сервер восстанавливает
         * snapshot-цепочку аудита, включая restore/archive-сценарии.
         *
         * @param {Object} entry Запись NostroEntry из таблицы.
         * @returns {void}
         */
        showHistory: function (entry) {
            var self = this;
            self.historyEntry = entry;
            self.historyItems = [];
            self.historyLoading = true;
            self._showModal('entryHistoryModal');

            SmartMatchApi.get(window.AppRoutes.entryHistory, { id: entry.id })
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
         * Возвращает описание колонки таблицы по ключу.
         *
         * Используется шаблонами для доступа к ширине колонки без знания её
         * индекса в массиве `tableColumns`. Если колонка не найдена — возвращает
         * безопасный дефолт.
         *
         * @param {string} key Ключ колонки.
         * @returns {{key:string,label:string,visible:boolean,width:number}} Описание колонки.
         */
        colByKey: function (key) {
            var col = this.tableColumns.find(function (c) { return c.key === key; });
            return col || { key: key, label: '', visible: true, width: 100 };
        },
        /**
         * Проверяет видимость колонки таблицы выверки.
         *
         * @param {string} key Ключ колонки.
         * @returns {boolean} `true`, если колонка должна отображаться.
         */
        tblColVisible: function (key) {
            var col = this.tableColumns.find(function (c) { return c.key === key; });
            return col ? col.visible : true;
        },
        /**
         * Переключает выпадающий список управления колонками.
         *
         * @returns {void}
         */
        toggleColsDropdown: function () {
            this.showColsDropdown = !this.showColsDropdown;
        },
        /**
         * Открывает боковую/оверлейную детальную панель записи.
         *
         * Побочный эффект: блокирует прокрутку body, пока карточка деталей открыта.
         *
         * @param {Object} entry Запись NostroEntry для подробного просмотра.
         * @returns {void}
         */
        openEntryDetail: function (entry) {
            this.detailEntry = entry;
            document.body.style.overflow = 'hidden';
        },
        /**
         * Закрывает детальную панель записи и возвращает прокрутку страницы.
         *
         * @returns {void}
         */
        closeEntryDetail: function () {
            this.detailEntry = null;
            document.body.style.overflow = '';
        },
        /**
         * Запускает изменение ширины колонки таблицы.
         *
         * Меняет `col.width` во время mousemove; watcher `tableColumns`
         * сохраняет результат в пользовательские настройки.
         *
         * @param {MouseEvent} e Событие mousedown на ресайзере колонки.
         * @param {Object} col Описание колонки из `tableColumns`.
         * @returns {void}
         */
        startColResize: function (e, col) {
            e.preventDefault();
            e.stopPropagation();
            var startX = e.clientX;
            var startW = col.width || 100;

            var onMove = function (ev) {
                ev.preventDefault();
                var newW = Math.max(50, startW + (ev.clientX - startX));
                col.width = newW;
            };
            var onUp = function () {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.style.userSelect = '';
                document.body.style.cursor      = '';
                document.body.classList.remove('resizing-col');
            };
            document.body.style.userSelect = 'none';
            document.body.style.cursor     = 'col-resize';
            document.body.classList.add('resizing-col');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
        /**
         * Загружает персональные настройки колонок таблицы выверки.
         *
         * Вызывает GET `userPreferenceGet` с ключом `entries_table_columns`,
         * применяет сохранённые `visible` и `width` только к известным колонкам,
         * затем включает флаг, разрешающий сохранение watcher'ом.
         *
         * @returns {void}
         */
        loadTableColumnsPrefs: function () {
            var self = this;
            SmartMatchApi.get(window.AppRoutes.userPreferenceGet, { key: 'entries_table_columns' })
                .then(function (response) {
                    var r = response.data !== undefined ? response.data : response;
                    if (r && r.success && Array.isArray(r.value)) {
                        var saved = {};
                        r.value.forEach(function (c) {
                            if (c && typeof c.key === 'string') saved[c.key] = c;
                        });
                        self.tableColumns.forEach(function (col) {
                            var s = saved[col.key];
                            if (!s) return;
                            if (typeof s.visible === 'boolean') col.visible = s.visible;
                            if (typeof s.width === 'number' && s.width >= 40) col.width = s.width;
                        });
                    }
                })
                .catch(function () { /* no-op */ })
                .then(function () {
                    // Флаг ставим после отрисовки, чтобы watcher не сохранял применённое состояние
                    self.$nextTick(function () { self._tableColumnsLoaded = true; });
                });
        },

        /**
         * Сохраняет персональные настройки колонок таблицы выверки.
         *
         * После debounce отправляет POST `userPreferenceSave` с ключом
         * `entries_table_columns`. Не выполняется до завершения загрузки
         * начальных настроек.
         *
         * @returns {void}
         */
        saveTableColumnsPrefs: function () {
            if (!this._tableColumnsLoaded) return;
            var self = this;
            if (self._colsSaveTimer) clearTimeout(self._colsSaveTimer);
            self._colsSaveTimer = setTimeout(function () {
                var payload = self.tableColumns.map(function (c) {
                    return { key: c.key, visible: !!c.visible, width: c.width };
                });
                SmartMatchApi.post(window.AppRoutes.userPreferenceSave, {
                    key: 'entries_table_columns',
                    value: payload
                });
            }, 600);
        },

        /**
         * Подключает глобальные обработчики закрытия UI управления колонками.
         *
         * Клик вне меню закрывает dropdown, Escape закрывает dropdown и детальную
         * панель записи.
         *
         * @returns {void}
         */
        _initColManagement: function () {
            var self = this;
            document.addEventListener('click', function (e) {
                if (!self.showColsDropdown) return;
                if (e.target.closest && (e.target.closest('.col-mgr-dropdown') || e.target.closest('[data-col-toggle]'))) return;
                self.showColsDropdown = false;
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    self.showColsDropdown = false;
                    self.closeEntryDetail();
                }
            });
        }
    }
};
