/**
 * Mixin страницы архива балансов.
 *
 * Управляет таблицей `nostro_balance_archive`, фильтрами, batch-архивированием,
 * очисткой просроченных строк, восстановлением баланса, историей и
 * пользовательскими настройками колонок.
 */
var BalanceArchiveMixin = {
    created: function () {
        StateStorage.remove('balance_archive_filters');
    },

    data: function () {
        return {
            balanceArchiveRows:        [],
            balanceArchiveTotal:       0,
            balanceArchivePage:        1,
            balanceArchiveLimit:       50,
            balanceArchiveLoading:     false,
            balanceArchiveLoadingMore: false,

            balanceArchiveSortCol: 'archived_at',
            balanceArchiveSortDir: 'desc',

            balanceArchiveFilters:     {},
            balanceArchiveFiltersOpen: false,
            balanceArchiveAccounts:    [],
            balanceArchivePools:       [],

            balanceArchiveStats: null,
            balanceArchiveSettings: {
                archive_after_days:   90,
                retention_years:       5,
                auto_archive_enabled:  true,
            },
            balanceArchiveSavedSettings: {
                archive_after_days:   90,
                retention_years:       5,
                auto_archive_enabled:  true,
            },
            balanceArchiveSettingsOpen:   false,
            balanceArchiveSettingsSaving: false,

            balanceArchiveRunning:      false,
            balanceArchivePurging:      false,
            balanceArchiveProgressOpen: false,
            balanceArchiveProgressDone: 0,
            balanceArchiveProgressAll:  0,
            balanceArchiveProgressPct:  0,

            balanceArchiveHistoryOpen:    false,
            balanceArchiveHistoryLoading: false,
            balanceArchiveHistoryBalance: null,
            balanceArchiveHistoryLogs:    [],

            _balanceArchiveDebounceTimer:        null,
            _balanceArchivePoolSelect2Inited:    false,
            _balanceArchiveAccountSelect2Inited: false,
            _balanceArchiveCurrencySelect2Inited: false,
            _balanceArchiveSubmitGuardBound:     false,

            balanceArchiveTableColumns: [
                { key: 'id',               label: 'ID',           visible: false, width: 60  },
                { key: 'original_id',      label: 'Исх. ID',      visible: false, width: 70  },
                { key: 'ls_type',          label: 'L/S',          visible: true,  width: 55  },
                { key: 'section',          label: 'Раздел',       visible: false, width: 80  },
                { key: 'account_id',       label: 'Счёт',         visible: true,  width: 160 },
                { key: 'currency',         label: 'Валюта',       visible: true,  width: 70  },
                { key: 'value_date',       label: 'Дата вал.',    visible: true,  width: 105 },
                { key: 'statement_number', label: '№ выписки',    visible: true,  width: 120 },
                { key: 'opening_balance',  label: 'Opening',      visible: true,  width: 130 },
                { key: 'opening_dc',       label: 'D/C Open',     visible: true,  width: 65  },
                { key: 'closing_balance',  label: 'Closing',      visible: true,  width: 130 },
                { key: 'closing_dc',       label: 'D/C Close',    visible: true,  width: 65  },
                { key: 'source',           label: 'Источник',     visible: true,  width: 90  },
                { key: 'status',           label: 'Статус',       visible: true,  width: 75  },
                { key: 'archived_at',      label: 'Архивирован',  visible: true,  width: 120 },
                { key: 'expires_at',       label: 'Хранить до',   visible: true,  width: 115 },
                { key: 'comment',          label: 'Комментарий',  visible: false, width: 180 },
            ],
            showBalanceArchiveColsDropdown: false,
            _balanceArchiveTableColumnsLoaded: false,
            _balanceArchiveColsSaveTimer: null,
        };
    },

    watch: {
        balanceArchiveTableColumns: {
            handler: function () { this.saveBalanceArchiveTableColumnsPrefs(); },
            deep: true
        }
    },

    computed: {
        hasMoreBalanceArchive: function () {
            return this.balanceArchiveRows.length < this.balanceArchiveTotal;
        },
        balanceArchiveUserSection: function () {
            return (window.AppConfig && window.AppConfig.companySection) || '';
        },
    },

    methods: {
        bindBalanceArchiveSubmitGuard: function () {
            if (this._balanceArchiveSubmitGuardBound) return;
            this._balanceArchiveSubmitGuardBound = true;

            document.addEventListener('submit', function (e) {
                var root = document.getElementById('balance-archive-app');
                if (!root || !e.target) return;
                if (!root.contains(e.target) && !e.target.contains(root)) return;
                e.preventDefault();
                e.stopPropagation();
            }, true);
        },

        loadBalanceArchive: function (reset, keepFiltersOpen) {
            if (keepFiltersOpen) {
                this.balanceArchiveFiltersOpen = true;
                this.saveBalanceArchiveFilterState();
            }
            if (reset) {
                this.balanceArchiveRows = [];
                this.balanceArchivePage = 1;
            }

            var self = this;
            var isFirst = self.balanceArchivePage === 1;
            if (isFirst) self.balanceArchiveLoading = true;
            else self.balanceArchiveLoadingMore = true;

            SmartMatchApi.get(AppRoutes.balanceArchiveList, {
                page:    self.balanceArchivePage,
                limit:   self.balanceArchiveLimit,
                sort:    self.balanceArchiveSortCol,
                dir:     self.balanceArchiveSortDir,
                filters: JSON.stringify(self.balanceArchiveFilters),
            }).then(function (r) {
                var d = r.data;
                if (d && d.success) {
                    self.balanceArchiveRows = reset ? d.data : self.balanceArchiveRows.concat(d.data);
                    self.balanceArchiveTotal = d.total;
                }
            }).finally(function () {
                self.balanceArchiveLoading = false;
                self.balanceArchiveLoadingMore = false;
            });
        },

        loadMoreBalanceArchive: function () {
            if (this.balanceArchiveLoadingMore || !this.hasMoreBalanceArchive) return;
            this.balanceArchivePage++;
            this.loadBalanceArchive(false);
        },

        onBalanceArchiveScroll: function (e) {
            var el = e.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 160) {
                this.loadMoreBalanceArchive();
            }
        },

        sortBalanceArchive: function (col) {
            if (this.balanceArchiveSortCol === col) {
                this.balanceArchiveSortDir = this.balanceArchiveSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.balanceArchiveSortCol = col;
                this.balanceArchiveSortDir = 'asc';
            }
            this.loadBalanceArchive(true);
        },

        balanceArchiveSortIcon: function (col) {
            if (this.balanceArchiveSortCol !== col) return 'fas fa-sort';
            return this.balanceArchiveSortDir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        },

        applyBalanceArchiveFilter: function (field, value) {
            this.balanceArchiveFiltersOpen = true;
            if (Array.isArray(value)) {
                var values = value.map(function (item) {
                    return String(item || '').trim();
                }).filter(function (item) {
                    return item !== '';
                });
                if (values.length) {
                    this.$set(this.balanceArchiveFilters, field, values);
                } else {
                    this.$delete(this.balanceArchiveFilters, field);
                }
            } else {
                var v = (value === null || value === undefined) ? '' : String(value).trim();
                if (v === '') {
                    this.$delete(this.balanceArchiveFilters, field);
                } else {
                    this.$set(this.balanceArchiveFilters, field, v);
                }
            }
            this.saveBalanceArchiveFilterState();
            this.loadBalanceArchive(true, true);
        },

        clearBalanceArchiveFilter: function (field) {
            this.balanceArchiveFiltersOpen = true;
            this.$delete(this.balanceArchiveFilters, field);
            this.saveBalanceArchiveFilterState();
            this.loadBalanceArchive(true, true);
        },

        debouncedBalanceArchiveFilter: function (field, value) {
            var self = this;
            clearTimeout(self._balanceArchiveDebounceTimer);
            self._balanceArchiveDebounceTimer = setTimeout(function () {
                self.applyBalanceArchiveFilter(field, value);
            }, 400);
        },

        clearAllBalanceArchiveFilters: function () {
            this.balanceArchiveFiltersOpen = true;
            this.balanceArchiveFilters = {};
            this.saveBalanceArchiveFilterState();
            var $pool = $('#balance-archive-pool-select2');
            if ($pool.length && $pool.data('select2')) $pool.val(null).trigger('change');
            var $account = $('#balance-archive-account-select2');
            if ($account.length && $account.data('select2')) $account.val(null).trigger('change');
            var $currency = $('#balance-archive-currency-select2');
            if ($currency.length && $currency.data('select2')) $currency.val(null).trigger('change');
            this.loadBalanceArchive(true, true);
        },

        activeBalanceArchiveFilterCount: function () {
            return Object.keys(this.balanceArchiveFilters).filter(function (k) {
                return !!this.balanceArchiveFilters[k];
            }, this).length;
        },

        saveBalanceArchiveFilterState: function () {
            StateStorage.remove('balance_archive_filters');
        },

        toggleBalanceArchiveFilters: function () {
            if (this.balanceArchiveFiltersOpen) {
                this.destroyBalanceArchiveFilterSelect2();
                this.balanceArchiveFiltersOpen = false;
                return;
            }
            this.balanceArchiveFiltersOpen = true;
            this._balanceArchivePoolSelect2Inited = false;
            this._balanceArchiveAccountSelect2Inited = false;
            this._balanceArchiveCurrencySelect2Inited = false;
            var self = this;
            this.$nextTick(function () {
                self.initBalanceArchivePoolSelect2();
                self.initBalanceArchiveAccountSelect2();
                self.initBalanceArchiveCurrencySelect2();
            });
        },

        destroyBalanceArchiveFilterSelect2: function () {
            var $pool = $('#balance-archive-pool-select2');
            if ($pool.length && $pool.data('select2')) {
                $pool.off('select2:select select2:clear');
                $pool.select2('destroy');
            }

            var $account = $('#balance-archive-account-select2');
            if ($account.length && $account.data('select2')) {
                $account.off('select2:select select2:clear');
                $account.select2('destroy');
            }

            var $currency = $('#balance-archive-currency-select2');
            if ($currency.length && $currency.data('select2')) {
                $currency.off('change.balanceArchiveCurrency');
                $currency.select2('destroy');
            }

            this._balanceArchivePoolSelect2Inited = false;
            this._balanceArchiveAccountSelect2Inited = false;
            this._balanceArchiveCurrencySelect2Inited = false;
        },

        initBalanceArchivePoolSelect2: function () {
            var self = this;
            var $el = $('#balance-archive-pool-select2');
            if (!$el.length || self._balanceArchivePoolSelect2Inited) return;
            self._balanceArchivePoolSelect2Inited = true;

            $el.select2({
                theme: 'bootstrap-5',
                placeholder: 'Все банки...',
                allowClear: true,
                data: (self.balanceArchivePools || []).map(function (p) {
                    return { id: String(p.id), text: p.name };
                }),
                language: { noResults: function () { return 'Нет ностро-банков'; } }
            });
            $el.val(self.balanceArchiveFilters.account_pool_id || null).trigger('change.select2');

            $el.on('select2:select', function (e) {
                self.applyBalanceArchiveFilter('account_pool_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearBalanceArchiveFilter('account_pool_id');
            });
        },

        initBalanceArchiveAccountSelect2: function () {
            var self = this;
            var $el = $('#balance-archive-account-select2');
            if (!$el.length || self._balanceArchiveAccountSelect2Inited) return;
            self._balanceArchiveAccountSelect2Inited = true;

            $el.select2({
                theme: 'bootstrap-5',
                placeholder: 'Все счета...',
                allowClear: true,
                data: (self.balanceArchiveAccounts || []).map(function (a) {
                    return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                }),
                language: { noResults: function () { return 'Нет счетов'; } }
            });
            $el.val(self.balanceArchiveFilters.account_id || null).trigger('change.select2');

            $el.on('select2:select', function (e) {
                self.applyBalanceArchiveFilter('account_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearBalanceArchiveFilter('account_id');
            });
        },

        initBalanceArchiveCurrencySelect2: function () {
            var self = this;
            var $el = $('#balance-archive-currency-select2');
            if (!$el.length || self._balanceArchiveCurrencySelect2Inited) return;
            self._balanceArchiveCurrencySelect2Inited = true;

            $el.select2({
                theme: 'bootstrap-5',
                placeholder: 'Все валюты...',
                allowClear: true,
                closeOnSelect: false,
                data: (self.dictCurrencies || []).map(function (c) {
                    return { id: c.code, text: c.code };
                }),
                language: { noResults: function () { return 'Нет валют'; } }
            });

            var current = self.balanceArchiveFilters.currency;
            if (current) {
                $el.val(Array.isArray(current) ? current : [current]).trigger('change.select2');
            }

            $el.off('change.balanceArchiveCurrency').on('change.balanceArchiveCurrency', function () {
                self.applyBalanceArchiveFilter('currency', $el.val() || []);
            });
        },

        loadBalanceArchiveAccounts: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.balanceArchiveAccounts, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.balanceArchiveAccounts = r.data.data || [];
                    self.balanceArchivePools = r.data.pools || [];
                }
            });
        },

        loadBalanceArchiveStats: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.balanceArchiveStats, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.balanceArchiveStats = r.data.data;
                    self.balanceArchiveSavedSettings = self.cloneBalanceArchiveSettings(r.data.data.settings);
                    self.balanceArchiveSettings = self.cloneBalanceArchiveSettings(self.balanceArchiveSavedSettings);
                }
            });
        },

        runBalanceArchive: function () {
            var self = this;
            Swal.fire({
                title: 'Запустить архивирование балансов?',
                html: 'Балансы со статусом normal/confirmed старше <strong>' +
                    self.balanceArchiveSettings.archive_after_days +
                    ' дней</strong> по дате валютирования будут перенесены в архив.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Да, архивировать',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#4f46e5',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                self._startProgressBalanceArchive();
            });
        },

        _startProgressBalanceArchive: function () {
            var self = this;
            self.balanceArchiveRunning = true;
            self.balanceArchiveProgressDone = 0;
            self.balanceArchiveProgressAll = 0;
            self.balanceArchiveProgressPct = 0;
            self.balanceArchiveProgressOpen = true;

            SmartMatchApi.post(AppRoutes.balanceArchiveCount, {}).then(function (d) {
                if (!d.success) {
                    self._closeProgressBalanceArchive();
                    Swal.fire('Ошибка', d.message || 'Не удалось начать', 'error');
                    return;
                }
                if (d.total === 0) {
                    self._closeProgressBalanceArchive();
                    Swal.fire('Готово', 'Нет балансов для архивирования', 'info');
                    return;
                }
                self.balanceArchiveProgressAll = d.total;
                self._runNextBalanceArchiveBatch(0, d.total);
            }).catch(function () {
                self._closeProgressBalanceArchive();
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            });
        },

        _runNextBalanceArchiveBatch: function (totalDone, totalAll) {
            var self = this;
            SmartMatchApi.post(AppRoutes.balanceArchiveRunBatch, {
                total_done: totalDone,
                total_all: totalAll,
            }).then(function (d) {
                if (!d.success) {
                    self._closeProgressBalanceArchive();
                    Swal.fire('Ошибка', d.message || 'Ошибка архивирования', 'error');
                    return;
                }

                self.balanceArchiveProgressDone = d.total_done;
                self.balanceArchiveProgressPct = d.percent || 0;

                if (d.is_finished) {
                    self._closeProgressBalanceArchive();
                    self.loadBalanceArchive(true);
                    self.loadBalanceArchiveStats();
                    Swal.fire({
                        title: 'Архивирование завершено',
                        html: 'Заархивировано балансов: <strong>' + d.total_done + '</strong>',
                        icon: 'success',
                        confirmButtonColor: '#4f46e5',
                    });
                } else {
                    setTimeout(function () {
                        self._runNextBalanceArchiveBatch(d.total_done, d.total_all);
                    }, 50);
                }
            }).catch(function (err) {
                self._closeProgressBalanceArchive();
                Swal.fire('Ошибка', 'Ошибка соединения: ' + (err.message || ''), 'error');
            });
        },

        _closeProgressBalanceArchive: function () {
            this.balanceArchiveRunning = false;
            this.balanceArchiveProgressOpen = false;
        },

        purgeExpiredBalanceArchive: function () {
            var self = this;
            Swal.fire({
                title: 'Удалить просроченные балансы?',
                text: 'Архивные балансы с истёкшим сроком хранения будут удалены безвозвратно.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Удалить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                self.balanceArchivePurging = true;
                SmartMatchApi.post(AppRoutes.balanceArchivePurgeExpired, {}).then(function (d) {
                    if (d.success) {
                        Swal.fire('Готово', d.message, 'success');
                        self.loadBalanceArchive(true);
                        self.loadBalanceArchiveStats();
                    } else {
                        Swal.fire('Ошибка', d.message || 'Не удалось очистить архив', 'error');
                    }
                }).finally(function () {
                    self.balanceArchivePurging = false;
                });
            });
        },

        restoreBalanceFromArchive: function (row) {
            var self = this;
            Swal.fire({
                title: 'Восстановить баланс?',
                html: self.escapeBalanceArchiveHtml(self.balanceArchiveRowText(row)),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Восстановить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#059669',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(AppRoutes.balanceArchiveRestore, { id: row.id }).then(function (d) {
                    if (d.success) {
                        Swal.fire('Восстановлено', d.message || 'Баланс восстановлен', 'success');
                        self.loadBalanceArchive(true);
                        self.loadBalanceArchiveStats();
                    } else {
                        Swal.fire('Ошибка', d.message || 'Не удалось восстановить баланс', 'error');
                    }
                }).catch(function () {
                    Swal.fire('Ошибка', 'Ошибка соединения', 'error');
                });
            });
        },

        balanceArchiveRowText: function (row) {
            return [
                row.account_name || '—',
                row.ls_type || '—',
                row.currency || '—',
                row.value_date_fmt || row.value_date || '—',
                'Opening ' + this.formatBalanceArchiveAmount(row.opening_balance, row.currency) + ' ' + (row.opening_dc || ''),
                'Closing ' + this.formatBalanceArchiveAmount(row.closing_balance, row.currency) + ' ' + (row.closing_dc || ''),
            ].join(' | ');
        },

        formatBalanceArchiveAmount: function (amount, currency) {
            return this.formatAmount(amount, currency);
        },

        escapeBalanceArchiveHtml: function (value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        showBalanceArchiveHistory: function (row) {
            var self = this;
            self.balanceArchiveHistoryBalance = row;
            self.balanceArchiveHistoryLogs = [];
            self.balanceArchiveHistoryOpen = true;
            self.balanceArchiveHistoryLoading = true;

            SmartMatchApi.get(AppRoutes.balanceArchiveHistory, { id: row.id }).then(function (r) {
                if (r.data && r.data.success) {
                    self.balanceArchiveHistoryLogs = r.data.data || [];
                }
            }).finally(function () {
                self.balanceArchiveHistoryLoading = false;
            });
        },

        closeBalanceArchiveHistory: function () {
            this.balanceArchiveHistoryOpen = false;
        },

        cloneBalanceArchiveSettings: function (settings) {
            settings = settings || {};
            var hasAutoArchive = Object.prototype.hasOwnProperty.call(settings, 'auto_archive_enabled');
            return {
                archive_after_days: Number(settings.archive_after_days || 90),
                retention_years: Number(settings.retention_years || 5),
                auto_archive_enabled: hasAutoArchive ? !!settings.auto_archive_enabled : true,
            };
        },

        openBalanceArchiveSettings: function () {
            this.balanceArchiveSettings = this.cloneBalanceArchiveSettings(this.balanceArchiveSavedSettings);
            this.balanceArchiveSettingsOpen = true;
        },

        closeBalanceArchiveSettings: function () {
            this.balanceArchiveSettings = this.cloneBalanceArchiveSettings(this.balanceArchiveSavedSettings);
            this.balanceArchiveSettingsOpen = false;
        },

        saveBalanceArchiveSettings: function () {
            var self = this;
            self.balanceArchiveSettingsSaving = true;
            SmartMatchApi.post(AppRoutes.balanceArchiveSaveSettings, self.balanceArchiveSettings).then(function (d) {
                if (d.success) {
                    self.balanceArchiveSavedSettings = self.cloneBalanceArchiveSettings(d.data || self.balanceArchiveSettings);
                    self.balanceArchiveSettings = self.cloneBalanceArchiveSettings(self.balanceArchiveSavedSettings);
                    Swal.fire({ title: 'Сохранено', icon: 'success', timer: 1400, showConfirmButton: false });
                    self.balanceArchiveSettingsOpen = false;
                    self.loadBalanceArchiveStats();
                } else {
                    Swal.fire('Ошибка', d.message || 'Не удалось сохранить настройки', 'error');
                }
            }).finally(function () {
                self.balanceArchiveSettingsSaving = false;
            });
        },

        balanceArchiveStatusLabel: function (status) {
            if (status === 'confirmed') return 'Подтв.';
            if (status === 'error') return 'Ошибка';
            return 'Норма';
        },

        balanceArchiveHistoryActionLabel: function (action) {
            var labels = {
                import: 'Импорт',
                edit: 'Изменено',
                confirm: 'Подтверждено',
                archive: 'Заархивировано',
                restore: 'Восстановлено',
            };
            return labels[action] || action;
        },

        balanceArchiveColVisible: function (key) {
            var col = this.balanceArchiveTableColumns.find(function (c) { return c.key === key; });
            return col ? col.visible : true;
        },

        balanceArchiveColByKey: function (key) {
            return this.balanceArchiveTableColumns.find(function (c) { return c.key === key; });
        },

        toggleBalanceArchiveColsDropdown: function () {
            this.showBalanceArchiveColsDropdown = !this.showBalanceArchiveColsDropdown;
        },

        startBalanceArchiveColResize: function (e, col) {
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
                document.body.style.cursor = '';
                document.body.classList.remove('resizing-col');
            };
            document.body.style.userSelect = 'none';
            document.body.style.cursor = 'col-resize';
            document.body.classList.add('resizing-col');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        loadBalanceArchiveTableColumnsPrefs: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.userPreferenceGet, { key: 'balance_archive_table_columns' })
                .then(function (response) {
                    var r = response.data !== undefined ? response.data : response;
                    if (r && r.success && Array.isArray(r.value)) {
                        var saved = {};
                        r.value.forEach(function (c) {
                            if (c && typeof c.key === 'string') saved[c.key] = c;
                        });
                        self.balanceArchiveTableColumns.forEach(function (col) {
                            var s = saved[col.key];
                            if (!s) return;
                            if (typeof s.visible === 'boolean') col.visible = s.visible;
                            if (typeof s.width === 'number' && s.width >= 40) col.width = s.width;
                        });
                    }
                })
                .catch(function () {})
                .then(function () {
                    self.$nextTick(function () { self._balanceArchiveTableColumnsLoaded = true; });
                });
        },

        saveBalanceArchiveTableColumnsPrefs: function () {
            if (!this._balanceArchiveTableColumnsLoaded) return;
            var self = this;
            if (self._balanceArchiveColsSaveTimer) clearTimeout(self._balanceArchiveColsSaveTimer);
            self._balanceArchiveColsSaveTimer = setTimeout(function () {
                SmartMatchApi.post(AppRoutes.userPreferenceSave, {
                    key: 'balance_archive_table_columns',
                    value: self.balanceArchiveTableColumns.map(function (c) {
                        return { key: c.key, visible: !!c.visible, width: c.width };
                    })
                });
            }, 600);
        },

        _initBalanceArchiveColManagement: function () {
            var self = this;
            document.addEventListener('click', function (e) {
                if (!self.showBalanceArchiveColsDropdown) return;
                if (e.target.closest && (e.target.closest('.col-mgr-dropdown') || e.target.closest('[data-balance-archive-col-toggle]'))) return;
                self.showBalanceArchiveColsDropdown = false;
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') self.showBalanceArchiveColsDropdown = false;
            });
        }
    }
};
