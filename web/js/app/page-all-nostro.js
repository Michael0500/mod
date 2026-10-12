/**
 * Стартер Vue-страницы "Выверка по всем ностро-банкам" (`#all-nostro-app`).
 *
 * Использует общие mixins выверки: модалки, таблица записей и
 * ручное/автоматическое квитование. Сайдбара с категориями нет — фильтр
 * по ностро-банку и счёту выбирается в Select2 в панели фильтров и в форме
 * добавления записи.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('all-nostro-app');
        if (!root) return;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        // Используем JSON-payload — SmartMatchApi.post шлёт объекты, а серверные
        // контроллеры читают тело через Yii::$app->request->post() с JSON-parser'ом.
        axios.defaults.transformRequest = [function (data) { return JSON.stringify(data); }];
        axios.defaults.headers.post['Content-Type'] = 'application/json';

        var INIT = (typeof window.AllNostroInit === 'object' && window.AllNostroInit) || {};

        new Vue({
            el: '#all-nostro-app',

            mixins: [ModalsMixin, EntriesMixin, MatchingMixin],

            data: {
                // Список ностро-банков компании для фильтра и формы добавления.
                // `accountPools` имя из EntriesMixin — заполняется загрузкой,
                // дополнительно держим `pools` (тот же массив) для совместимости со старым шаблоном.
                pools: INIT.pools || [],

                // Mock сайдбарных полей, на которые опираются общие mixins.
                selectedPool:     null,
                selectedCategory: null,

                // Контекстное меню строки (используется в _matching-modals.php).
                openRowMenu:  null,
                rowMenuStyle: {},

                _poolsFilterSelect2Inited:    false,
                _accountFilterSelect2Inited:  false,
                _filterCurrencySelect2Inited: false,

                // ID Select2-фильтра валют (используется EntriesMixin)
                currencyFilterSelectId: 'an-filter-currency'
            },

            mounted: function () {
                var self = this;
                self.accountPools = (INIT.pools || []).map(function (p) {
                    return { id: p.id, name: p.name };
                });
                self.pools = self.accountPools;

                // Предустановленный фильтр по номеру пакета (переход со страницы отката).
                if (INIT.batchId) {
                    self.$set(self.filters, 'batch_id', parseInt(INIT.batchId, 10));
                    self.filtersOpen = true;
                }

                self._initColManagement();
                self.loadTableColumnsPrefs();
                self.loadAllNostroEntries(true);

                self.$nextTick(function () {
                    self.initAllNostroPoolsSelect2();
                    self.initAllNostroAccountSelect2();
                    // Панель фильтров открыта программно (переход с номером пакета) —
                    // поднимаем Select2 валют, как это делает toggleFiltersPanel.
                    if (self.filtersOpen) {
                        self.initFilterCurrencySelect2();
                    }
                });

                document.addEventListener('click', function () { self.openRowMenu = null; });
            },

            methods: {
                /**
                 * Контекстное меню строки таблицы (нужно для _matching-modals.php).
                 *
                 * @param {string} type Тип сущности меню.
                 * @param {number|string} id Идентификатор строки.
                 * @param {MouseEvent} event Событие клика.
                 * @returns {void}
                 */
                toggleRowMenu: function (type, id, event) {
                    var key = type + '-' + id;
                    if (this.openRowMenu === key) {
                        this.openRowMenu = null;
                        return;
                    }
                    var rect = event.currentTarget.getBoundingClientRect();
                    this.rowMenuStyle = {
                        top:  (rect.bottom + 4) + 'px',
                        left: (rect.right - 150) + 'px'
                    };
                    this.openRowMenu = key;
                },

                /**
                 * Перехват `loadEntries` из EntriesMixin: на этой странице нет
                 * `selectedPool`, поэтому используем `/all-nostro/list`.
                 *
                 * @param {boolean} reset Сбрасывать ли пагинацию и выбор.
                 * @returns {void}
                 */
                loadEntries: function (reset) {
                    this.loadAllNostroEntries(reset);
                },

                /**
                 * Загружает страницу записей со всех ностро-банков компании.
                 *
                 * @param {boolean} reset Сбрасывать ли пагинацию и выбор.
                 * @returns {void}
                 */
                loadAllNostroEntries: function (reset) {
                    if (reset) {
                        this.entries          = [];
                        this.entriesPage      = 1;
                        this.selectedIds      = [];
                        this.selectionSummary = null;
                    }
                    var self = this;
                    var isFirst = self.entriesPage === 1;
                    if (isFirst) self.entriesLoading = true;
                    else self.entriesLoadingMore = true;

                    axios.get(window.AllNostroRoutes.list, {
                        params: {
                            page:    self.entriesPage,
                            limit:   self.entriesLimit,
                            sorts:   JSON.stringify(self.sorts),
                            filters: JSON.stringify(self.filters)
                        }
                    }).then(function (response) {
                        var r = response.data;
                        if (r && r.success) {
                            self.entries      = reset ? r.data : self.entries.concat(r.data);
                            self.entriesTotal = r.total;
                        }
                    }).catch(function () { /* no-op */ })
                    .then(function () {
                        self.entriesLoading     = false;
                        self.entriesLoadingMore = false;
                    });
                },

                /**
                 * Перехват `clearAllFilters` — на /all-nostro фильтры Select2
                 * имеют свои id, поэтому сбрасываем их явно.
                 *
                 * @returns {void}
                 */
                clearAllFilters: function () {
                    this.filters = {};
                    var $p = $('#an-filter-pools');
                    if ($p.length && $p.data('select2')) $p.val(null).trigger('change');
                    var $a = $('#an-filter-account');
                    if ($a.length && $a.data('select2')) $a.val(null).trigger('change');
                    var $c = $('#an-filter-currency');
                    if ($c.length && $c.data('select2')) $c.val(null).trigger('change');
                    this.loadEntries(true);
                },

                /**
                 * Перехват `toggleFiltersPanel` — отключаем дефолтные Select2 из
                 * EntriesMixin (они смотрят на DOM id главной страницы выверки).
                 *
                 * @returns {void}
                 */
                toggleFiltersPanel: function () {
                    var self = this;
                    self.filtersOpen = !self.filtersOpen;
                    if (self.filtersOpen) {
                        self.$nextTick(function () { self.initFilterCurrencySelect2(); });
                    }
                },

                /**
                 * Инициализирует Select2 мультивыбора ностро-банков для /all-nostro.
                 *
                 * @returns {void}
                 */
                initAllNostroPoolsSelect2: function () {
                    var self = this;
                    var $el = $('#an-filter-pools');
                    if (!$el.length || self._poolsFilterSelect2Inited) return;
                    self._poolsFilterSelect2Inited = true;

                    var data = (self.pools || []).map(function (p) {
                        return { id: String(p.id), text: p.name };
                    });

                    $el.select2({
                        theme:       'bootstrap-5',
                        placeholder: 'Все ностро-банки...',
                        allowClear:  true,
                        data:        data,
                        language: { noResults: function () { return 'Нет ностро-банков'; } }
                    });

                    $el.on('change', function () {
                        var vals = $el.val() || [];
                        if (vals.length === 0) {
                            self.$delete(self.filters, 'pool_ids');
                        } else {
                            self.$set(self.filters, 'pool_ids', vals.map(function (v) { return parseInt(v, 10); }));
                        }
                        // Список счетов мог стать невалидным — сбрасываем
                        var $a = $('#an-filter-account');
                        if ($a.length && $a.data('select2')) $a.val(null).trigger('change');
                        self.$delete(self.filters, 'account_id');

                        self.loadEntries(true);
                    });
                },

                /**
                 * Инициализирует Select2 выбора счёта для /all-nostro.
                 *
                 * @returns {void}
                 */
                initAllNostroAccountSelect2: function () {
                    var self = this;
                    var $el = $('#an-filter-account');
                    if (!$el.length || self._accountFilterSelect2Inited) return;
                    self._accountFilterSelect2Inited = true;

                    $el.select2({
                        theme:              'bootstrap-5',
                        placeholder:        'Все счета...',
                        allowClear:         true,
                        minimumInputLength: 0,
                        ajax: {
                            url:      window.AllNostroRoutes.searchAccounts,
                            dataType: 'json',
                            delay:    200,
                            data:     function (p) {
                                var req = { q: p.term || '' };
                                var poolIds = (self.filters.pool_ids || []);
                                if (poolIds.length) {
                                    req['pool_ids'] = poolIds;
                                }
                                return req;
                            },
                            processResults: function (d) { return d; },
                            cache: false
                        },
                        templateResult: function (item) {
                            if (item.loading) return item.text;
                            var tag = item.currency
                                ? '<span style="background:#e0e7ff;color:#4338ca;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;margin-left:5px">' + item.currency + '</span>'
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
                }
            }
        });
    });
})();
