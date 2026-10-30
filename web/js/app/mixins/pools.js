/**
 * Mixin управления ностро-банками в сайдбаре выверки.
 *
 * Отвечает за выбор `AccountPool`, быстрое создание с привязкой свободных
 * Ledger/Statement-счетов, перемещение между категориями, открепление и
 * удаление. Серверные endpoints проверяют `company_id`, связи счетов и
 * допустимость удаления записей выверки.
 */
var PoolsMixin = {
    /**
     * Начальное состояние форм и списков для операций с ностро-банками.
     *
     * @returns {Object} Vue data для модалок создания и перемещения банка.
     */
    data: function () {
        return {
            // Форма быстрого создания ностро-банка из сайдбара
            newPool: {
                name:          '',
                description:   '',
                category_id:   null,
                category_name: '',
            },

            // Привязка счетов в модалке быстрого создания
            loadingPoolAccounts:        false,
            availableLedgerAccounts:    [],
            availableStatementAccounts: [],
            selectedLedgerAccounts:     [],
            selectedStatementAccounts:  [],

            // Перемещение ностро-банка
            movingPool: {
                id:                  null,
                name:                '',
                from_category_id:    null,
                from_category_name:  '',
                target_category_id:  '',
            },
        };
    },

    methods: {

        /**
         * Выбирает ностро-банк и запускает загрузку записей выверки.
         *
         * Изменяет `selectedPool` и `selectedCategory`, затем вызывает
         * `loadEntries(true)` из EntriesMixin. Выбор не сохраняется между
         * страницами, чтобы фильтр сбрасывался после возврата.
         *
         * @param {Object|null} pool Ностро-банк из выбранной категории.
         * @param {Object|null} category Категория, к которой привязан банк.
         * @returns {void}
         */
        selectPool: function (pool, category) {
            this.selectedPool     = pool;
            this.selectedCategory = category;

            StateStorage.remove('selectedPoolId');
            StateStorage.remove('selectedCategoryId');

            this.loadEntries(true);
        },

        /**
         * Открывает модалку быстрого создания ностро-банка.
         *
         * Сбрасывает форму, подставляет категорию, загружает свободные счета
         * через GET `accountPoolAvailableAccounts` и инициализирует Select2 для
         * Ledger/Statement привязок.
         *
         * @param {Object|null} category Категория, в которую будет добавлен банк.
         * @returns {void}
         */
        showAddPoolModal: function (category) {
            var self = this;
            self.newPool = {
                name:          '',
                description:   '',
                category_id:   category ? category.id   : null,
                category_name: category ? category.name : '',
            };
            self.selectedLedgerAccounts    = [];
            self.selectedStatementAccounts = [];
            self.availableLedgerAccounts    = [];
            self.availableStatementAccounts = [];
            self._showModal('addPoolModal');
            self.$nextTick(function () {
                if (self.$refs.addPoolNameInput) self.$refs.addPoolNameInput.focus();
            });

            // Загружаем свободные счета и инициализируем select2
            self.loadingPoolAccounts = true;
            SmartMatchApi.get(window.AppRoutes.accountPoolAvailableAccounts).then(function (r) {
                var payload  = (r && r.data) ? r.data : {};
                var accounts = payload.success ? (payload.data || []) : [];
                self.availableLedgerAccounts    = accounts.filter(function (a) { return a.account_type === 'L'; });
                self.availableStatementAccounts = accounts.filter(function (a) { return a.account_type === 'S'; });
            }).catch(function () {
                self.availableLedgerAccounts    = [];
                self.availableStatementAccounts = [];
            }).then(function () {
                self.loadingPoolAccounts = false;
                self.$nextTick(function () { self._initAddPoolAccountSelects(); });
            });
        },

        /**
         * Инициализирует Select2 для выбора свободных Ledger и Statement счетов.
         *
         * Побочные эффекты: пересоздаёт виджеты `#add-pool-ledger-select2` и
         * `#add-pool-statement-select2`, обновляет массивы выбранных account IDs.
         *
         * @returns {void}
         */
        _initAddPoolAccountSelects: function () {
            var self = this;
            if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;

            var $l = $('#add-pool-ledger-select2');
            if ($l.length) {
                if ($l.data('select2')) $l.off('change.addPoolL').select2('destroy');
                $l.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Выберите Ledger счета —',
                    allowClear: true,
                    multiple: true,
                    data: self.availableLedgerAccounts.map(function (a) {
                        return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                    }),
                    dropdownParent: $('#addPoolModal'),
                }).val(null).trigger('change');
                $l.on('change.addPoolL', function () {
                    self.selectedLedgerAccounts = $l.val() || [];
                });
            }

            var $s = $('#add-pool-statement-select2');
            if ($s.length) {
                if ($s.data('select2')) $s.off('change.addPoolS').select2('destroy');
                $s.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Выберите Statement счета —',
                    allowClear: true,
                    multiple: true,
                    data: self.availableStatementAccounts.map(function (a) {
                        return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                    }),
                    dropdownParent: $('#addPoolModal'),
                }).val(null).trigger('change');
                $s.on('change.addPoolS', function () {
                    self.selectedStatementAccounts = $s.val() || [];
                });
            }
        },

        /**
         * Создаёт ностро-банк из сайдбара.
         *
         * Валидирует имя, отправляет POST `accountPoolQuickCreate` с категорией
         * и выбранными счетами, закрывает модалку, обновляет дерево категорий и
         * список банков для фильтров.
         *
         * @returns {void}
         */
        createPoolFromSidebar: function () {
            var self = this;
            var name = (self.newPool.name || '').trim();
            if (!name) {
                Swal.fire({ icon: 'warning', title: 'Введите название', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            SmartMatchApi.post(window.AppRoutes.accountPoolQuickCreate, {
                name:               name,
                description:        self.newPool.description || '',
                category_id:        self.newPool.category_id || '',
                ledger_accounts:    self.selectedLedgerAccounts,
                statement_accounts: self.selectedStatementAccounts,
            }).then(function (r) {
                if (r.success) {
                    self._hideModal('addPoolModal');
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 1800, showConfirmButton: false });
                    self.loadCategories();
                    self.loadAccountPools && self.loadAccountPools();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message || 'Не удалось создать' });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Сетевая ошибка' });
            });
        },

        /**
         * Открывает модалку перемещения ностро-банка между категориями.
         *
         * @param {Object} pool Ностро-банк, который перемещается.
         * @param {Object|null} fromCategory Текущая категория банка.
         * @returns {void}
         */
        showMovePoolModal: function (pool, fromCategory) {
            this.movingPool = {
                id:                 pool.id,
                name:               pool.name,
                from_category_id:   fromCategory ? fromCategory.id   : null,
                from_category_name: fromCategory ? fromCategory.name : '',
                target_category_id: fromCategory ? fromCategory.id   : '',
            };
            this._showModal('movePoolModal');
        },

        /**
         * Подтверждает перемещение ностро-банка в выбранную категорию.
         *
         * Вызывает POST `accountPoolMoveToCategory`, обновляет категории и
         * список банков. Пустой `category_id` означает снятие привязки.
         *
         * @returns {void}
         */
        confirmMovePool: function () {
            var self     = this;
            var poolId   = self.movingPool.id;
            var targetId = self.movingPool.target_category_id;

            // Если категория не изменилась — просто закрываем
            if (String(targetId || '') === String(self.movingPool.from_category_id || '')) {
                self._hideModal('movePoolModal');
                return;
            }

            SmartMatchApi.post(window.AppRoutes.accountPoolMoveToCategory, {
                id:          poolId,
                category_id: targetId === '' || targetId === null ? '' : targetId,
            }).then(function (r) {
                if (r.success) {
                    self._hideModal('movePoolModal');
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 1800, showConfirmButton: false });
                    self.loadCategories();
                    self.loadAccountPools && self.loadAccountPools();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Сетевая ошибка' });
            });
        },

        /**
         * Открепляет ностро-банк от категории без удаления банка и счетов.
         *
         * После подтверждения вызывает POST `accountPoolMoveToCategory` с пустой
         * категорией. Если банк был выбран в выверке, сбрасывает `selectedPool`.
         *
         * @param {Object} pool Ностро-банк для открепления.
         * @param {Object|null} fromCategory Категория, из которой банк убирается.
         * @returns {void}
         */
        detachPoolFromCategory: function (pool, fromCategory) {
            var self = this;
            Swal.fire({
                title: 'Открепить ностро-банк?',
                html: '<b>' + pool.name + '</b> будет откреплён от категории «' +
                    (fromCategory ? fromCategory.name : '—') +
                    '». Сам банк и его счета останутся, но он исчезнет из сайдбара.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Открепить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#6366f1',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(window.AppRoutes.accountPoolMoveToCategory, {
                    id:          pool.id,
                    category_id: '',
                }).then(function (r) {
                    if (r.success) {
                        Swal.fire({ icon: 'success', title: r.message, toast: true,
                            position: 'top-end', timer: 1800, showConfirmButton: false });
                        if (self.selectedPool && self.selectedPool.id === pool.id) {
                            self.selectedPool = null;
                        }
                        self.loadCategories();
                    } else {
                        Swal.fire({ icon: 'error', title: r.message });
                    }
                });
            });
        },

        /**
         * Удаляет ностро-банк после явного подтверждения.
         *
         * Вызывает POST `accountPoolDelete`. При удалении выбранного банка
         * очищает таблицу записей и счётчик, затем перезагружает категории и
         * список банков. Операция может затронуть записи выверки на сервере.
         *
         * @param {Object} pool Ностро-банк для удаления.
         * @returns {void}
         */
        deletePool: function (pool) {
            var self = this;
            Swal.fire({
                title: 'Удалить ностро-банк?',
                html: '<b>' + pool.name + '</b> и все его записи выверки будут удалены безвозвратно. Привязанные счета будут отвязаны.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, удалить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(window.AppRoutes.accountPoolDelete, { id: pool.id })
                    .then(function (r) {
                        if (r.success) {
                            Swal.fire({ icon: 'success', title: r.message, toast: true,
                                position: 'top-end', timer: 1800, showConfirmButton: false });
                            if (self.selectedPool && self.selectedPool.id === pool.id) {
                                self.selectedPool = null;
                                self.entries     = [];
                                self.entriesTotal = 0;
                            }
                            self.loadCategories();
                            self.loadAccountPools && self.loadAccountPools();
                        } else {
                            Swal.fire({ icon: 'error', title: r.message });
                        }
                    });
            });
        },
    }
};
