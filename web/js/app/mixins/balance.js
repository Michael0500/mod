/**
 * Mixin раздела балансов ностро-счетов.
 *
 * Управляет таблицей `nostro_balance`, CRUD формой, подтверждением ошибок,
 * историей, импортом BND/ASB, фильтрами и пользовательскими настройками
 * колонок. Раздел компании (`NRE`/`INV`) берётся из `AppConfig.companySection`,
 * а сервер ограничивает данные по `company_id` и проверяет денежную точность.
 */
var BalanceMixin = {
    /**
     * Начальное состояние страницы балансов.
     *
     * @returns {Object} Vue data для таблицы, форм, импорта, истории и настроек колонок.
     */
    data: function () {
        // Секция текущего пользователя — NRE или INV
        var section = (window.AppConfig && window.AppConfig.companySection) || 'NRE';

        return {
            balances:            [],
            balancesTotal:       0,
            balancesPage:        1,
            balancesLimit:       50,
            balancesLoading:     false,
            balancesLoadingMore: false,

            balanceSorts: [
                { field: 'value_date', dir: 'asc' },
                { field: 'closing_balance', dir: 'asc' }
            ],

            // Фильтр section предустановлен по компании пользователя
            balanceFilters:     section ? { section: section } : {},
            balanceFiltersOpen: false,

            balanceAccounts: [],
            balancePools:    [],
            balancePoolId:   '',  // фильтр по ностро-банку (Select2)
            balanceFilterAccountId: '', // фильтр по ностро-счёту (Select2)

            /**
             * Форма создания/редактирования записи `nostro_balance`.
             *
             * @type {Object}
             * @property {?number} id ID записи; `null` означает создание.
             * @property {?number} account_id ID счёта текущей компании.
             * @property {string} ls_type Тип баланса: `L` или `S`.
             * @property {string} value_date Дата валютирования в формате `YYYY-MM-DD`.
             * @property {string} opening_balance Opening balance в decimal-формате.
             * @property {string} closing_balance Closing balance в decimal-формате.
             * @property {string} section Раздел компании, например `NRE` или `INV`.
             */
            editingBalance: {
                id: null, account_id: null, account_name: '',
                ls_type: '', statement_number: '', currency: '',
                value_date: '', opening_balance: '', opening_dc: 'C',
                closing_balance: '', closing_dc: 'C',
                section:  section,   // ← дефолт из компании
                source:  'MANUAL', comment: '', status: 'normal',
            },
            editingBalancePoolId: null, // выбранный банк в форме (не сохраняется, только для фильтра счетов)
            balanceModalOpen: false,
            balanceSaving:    false,

            confirmingBalance: null,
            confirmReason:     '',
            confirmModalOpen:  false,
            confirmSaving:     false,

            historyBalance:     null,
            historyLogs:        [],
            historyModalOpen:   false,
            historyLoading:     false,

            importModalOpen:    false,
            importType:         'bnd',
            importAccountId:    null,
            importSection:      section,  // ← дефолт из компании
            importFile:         null,
            importLoading:      false,
            importResult:       null,

            _balanceDebounceTimer: null,

            /**
             * Конфигурация колонок таблицы баланса.
             *
             * `key` соответствует полю API/шаблона, а пользовательские `visible`
             * и `width` сохраняются в `user_preferences.balance_table_columns`.
             *
             * @type {Array<{key: string, label: string, visible: boolean, width: number}>}
             */
            balanceTableColumns: [
                { key: 'id',               label: 'ID',           visible: false, width: 60  },
                { key: 'ls_type',          label: 'L/S',          visible: true,  width: 55  },
                { key: 'section',          label: 'Раздел',       visible: false, width: 80  },
                { key: 'account_id',       label: 'Счёт',         visible: true,  width: 140 },
                { key: 'currency',         label: 'Валюта',       visible: true,  width: 70  },
                { key: 'value_date',       label: 'Дата вал.',    visible: true,  width: 100 },
                { key: 'statement_number', label: '№ выписки',    visible: true,  width: 110 },
                { key: 'opening_balance',  label: 'Opening',      visible: true,  width: 130 },
                { key: 'opening_dc',       label: 'D/C Open',     visible: true,  width: 60  },
                { key: 'closing_balance',  label: 'Closing',      visible: true,  width: 130 },
                { key: 'closing_dc',       label: 'D/C Close',    visible: true,  width: 60  },
                { key: 'source',           label: 'Источник',     visible: true,  width: 90  },
                { key: 'status',           label: 'Статус',       visible: true,  width: 55  },
                { key: 'comment',          label: 'Комментарий',  visible: true,  width: 170 },
            ],
            showBalanceColsDropdown: false,
            _balanceTableColumnsLoaded: false,
            _balanceColsSaveTimer: null,
        };
    },

    watch: {
        balanceTableColumns: {
            /**
             * Сохраняет настройки колонок баланса после изменения.
             *
             * @returns {void}
             */
            handler: function () { this.saveBalanceTableColumnsPrefs(); },
            deep: true
        }
    },

    computed: {
        /**
         * Проверяет наличие следующей страницы балансов.
         *
         * @returns {boolean} `true`, если загружено меньше строк, чем `balancesTotal`.
         */
        hasMoreBalances: function () {
            return this.balances.length < this.balancesTotal;
        },

        /**
         * Возвращает раздел компании пользователя для шаблона и фильтров.
         *
         * @returns {string} Раздел компании, например `NRE` или `INV`.
         */
        userSection: function () {
            return (window.AppConfig && window.AppConfig.companySection) || '';
        },
    },

    methods: {

        /**
         * Показывает единое toast-уведомление раздела балансов.
         *
         * @param {string} message Текст уведомления.
         * @param {string=} type Тип SweetAlert icon.
         * @returns {void}
         */
        _balanceNotify: function (message, type) {
            Swal.fire({
                toast:             true,
                position:          'top-end',
                icon:              type || 'info',
                title:             message,
                showConfirmButton: false,
                timer:             3000,
                timerProgressBar:  true,
            });
        },

        /**
         * Загружает страницу балансов с учётом фильтров, сортировки и раздела.
         *
         * Вызывает GET `balanceList`; при `reset` очищает текущий список и
         * начинает с первой страницы. Фильтр ностро-банка добавляется отдельно
         * из Select2 `balancePoolId`.
         *
         * @param {boolean} reset Нужно ли начать загрузку с первой страницы.
         * @returns {void}
         */
        loadBalances: function (reset) {
            if (reset) {
                this.balancesPage = 1;
                this.balances     = [];
            }
            if (this.balancesLoading || this.balancesLoadingMore) return;

            var isFirstPage = (this.balancesPage === 1);
            if (isFirstPage) this.balancesLoading    = true;
            else             this.balancesLoadingMore = true;

            var self = this;

            // Собираем итоговые фильтры
            var filters = Object.assign({}, self.balanceFilters);

            // Фильтр по ностро-банку:
            //  - Select2 (страница «Баланс по всем ностро-банкам»)
            //  - либо selectedPool из сайдбара (главная страница «Баланс»)
            if (self.balancePoolId) {
                filters.pool_id = self.balancePoolId;
            } else if (self.selectedPool && self.selectedPool.id) {
                filters.pool_id = self.selectedPool.id;
            }
            if (self.balanceFilterAccountId) {
                filters.account_id = self.balanceFilterAccountId;
            }

            SmartMatchApi.get(AppRoutes.balanceList, {
                page:    self.balancesPage,
                limit:   self.balancesLimit,
                sorts:   JSON.stringify(self.balanceSorts),
                filters: JSON.stringify(filters),
            }).then(function (r) {
                var d = r.data;
                if (!d.success) {
                    self._balanceNotify(d.message || 'Ошибка загрузки', 'error');
                    return;
                }
                self.balancesTotal = d.total;
                if (isFirstPage) self.balances = d.data;
                else             self.balances = self.balances.concat(d.data);
            }).finally(function () {
                self.balancesLoading    = false;
                self.balancesLoadingMore = false;
            });
        },

        /**
         * Догружает следующую страницу балансов для infinite scroll.
         *
         * @returns {void}
         */
        loadMoreBalances: function () {
            if (!this.hasMoreBalances) return;
            this.balancesPage++;
            this.loadBalances(false);
        },

        /**
         * Загружает счета и ностро-банки, доступные форме баланса.
         *
         * Вызывает GET `balanceAccounts`, заполняет `balanceAccounts` и
         * `balancePools`, затем инициализирует Select2 фильтра банка.
         *
         * @returns {void}
         */
        loadBalanceAccounts: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.balanceAccounts).then(function (r) {
                if (r.data && r.data.success) {
                    self.balanceAccounts = r.data.data;
                    if (r.data.pools) {
                        self.balancePools = r.data.pools;
                        self.$nextTick(function () {
                            self.initBalancePoolSelect();
                            self.initBalanceAccountSelect();
                        });
                    }
                    if (self.importModalOpen) {
                        self.$nextTick(function () { self.initImportAccountSelect2(); });
                    }
                }
            });
        },

        /**
         * Обрабатывает изменение текстовых фильтров баланса с debounce.
         *
         * @returns {void}
         */
        onBalanceFilterChange: function () {
            clearTimeout(this._balanceDebounceTimer);
            var self = this;
            this._balanceDebounceTimer = setTimeout(function () {
                self.loadBalances(true);
            }, 350);
        },

        /**
         * Переключает видимость панели фильтров и лениво поднимает Select2 валют.
         *
         * Лениво потому, что виджет привязан к скрытому элементу (v-show),
         * и без отложенного init Select2 может посчитать ширину как 0.
         *
         * @returns {void}
         */
        toggleBalanceFilters: function () {
            this.balanceFiltersOpen = !this.balanceFiltersOpen;
            if (this.balanceFiltersOpen) {
                var self = this;
                this.$nextTick(function () { self.initBalanceCurrencySelect2(); });
            }
        },

        /**
         * Сбрасывает все фильтры баланса и связанные Select2-виджеты.
         *
         * Сохраняет дефолтный раздел текущей компании (`userSection`), если
         * он задан, и перезагружает таблицу.
         *
         * @returns {void}
         */
        resetBalanceFilters: function () {
            this.balanceFilters = {};
            if (this.userSection) {
                this.balanceFilters.section = this.userSection;
            }
            var elId = this.balanceCurrencyFilterSelectId || 'balance-filter-currency-select2';
            var $c = jQuery('#' + elId);
            if ($c.length && $c.data('select2')) {
                $c.val(null).trigger('change.select2');
            }
            this.balancePoolId = '';
            this.balanceFilterAccountId = '';
            var $pool = jQuery('#balancePoolSelect');
            if ($pool.length && $pool.data('select2')) {
                $pool.val(null).trigger('change.select2');
            }
            var $account = jQuery('#balanceAccountSelect');
            if ($account.length && $account.data('select2')) {
                $account.val(null).trigger('change.select2');
            }
            this.onBalanceFilterChange();
        },

        /**
         * Инициализирует Select2 мультивыбора валют для фильтра балансов.
         *
         * Использует глобальный `dictCurrencies`, записывает выбранные коды в
         * `balanceFilters.currency` как массив (или удаляет фильтр, если выбор
         * пуст) и перезагружает таблицу через `onBalanceFilterChange`.
         *
         * @returns {void}
         */
        initBalanceCurrencySelect2: function () {
            var self = this;
            var elId = self.balanceCurrencyFilterSelectId || 'balance-filter-currency-select2';
            var $el  = jQuery('#' + elId);
            if (!$el.length || self._balanceCurrencySelect2Inited) return;
            self._balanceCurrencySelect2Inited = true;

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

            var current = self.balanceFilters.currency;
            if (current) {
                $el.val(Array.isArray(current) ? current : [current]).trigger('change.select2');
            }

            $el.off('change.balanceCurrency').on('change.balanceCurrency', function () {
                var vals = $el.val() || [];
                if (!vals.length) {
                    self.$delete(self.balanceFilters, 'currency');
                } else {
                    self.$set(self.balanceFilters, 'currency', vals);
                }
                self.onBalanceFilterChange();
            });
        },

        /**
         * Перезагружает балансы после смены фильтра ностро-банка.
         *
         * @returns {void}
         */
        onBalancePoolChange: function () {
            this.initBalanceAccountSelect();
            this.loadBalances(true);
        },

        /**
         * Меняет сортировку таблицы балансов и перезагружает данные.
         *
         * @param {string} col Ключ колонки сортировки.
         * @returns {void}
         */
        sortBalance: function (col) {
            if (this.balanceSorts.length === 1 && this.balanceSorts[0].field === col) {
                this.balanceSorts[0].dir = this.balanceSorts[0].dir === 'asc' ? 'desc' : 'asc';
            } else {
                this.balanceSorts = [{ field: col, dir: 'asc' }];
            }
            this.loadBalances(true);
        },

        /**
         * Возвращает стандартную сортировку балансов: дата, затем Closing Balance.
         *
         * @returns {void}
         */
        resetBalanceSorting: function () {
            this.balanceSorts = [
                { field: 'value_date', dir: 'asc' },
                { field: 'closing_balance', dir: 'asc' }
            ];
            this.loadBalances(true);
        },

        /**
         * Возвращает иконку направления сортировки балансов.
         *
         * @param {string} col Ключ колонки.
         * @returns {string} CSS-класс Font Awesome.
         */
        balanceSortIcon: function (col) {
            var item = this.balanceSorts.find(function (sort) { return sort.field === col; });
            if (!item) return 'fas fa-sort';
            return item.dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        },

        /**
         * Открывает форму создания записи баланса.
         *
         * Сбрасывает `editingBalance`, подставляет раздел компании и источник
         * `MANUAL`, затем инициализирует Select2 банка и счёта.
         *
         * @returns {void}
         */
        openCreateBalanceModal: function () {
            var section = (window.AppConfig && window.AppConfig.companySection) || 'NRE';
            this.editingBalance = {
                id: null, account_id: null, account_name: '',
                ls_type: '', statement_number: '', currency: '',
                value_date: '', opening_balance: '', opening_dc: 'C',
                closing_balance: '', closing_dc: 'C',
                section:  section,
                source:  'MANUAL', comment: '', status: 'normal',
            };
            this.editingBalancePoolId = null;
            this.balanceModalOpen = true;
            this.$nextTick(function () { this.initBalanceFormSelects(); }.bind(this));
        },

        /**
         * Открывает форму редактирования записи баланса.
         *
         * Копирует строку в `editingBalance`, нормализует дату для input и
         * определяет ностро-банк по `account_id`, чтобы отфильтровать список
         * счетов формы.
         *
         * @param {Object} row Строка `nostro_balance` из таблицы.
         * @returns {void}
         */
        openEditBalanceModal: function (row) {
            this.editingBalance = Object.assign({}, row, {
                value_date:      row.value_date ? row.value_date.substring(0, 10) : '',
                opening_balance: row.opening_balance,
                closing_balance: row.closing_balance,
            });
            // Определяем банк по pool_id счёта
            var acc = (this.balanceAccounts || []).find(function (a) {
                return String(a.id) === String(row.account_id);
            });
            this.editingBalancePoolId = acc && acc.pool_id ? String(acc.pool_id) : null;
            this.balanceModalOpen = true;
            this.$nextTick(function () { this.initBalanceFormSelects(); }.bind(this));
        },

        /**
         * Запрашивает подтверждение закрытия формы баланса без сохранения.
         *
         * @returns {void}
         */
        closeBalanceModal: function () {
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
                if (result.isConfirmed) self.balanceModalOpen = false;
            });
        },

        /**
         * Инициализирует Select2 банка и счёта в форме баланса.
         *
         * @returns {void}
         */
        initBalanceFormSelects: function () {
            this.initBalanceFormPoolSelect2();
            this.initBalanceFormAccountSelect2(this.editingBalancePoolId);
        },

        /**
         * Инициализирует Select2 выбора ностро-банка в форме баланса.
         *
         * Выбор банка меняет `editingBalancePoolId`, сбрасывает выбранный счёт
         * и пересоздаёт Select2 счетов с фильтром по выбранному банку.
         *
         * @returns {void}
         */
        initBalanceFormPoolSelect2: function () {
            var self = this;
            var $el  = $('#balance-form-pool-select2');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
            }

            var poolData = (self.balancePools || []).map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                dropdownParent: $(document.body),
                theme:          'bootstrap-5',
                placeholder:    'Выберите банк...',
                allowClear:     true,
                data:           poolData,
                language: { noResults: function () { return 'Нет ностробанков'; } }
            });

            // Явно сбрасываем / устанавливаем значение после инита
            if (self.editingBalancePoolId) {
                $el.val(String(self.editingBalancePoolId)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.on('select2:select', function (e) {
                self.editingBalancePoolId = e.params.data.id;
                self.editingBalance.account_id   = null;
                self.editingBalance.account_name = '';
                self.initBalanceFormAccountSelect2(self.editingBalancePoolId);
            });
            $el.on('select2:clear', function () {
                self.editingBalancePoolId = null;
                self.editingBalance.account_id   = null;
                self.editingBalance.account_name = '';
                self.initBalanceFormAccountSelect2(null);
            });
        },

        /**
         * Инициализирует Select2 выбора счёта в форме баланса.
         *
         * Фильтрует `balanceAccounts` по `poolId`, записывает выбранные
         * `account_id` и `account_name` в `editingBalance`.
         *
         * @param {number|string|null} poolId ID ностро-банка для фильтра счетов.
         * @returns {void}
         */
        initBalanceFormAccountSelect2: function (poolId) {
            var self = this;
            var $el  = $('#balance-form-account-select2');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
                $el.empty();
            }

            var accounts = (self.balanceAccounts || []).filter(function (a) {
                return !poolId || String(a.pool_id) === String(poolId);
            });

            var accData = accounts.map(function (a) {
                return { id: String(a.id), text: a.name };
            });

            $el.select2({
                dropdownParent: $(document.body),
                theme:          'bootstrap-5',
                placeholder:    poolId ? 'Выберите счёт...' : 'Сначала выберите банк...',
                allowClear:     true,
                data:           accData,
                language: { noResults: function () { return 'Нет счетов'; } }
            });

            // Явно сбрасываем / устанавливаем значение после инита
            if (self.editingBalance.account_id) {
                $el.val(String(self.editingBalance.account_id)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.on('select2:select', function (e) {
                var found = (self.balanceAccounts || []).find(function (a) {
                    return String(a.id) === String(e.params.data.id);
                });
                self.editingBalance.account_id   = parseInt(e.params.data.id);
                self.editingBalance.account_name = found ? found.name : e.params.data.text;
                if (found && found.pool_id && String(found.pool_id) !== String(self.editingBalancePoolId || '')) {
                    self.editingBalancePoolId = String(found.pool_id);
                    var $pool = $('#balance-form-pool-select2');
                    if ($pool.length && $pool.data('select2')) {
                        $pool.val(self.editingBalancePoolId).trigger('change.select2');
                    }
                    self.initBalanceFormAccountSelect2(self.editingBalancePoolId);
                }
            });
            $el.on('select2:clear', function () {
                self.editingBalance.account_id   = null;
                self.editingBalance.account_name = '';
            });
        },

        /**
         * Создаёт или обновляет запись баланса.
         *
         * Валидирует обязательные поля, нормализует opening/closing balance с
         * разрешением отрицательных значений, отправляет POST `balanceCreate`
         * или `balanceUpdate`, закрывает форму и перезагружает таблицу.
         *
         * @returns {void}
         */
        saveBalance: function () {
            var self = this;
            if (self.balanceSaving) return;
            if (!self.editingBalance.ls_type) {
                self._balanceNotify('Выберите тип (L или S)', 'warning'); return;
            }
            if (!self.editingBalance.account_id) {
                self._balanceNotify('Выберите счёт', 'warning'); return;
            }
            if (!self.editingBalance.currency) {
                self._balanceNotify('Выберите валюту', 'warning'); return;
            }
            if (!self.editingBalance.value_date) {
                self._balanceNotify('Укажите дату валютирования', 'warning'); return;
            }
            if (self.editingBalance.ls_type === 'S' && !self.editingBalance.statement_number) {
                self._balanceNotify('Укажите номер выписки', 'warning'); return;
            }

            self.editingBalance.opening_balance = self.normalizeMoneyInput(self.editingBalance.opening_balance, true);
            self.editingBalance.closing_balance = self.normalizeMoneyInput(self.editingBalance.closing_balance, true);

            var openingError = self.validateMoneyAmount(self.editingBalance.opening_balance, true);
            if (openingError) {
                self._balanceNotify('Opening Balance: ' + openingError, 'warning'); return;
            }
            var closingError = self.validateMoneyAmount(self.editingBalance.closing_balance, true);
            if (closingError) {
                self._balanceNotify('Closing Balance: ' + closingError, 'warning'); return;
            }
            self.balanceSaving = true;

            var url = self.editingBalance.id ? AppRoutes.balanceUpdate : AppRoutes.balanceCreate;
            SmartMatchApi.post(url, self.editingBalance).then(function (d) {
                if (!d.success) {
                    Swal.fire('Ошибка', d.message || 'Не удалось сохранить', 'error');
                    return;
                }
                self._balanceNotify(d.message || 'Сохранено', 'success');
                self.balanceModalOpen = false;
                self.loadBalances(true);
            }).catch(function () {
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            }).finally(function () {
                self.balanceSaving = false;
            });
        },

        /**
         * Удаляет запись баланса после подтверждения.
         *
         * Вызывает POST `balanceDelete` и при успехе перезагружает первую
         * страницу балансов.
         *
         * @param {Object} row Строка баланса из таблицы.
         * @returns {void}
         */
        deleteBalance: function (row) {
            var self = this;
            Swal.fire({
                title: 'Удалить запись?',
                text:  (row.account_name || '') + ' · ' + (row.currency || '') + ' · ' + (row.value_date_fmt || row.value_date || ''),
                icon:  'warning',
                showCancelButton:   true,
                confirmButtonColor: '#d33',
                cancelButtonColor:  '#6b7280',
                confirmButtonText:  'Да, удалить',
                cancelButtonText:   'Отмена',
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.post(AppRoutes.balanceDelete, { id: row.id }).then(function (d) {
                    if (d.success) {
                        self._balanceNotify('Запись удалена', 'success');
                        self.loadBalances(true);
                    } else {
                        Swal.fire('Ошибка', d.message || 'Не удалось удалить', 'error');
                    }
                });
            });
        },

        /**
         * Открывает модалку подтверждения ошибочной записи баланса.
         *
         * Используется для фиксации причины корректировки/подтверждения строки
         * со статусом error.
         *
         * @param {Object} row Строка баланса.
         * @returns {void}
         */
        openConfirmModal: function (row) {
            this.confirmingBalance = row;
            this.confirmReason     = '';
            this.confirmModalOpen  = true;
        },

        /**
         * Закрывает модалку подтверждения баланса и очищает выбранную строку.
         *
         * @returns {void}
         */
        closeConfirmModal: function () {
            this.confirmModalOpen  = false;
            this.confirmingBalance = null;
        },

        /**
         * Отправляет подтверждение записи баланса с причиной.
         *
         * Вызывает POST `balanceConfirm`, меняет статус на сервере, пишет аудит
         * и перезагружает таблицу. Пустая причина не допускается.
         *
         * @returns {void}
         */
        submitConfirm: function () {
            var self = this;
            if (!self.confirmReason.trim()) {
                self._balanceNotify('Укажите причину корректировки', 'warning'); return;
            }
            if (self.confirmSaving) return;
            self.confirmSaving = true;

            SmartMatchApi.post(AppRoutes.balanceConfirm, {
                id:     self.confirmingBalance.id,
                reason: self.confirmReason,
            }).then(function (d) {
                if (!d.success) {
                    Swal.fire('Ошибка', d.message || 'Не удалось подтвердить', 'error');
                    return;
                }
                self._balanceNotify('Запись подтверждена ⚫', 'success');
                self.confirmModalOpen = false;
                self.loadBalances(true);
            }).catch(function () {
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            }).finally(function () {
                self.confirmSaving = false;
            });
        },

        /**
         * Открывает модалку истории баланса.
         *
         * @param {Object} row Строка баланса.
         * @returns {void}
         */
        openHistoryModal: function (row) {
            this.historyBalance   = row;
            this.historyLogs      = [];
            this.historyModalOpen = true;
            this.loadHistory(row.id);
        },

        /**
         * Закрывает модалку истории баланса.
         *
         * @returns {void}
         */
        closeHistoryModal: function () {
            this.historyModalOpen = false;
        },

        /**
         * Загружает историю изменений записи баланса.
         *
         * Вызывает GET `balanceHistory` и заполняет `historyLogs`.
         *
         * @param {number|string} id Идентификатор записи `nostro_balance`.
         * @returns {void}
         */
        loadHistory: function (id) {
            var self = this;
            self.historyLoading = true;
            SmartMatchApi.get(AppRoutes.balanceHistory, { id: id }).then(function (r) {
                if (r.data && r.data.success) self.historyLogs = r.data.data;
            }).finally(function () {
                self.historyLoading = false;
            });
        },

        /**
         * Открывает модалку импорта файла балансов.
         *
         * Сбрасывает выбранный счёт, файл и результат импорта; раздел берётся
         * из компании пользователя.
         *
         * @param {string=} type Тип импорта: `bnd` или `asb`.
         * @returns {void}
         */
        openImportModal: function (type) {
            this.resetImportModalState(type || 'bnd');
            this.importModalOpen = true;

            var self = this;
            this.$nextTick(function () {
                self.initImportAccountSelect2();
            });
        },

        /**
         * Закрывает модалку импорта балансов.
         *
         * @returns {void}
         */
        closeImportModal: function () {
            this.destroyImportAccountSelect2();
            this.importModalOpen = false;
            this.resetImportModalState(this.importType);
        },

        /**
         * Сбрасывает состояние модалки импорта и очищает DOM file input.
         *
         * @param {string=} type Тип импорта, который нужно оставить выбранным.
         * @returns {void}
         */
        resetImportModalState: function (type) {
            var section = (window.AppConfig && window.AppConfig.companySection) || 'NRE';
            this.importType      = type || this.importType || 'bnd';
            this.importAccountId = null;
            this.importSection   = section;
            this.importFile      = null;
            this.importResult    = null;
            this.importLoading   = false;

            var fileInput = document.getElementById('balance-import-file-input');
            if (fileInput) {
                fileInput.value = '';
            }

            var $account = $('#balance-import-account-select2');
            if ($account.length && $account.data('select2')) {
                $account.val(null).trigger('change.select2');
            }
        },

        /**
         * Инициализирует Select2 выбора счёта в модалке импорта BND/ASB.
         *
         * Использует загруженный список `balanceAccounts` и записывает выбранный
         * `account_id` в `importAccountId`.
         *
         * @returns {void}
         */
        initImportAccountSelect2: function () {
            var self = this;
            var $el = $('#balance-import-account-select2');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
                $el.empty();
            }

            var accountData = (self.balanceAccounts || []).map(function (a) {
                return { id: String(a.id), text: a.name };
            });

            $el.select2({
                dropdownParent: $(document.body),
                theme:          'bootstrap-5',
                placeholder:    'Выберите счёт...',
                allowClear:     true,
                width:          '100%',
                data:           accountData,
                language: { noResults: function () { return 'Нет счетов'; } }
            });

            if (self.importAccountId) {
                $el.val(String(self.importAccountId)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.on('select2:select', function (e) {
                self.importAccountId = parseInt(e.params.data.id, 10);
            });
            $el.on('select2:clear', function () {
                self.importAccountId = null;
            });
        },

        /**
         * Уничтожает Select2 выбора счёта в модалке импорта.
         *
         * @returns {void}
         */
        destroyImportAccountSelect2: function () {
            var $el = $('#balance-import-account-select2');
            if ($el.length && $el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
                $el.empty();
            }
        },

        /**
         * Сохраняет выбранный файл импорта из input[type=file].
         *
         * @param {Event} e DOM-событие change.
         * @returns {void}
         */
        onImportFileChange: function (e) {
            this.importFile = e.target.files[0] || null;
        },

        /**
         * Отправляет файл импорта балансов на сервер.
         *
         * Формирует `FormData` с файлом, account_id и section, затем вызывает
         * `balanceImportAsb` или `balanceImportBnd`. При успехе обновляет
         * таблицу и сохраняет результат импорта для UI.
         *
         * @returns {void}
         */
        submitImport: function () {
            var self = this;

            var accountId = parseInt(self.importAccountId, 10);
            if (!accountId || isNaN(accountId)) {
                self._balanceNotify('Выберите счёт', 'warning');
                return;
            }
            if (!self.importFile) {
                self._balanceNotify('Выберите файл', 'warning');
                return;
            }
            if (self.importLoading) return;

            self.importLoading = true;
            self.importResult  = null;

            var fd = new FormData();
            fd.append('file',       self.importFile);
            fd.append('account_id', accountId);
            fd.append('section',    self.importSection);

            var url = (self.importType === 'asb')
                ? AppRoutes.balanceImportAsb
                : AppRoutes.balanceImportBnd;

            axios.post(url, fd, {
                transformRequest: [function (data) { return data; }],
                headers: { 'Content-Type': undefined },
            }).then(function (r) {
                self.importResult = r.data;
                if (r.data.success) {
                    self._balanceNotify(r.data.message, r.data.errors > 0 ? 'warning' : 'success');
                    self.loadBalances(true);
                    self.closeImportModal();
                } else {
                    Swal.fire('Ошибка импорта', r.data.message || 'Неизвестная ошибка', 'error');
                }
            }).catch(function (err) {
                console.error('Import error:', err);
                Swal.fire('Ошибка', 'Не удалось загрузить файл', 'error');
            }).finally(function () {
                self.importLoading = false;
            });
        },

        /**
         * Возвращает визуальную метку статуса баланса.
         *
         * @param {string} status Код статуса строки.
         * @returns {string} Символ статуса для таблицы.
         */
        balanceStatusIcon: function (status) {
            if (status === 'error')     return '🔴';
            if (status === 'confirmed') return '⚫';
            return '⚪';
        },

        /**
         * Форматирует сумму баланса для отображения в таблице.
         *
         * @param {string|number|null|undefined} amount Значение opening/closing balance.
         * @returns {string} Сумма в формате `1 234,00` или `—`.
         */
        formatBalanceAmount: function (amount) {
            if (amount === null || amount === undefined || amount === '') return '—';
            var s = String(amount).trim();
            var sign = '';
            if (s.charAt(0) === '-') {
                sign = '-';
                s = s.slice(1);
            }
            s = s.replace(/\s/g, '').replace(/,/g, '');
            if (!/^\d+(\.\d+)?$/.test(s)) return '—';
            var parts = s.split('.');
            var intPart = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            var decPart = ((parts[1] || '') + '00').slice(0, 2);
            return sign + intPart + ',' + decPart;
        },

        /**
         * Нормализует денежный ввод баланса с поддержкой разных разделителей.
         *
         * Балансы могут быть отрицательными, поэтому флаг `allowNegative`
         * используется формой и валидацией. Возвращает строку с точкой как
         * десятичным разделителем для API.
         *
         * @param {string|number|null|undefined} val Пользовательский ввод.
         * @param {boolean} allowNegative Разрешать отрицательные суммы.
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
         * Валидирует сумму баланса по контракту decimal(20,2).
         *
         * @param {string|number|null|undefined} val Значение суммы.
         * @param {boolean} allowNegative Разрешать отрицательные суммы.
         * @returns {string} Текст ошибки или пустая строка.
         */
        validateMoneyAmount: function (val, allowNegative) {
            var s = this.normalizeMoneyInput(val, allowNegative);
            var re = allowNegative ? /^-?\d+(\.\d{1,2})?$/ : /^\d+(\.\d{1,2})?$/;
            if (!re.test(s)) return 'сумма должна быть числом с максимум 2 знаками после запятой';

            var integerPart = s.replace('-', '').split('.')[0].replace(/^0+/, '');
            if (integerPart.length > 18) {
                return 'максимум 18 цифр до запятой и 2 после';
            }
            return '';
        },

        /**
         * Инициализирует Select2 фильтра ностро-банка на странице балансов.
         *
         * Перезаполняет options из `balancePools`, синхронизирует текущее
         * `balancePoolId` и перезагружает таблицу при изменении значения.
         *
         * @returns {void}
         */
        initBalancePoolSelect: function () {
            var self = this;
            var $el = jQuery('#balancePoolSelect');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('change.balancePool');
                $el.select2('destroy');
            }

            // Заполняем options
            $el.empty().append(new Option('— Все ностро-банки —', '', false, false));
            self.balancePools.forEach(function (p) {
                $el.append(new Option(p.name, p.id, false, false));
            });

            $el.select2({
                theme: 'bootstrap-5',
                allowClear: true,
                placeholder: '— Все ностро-банки —',
                width: '300px',
            });

            // Установить текущее значение
            if (self.balancePoolId) {
                $el.val(self.balancePoolId).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.off('change.balancePool').on('change.balancePool', function () {
                var nextPoolId = jQuery(this).val() || '';
                if (nextPoolId !== self.balancePoolId) {
                    self.balanceFilterAccountId = '';
                }
                self.balancePoolId = nextPoolId;
                self.onBalancePoolChange();
            });
        },

        /**
         * Инициализирует Select2 фильтра ностро-счёта на странице балансов.
         *
         * Если выбран ностро-банк, список ограничивается его счетами. Если
         * выбран счёт без банка, связанный банк автоматически подставляется.
         *
         * @returns {void}
         */
        initBalanceAccountSelect: function () {
            var self = this;
            var $el = jQuery('#balanceAccountSelect');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('change.balanceAccount');
                $el.select2('destroy');
            }

            var accounts = (self.balanceAccounts || []).filter(function (a) {
                return !self.balancePoolId || String(a.pool_id) === String(self.balancePoolId);
            });

            $el.empty().append(new Option('', '', false, false));
            accounts.forEach(function (a) {
                $el.append(new Option(a.name, a.id, false, false));
            });

            $el.select2({
                theme: 'bootstrap-5',
                allowClear: true,
                placeholder: self.balancePoolId ? '— Счёт ностро-банка —' : '— Все ностро-счета —',
                width: '320px',
                language: { noResults: function () { return 'Нет счетов'; } }
            });

            if (self.balanceFilterAccountId) {
                var currentExists = accounts.some(function (a) {
                    return String(a.id) === String(self.balanceFilterAccountId);
                });
                if (currentExists) {
                    $el.val(String(self.balanceFilterAccountId)).trigger('change.select2');
                } else {
                    self.balanceFilterAccountId = '';
                    $el.val(null).trigger('change.select2');
                }
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.off('change.balanceAccount').on('change.balanceAccount', function () {
                var accountId = jQuery(this).val() || '';
                self.balanceFilterAccountId = accountId;

                if (accountId) {
                    var account = (self.balanceAccounts || []).find(function (a) {
                        return String(a.id) === String(accountId);
                    });
                    if (account && account.pool_id && String(account.pool_id) !== String(self.balancePoolId)) {
                        self.balancePoolId = String(account.pool_id);
                        var $pool = jQuery('#balancePoolSelect');
                        if ($pool.length && $pool.data('select2')) {
                            $pool.val(self.balancePoolId).trigger('change.select2');
                        }
                        self.initBalanceAccountSelect();
                    }
                }

                self.loadBalances(true);
            });
        },

        /**
         * Проверяет видимость колонки таблицы балансов.
         *
         * @param {string} key Ключ колонки.
         * @returns {boolean} `true`, если колонка видима.
         */
        balanceColVisible: function (key) {
            var col = this.balanceTableColumns.find(function (c) { return c.key === key; });
            return col ? col.visible : true;
        },
        /**
         * Возвращает описание колонки баланса по ключу.
         *
         * @param {string} key Ключ колонки.
         * @returns {Object|undefined} Объект колонки или `undefined`.
         */
        balanceColByKey: function (key) {
            return this.balanceTableColumns.find(function (c) { return c.key === key; });
        },
        /**
         * Переключает dropdown управления колонками баланса.
         *
         * @returns {void}
         */
        toggleBalanceColsDropdown: function () {
            this.showBalanceColsDropdown = !this.showBalanceColsDropdown;
        },
        /**
         * Запускает изменение ширины колонки баланса.
         *
         * @param {MouseEvent} e Событие mousedown на ресайзере.
         * @param {Object} col Колонка из `balanceTableColumns`.
         * @returns {void}
         */
        startBalanceColResize: function (e, col) {
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
         * Загружает персональные настройки колонок баланса.
         *
         * Вызывает GET `userPreferenceGet` с ключом `balance_table_columns` и
         * применяет сохранённые ширины/видимость к известным колонкам.
         *
         * @returns {void}
         */
        loadBalanceTableColumnsPrefs: function () {
            var self = this;
            SmartMatchApi.get(window.AppRoutes.userPreferenceGet, { key: 'balance_table_columns' })
                .then(function (response) {
                    var r = response.data !== undefined ? response.data : response;
                    if (r && r.success && Array.isArray(r.value)) {
                        var saved = {};
                        r.value.forEach(function (c) {
                            if (c && typeof c.key === 'string') saved[c.key] = c;
                        });
                        self.balanceTableColumns.forEach(function (col) {
                            var s = saved[col.key];
                            if (!s) return;
                            if (typeof s.visible === 'boolean') col.visible = s.visible;
                            if (typeof s.width === 'number' && s.width >= 40) col.width = s.width;
                        });
                    }
                })
                .catch(function () { /* no-op */ })
                .then(function () {
                    self.$nextTick(function () { self._balanceTableColumnsLoaded = true; });
                });
        },
        /**
         * Сохраняет персональные настройки колонок баланса.
         *
         * После debounce вызывает POST `userPreferenceSave` с ключом
         * `balance_table_columns`.
         *
         * @returns {void}
         */
        saveBalanceTableColumnsPrefs: function () {
            if (!this._balanceTableColumnsLoaded) return;
            var self = this;
            if (self._balanceColsSaveTimer) clearTimeout(self._balanceColsSaveTimer);
            self._balanceColsSaveTimer = setTimeout(function () {
                var payload = self.balanceTableColumns.map(function (c) {
                    return { key: c.key, visible: !!c.visible, width: c.width };
                });
                SmartMatchApi.post(window.AppRoutes.userPreferenceSave, {
                    key: 'balance_table_columns',
                    value: payload
                });
            }, 600);
        },
        /**
         * Подключает глобальные обработчики закрытия dropdown колонок баланса.
         *
         * @returns {void}
         */
        _initBalanceColManagement: function () {
            var self = this;
            document.addEventListener('click', function (e) {
                if (!self.showBalanceColsDropdown) return;
                if (e.target.closest && (e.target.closest('.col-mgr-dropdown') || e.target.closest('[data-balance-col-toggle]'))) return;
                self.showBalanceColsDropdown = false;
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') self.showBalanceColsDropdown = false;
            });
        },

        /**
         * Подключает infinite scroll к контейнеру таблицы балансов.
         *
         * Используется страницей, где scroll-обработчик не привязан напрямую в
         * шаблоне.
         *
         * @returns {void}
         */
        initBalanceInfiniteScroll: function () {
            var self  = this;
            var table = document.querySelector('.balance-table-wrap');
            if (!table) return;
            table.addEventListener('scroll', function () {
                if (table.scrollTop + table.clientHeight >= table.scrollHeight - 100) {
                    self.loadMoreBalances();
                }
            });
        },
    },
};
