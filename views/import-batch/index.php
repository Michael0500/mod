<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Откат загруженных данных — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<div id="imports-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#0ea5e9,#6366f1);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-file-import" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Откат загруженных данных</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">Пачки загрузок и откат</div>
            </div>
        </div>
        <button class="btn-action btn-primary-violet" @click="reload()" :disabled="busy">
            <i class="fas" :class="loading ? 'fa-spinner fa-spin' : 'fa-sync-alt'"></i>
            <span class="ms-1">Обновить</span>
        </button>
    </div>

    <div class="sm-card" style="padding:0;overflow:hidden">
        <table class="imp-table">
            <thead>
            <tr>
                <th style="width:80px">Пакет №</th>
                <th>Тип</th>
                <th>Дата</th>
                <th>Счёт / файл</th>
                <th style="text-align:center">Записей</th>
                <th style="text-align:center">Балансов</th>
                <th style="text-align:center">Статус</th>
                <th style="width:150px;text-align:center">Перейти</th>
                <th style="width:140px"></th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="b in batches" :key="b.id">
                <td style="color:#9ca3af">{{ b.id }}</td>
                <td><span class="imp-badge">{{ b.type_label }}</span></td>
                <td style="white-space:nowrap;color:#6b7280">{{ fmtDateTime(b.date_time) }}</td>
                <td>
                    <div v-if="b.account_name" style="font-weight:600">{{ b.account_name }}</div>
                    <div v-if="b.source_label" style="font-size:11px;color:#9ca3af">{{ b.source_label }}</div>
                    <span v-if="!b.account_name && !b.source_label" style="color:#d1d5db">—</span>
                </td>
                <td style="text-align:center">
                    <span :title="'В системе сейчас: ' + b.live_entries">{{ displayCount(b.imported_entries, b.live_entries) }}</span>
                </td>
                <td style="text-align:center">
                    <span :title="'В системе сейчас: ' + b.live_balances">{{ displayCount(b.imported_balances, b.live_balances) }}</span>
                </td>
                <td style="text-align:center">
                    <span v-if="b.is_processing" class="imp-status imp-status-proc">
                        <i class="fas fa-spinner fa-spin"></i> Выполняется ({{ b.processing_owner === 'manual' ? 'вручную' : 'фоном' }})
                    </span>
                    <span v-else-if="b.is_rolled_back" class="imp-status imp-status-rolled" :title="'Откатано: ' + fmtDateTime(b.rolled_back_at)">
                        <i class="fas fa-undo"></i> Откатано
                    </span>
                    <span v-else-if="b.is_partially_rolled_back" class="imp-status imp-status-partial">
                        <i class="fas fa-undo"></i> Откатана частично
                    </span>
                    <span v-else-if="hasMissingAccounts(b)" class="imp-status imp-status-partial">
                        <i class="fas fa-exclamation-triangle"></i> Загружено частично
                    </span>
                    <span v-else-if="!b.is_merged && hasData(b)" class="imp-status imp-status-ok">
                        <i class="fas fa-check"></i> Импортировано
                    </span>
                    <span v-else-if="!b.is_merged" class="imp-status imp-status-pending">
                        <i class="fas fa-clock"></i> Ожидает загрузки
                    </span>
                    <span v-else-if="b.matched > 0" class="imp-status imp-status-locked">
                        <i class="fas fa-link"></i> Есть матчи ({{ b.matched }})
                    </span>
                    <span v-else-if="b.archived > 0" class="imp-status imp-status-locked">
                        <i class="fas fa-archive"></i> В архиве ({{ b.archived }})
                    </span>
                    <span v-else class="imp-status imp-status-ok">
                        <i class="fas fa-check"></i> Импортировано
                    </span>
                    <div v-if="b.rolled_back_accounts" class="imp-current">
                        Откатано счетов: {{ b.rolled_back_accounts }}.<br>
                        Осталось записей: {{ b.live_entries }}, балансов: {{ b.live_balances }}
                    </div>
                    <div v-if="b.skipped_accounts && b.skipped_accounts.length" class="imp-missing"
                         :title="'Этих счетов нет в системе — их строки не загружены:\n' + b.skipped_accounts.join('\n')">
                        <i class="fas fa-triangle-exclamation"></i>
                        Нет счетов в системе ({{ b.skipped_accounts.length }}): {{ missingAccountsShort(b.skipped_accounts) }}
                    </div>
                </td>
                <td style="text-align:center;white-space:nowrap">
                    <a class="imp-link" :href="entriesUrl(b.id)" title="Открыть записи пакета в выверке по всем банкам">
                        <i class="fas fa-list-ul"></i> Выверка
                    </a>
                    <a class="imp-link" :href="balanceUrl(b.id)" title="Открыть балансы пакета в балансе по всем банкам">
                        <i class="fas fa-balance-scale"></i> Баланс
                    </a>
                </td>
                <td style="text-align:right">
                    <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                        <button v-if="b.can_load && (!hasData(b) || hasMissingAccounts(b))" class="btn-action btn-load" @click="confirmLoad(b)" :disabled="busy">
                            <i class="fas" :class="loadingId === b.id ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                            <span class="ms-1">{{ hasData(b) ? 'Догрузить' : 'Запустить загрузку' }}</span>
                        </button>
                        <button v-if="b.can_rollback" class="btn-action btn-danger-soft" @click="confirmRollback(b)" :disabled="busy">
                            <i class="fas" :class="rollingId === b.id ? 'fa-spinner fa-spin' : 'fa-undo'"></i>
                            <span class="ms-1">Откатить пачку</span>
                        </button>
                        <button class="btn-action btn-load" @click="openAccounts(b)" :disabled="busy">По счетам</button>
                        <span v-if="!b.can_load && !b.can_rollback" style="font-size:11px;color:#9ca3af">{{ (!b.is_merged ? b.load_reason : b.reason) || '—' }}</span>
                    </div>
                </td>
            </tr>
            <tr v-if="!batches.length">
                <td colspan="9" style="text-align:center;color:#9ca3af;padding:30px">Пачек импорта пока нет</td>
            </tr>
            </tbody>
        </table>
        <div class="imp-pagination">
            <div>Показано {{ pageFrom }}–{{ pageTo }} из {{ total }}</div>
            <div class="imp-pagination-controls">
                <label for="imports-page-size">На странице:</label>
                <select id="imports-page-size" class="form-select form-select-sm" style="width:auto"
                        v-model.number="selectedPageSize" @change="reload(1)" :disabled="busy">
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
                <button class="btn-action" @click="reload(1)" :disabled="busy || page <= 1" aria-label="Первая страница">«</button>
                <button class="btn-action" @click="reload(page - 1)" :disabled="busy || page <= 1" aria-label="Предыдущая страница">‹</button>
                <span>Страница {{ page }} из {{ pageCount }}</span>
                <button class="btn-action" @click="reload(page + 1)" :disabled="busy || page >= pageCount" aria-label="Следующая страница">›</button>
                <button class="btn-action" @click="reload(pageCount)" :disabled="busy || page >= pageCount" aria-label="Последняя страница">»</button>
            </div>
        </div>
    </div>

    <div v-if="accountBatch" class="imp-modal-backdrop" @click.self="closeAccounts" @keydown.esc="closeAccounts">
        <section class="imp-modal" role="dialog" aria-modal="true" aria-labelledby="imp-accounts-title" tabindex="-1">
            <div class="imp-modal-header">
                <h2 id="imp-accounts-title">Откат по счёту · Пачка #{{ accountBatch.id }}</h2>
                <button class="btn-action" @click="closeAccounts" :disabled="rollingAccountId !== null" aria-label="Закрыть">×</button>
            </div>
            <p>Выберите счёт. Будут удалены его записи и балансы только из этой пачки.</p>
            <form class="imp-account-search" @submit.prevent="loadAccounts(1)">
                <input class="form-control form-control-sm" aria-label="Поиск счёта" placeholder="Номер или название счёта"
                       v-model="accountSearch" :disabled="accountsLoading || rollingAccountId !== null">
                <button class="btn-action btn-load" :disabled="accountsLoading || rollingAccountId !== null">Найти</button>
            </form>
            <div v-if="accountsError" role="alert" class="text-danger">{{ accountsError }}</div>
            <div v-if="accountsLoading" role="status">Загрузка счетов…</div>
            <div class="imp-account-table">
                <table class="imp-table">
                    <thead><tr><th>Счёт</th><th>Записей</th><th>Балансов</th><th>Состояние / история</th><th></th></tr></thead>
                    <tbody>
                    <tr v-for="a in batchAccounts" :key="a.account_id">
                        <td>{{ a.account_name }}</td><td>{{ a.live_entries }}</td><td>{{ a.live_balances }}</td>
                        <td>
                            <template v-if="a.rolled_back_at">
                                Откатано {{ fmtDateTime(a.rolled_back_at) }} · {{ a.rolled_back_user }}<br>
                                Удалено записей: {{ a.deleted_entries }}, балансов: {{ a.deleted_balances }}
                            </template>
                            <template v-else>{{ a.reason || 'Доступен откат' }}</template>
                        </td>
                        <td><button v-if="a.can_rollback" class="btn-action btn-danger-soft" @click="confirmAccountRollback(a)"
                                    :disabled="accountsLoading || rollingAccountId !== null">
                            {{ rollingAccountId === a.account_id ? 'Откат…' : 'Откатить' }}
                        </button></td>
                    </tr>
                    <tr v-if="!batchAccounts.length && !accountsLoading"><td colspan="5">Счета не найдены</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="imp-pagination">
                <span>Счетов: {{ accountsTotal }} · Страница {{ accountsPage }} из {{ accountsPageCount }}</span>
                <div class="imp-pagination-controls">
                    <button class="btn-action" @click="loadAccounts(accountsPage - 1)" :disabled="accountsLoading || rollingAccountId !== null || accountsPage <= 1">Назад</button>
                    <button class="btn-action" @click="loadAccounts(accountsPage + 1)" :disabled="accountsLoading || rollingAccountId !== null || accountsPage >= accountsPageCount">Далее</button>
                </div>
            </div>
        </section>
    </div>
</div>

<style>
.imp-table { width:100%; border-collapse:collapse; font-size:13px; }
.imp-table th {
    padding:10px 12px; font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.04em; color:#6b7280; border-bottom:2px solid #e5e7eb; background:#f9fafb; white-space:nowrap;
}
.imp-table td { padding:9px 12px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:middle; }
.imp-table tr:last-child td { border-bottom:none; }
.imp-table tr:hover td { background:#fafbff; }

.imp-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:700;
    background:#e0e7ff; color:#3730a3; font-family:monospace; }

.imp-status { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:10px; }
.imp-status-ok      { background:#dcfce7; color:#166534; }
.imp-status-locked  { background:#fef3c7; color:#92400e; }
.imp-status-rolled  { background:#f3f4f6; color:#6b7280; }
.imp-status-pending { background:#e0e7ff; color:#3730a3; }
.imp-status-proc    { background:#dbeafe; color:#1d4ed8; }
.imp-status-partial { background:#ffedd5; color:#9a3412; }

.imp-missing { margin-top:5px; font-size:10.5px; font-weight:600; color:#b45309; line-height:1.35;
    max-width:260px; margin-left:auto; margin-right:auto; cursor:help; }
.imp-missing i { margin-right:3px; }

.btn-load { background:#eef2ff; color:#4338ca; border:none; border-radius:8px; padding:5px 12px; font-size:12px; font-weight:600; cursor:pointer; }
.btn-load:hover { background:#e0e7ff; }
.btn-load:disabled { opacity:.6; cursor:default; }

.btn-danger-soft { background:#fee2e2; color:#b91c1c; border:none; border-radius:8px; padding:5px 12px; font-size:12px; font-weight:600; cursor:pointer; }
.btn-danger-soft:hover { background:#fecaca; }
.btn-danger-soft:disabled { opacity:.6; cursor:default; }

.imp-link { display:inline-block; font-size:11px; font-weight:600; color:#4f46e5; text-decoration:none; padding:2px 6px; border-radius:6px; white-space:nowrap; }
.imp-link:hover { background:#eef2ff; text-decoration:none; }
.imp-link + .imp-link { margin-left:2px; }
.imp-pagination { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    padding:12px; border-top:1px solid #e5e7eb; font-size:12px; color:#6b7280; }
.imp-pagination-controls { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.imp-pagination-controls button:disabled { opacity:.45; cursor:default; }
.imp-current { margin-top:5px; font-size:11px; color:#6b7280; }
.imp-modal-backdrop { position:fixed; inset:0; z-index:1050; background:rgba(15,23,42,.4); display:flex; align-items:center; justify-content:center; padding:20px; }
.imp-modal { width:1000px; max-width:100%; max-height:90vh; overflow:auto; background:white; border-radius:14px; padding:20px; box-shadow:0 20px 60px #0003; }
.imp-modal-header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.imp-modal h2 { font-size:18px; margin:0; }
.imp-modal p { margin:12px 0; font-size:13px; color:#6b7280; }
.imp-account-search { display:flex; gap:8px; margin-bottom:14px; }
.imp-account-table { overflow-x:auto; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var _init = <?= $initJson ?>;

    new Vue({
        el: '#imports-app',

        data: function () {
            return {
                batches:   _init.batches || [],
                page:      (_init.pagination || {}).page || 1,
                pageSize:  (_init.pagination || {}).page_size || 25,
                selectedPageSize: (_init.pagination || {}).page_size || 25,
                pageCount: (_init.pagination || {}).page_count || 1,
                total:     (_init.pagination || {}).total || 0,
                loading:   false,
                rollingId: null,
                loadingId: null,
                accountBatch: null,
                batchAccounts: [],
                accountSearch: '',
                accountsPage: 1,
                accountsPageCount: 1,
                accountsTotal: 0,
                accountsLoading: false,
                accountsError: '',
                accountsRequest: 0,
                rollingAccountId: null,
            };
        },

        computed: {
            busy: function () { return this.loading || this.rollingId !== null || this.loadingId !== null || this.accountsLoading || this.rollingAccountId !== null; },
            pageFrom: function () { return this.total ? (this.page - 1) * this.pageSize + 1 : 0; },
            pageTo: function () { return Math.min(this.page * this.pageSize, this.total); },
        },

        methods: {
            openAccounts: function (batch) {
                if (this.busy) return;
                this.accountBatch = batch;
                this.batchAccounts = [];
                this.accountSearch = '';
                this.accountsPage = 1;
                this.accountsPageCount = 1;
                this.accountsTotal = 0;
                return this.loadAccounts(1);
            },
            closeAccounts: function () {
                if (this.rollingAccountId !== null) return;
                this.accountsRequest++;
                this.accountBatch = null;
                this.accountsLoading = false;
            },
            loadAccounts: function (page) {
                var self = this;
                if (!self.accountBatch || self.accountsLoading) return;
                var request = ++self.accountsRequest;
                self.accountsLoading = true;
                self.accountsError = '';
                return axios.get('<?= Url::to(['/import-batch/accounts']) ?>', {
                    params: { id: self.accountBatch.id, q: self.accountSearch, page: page || 1, page_size: 25 },
                }).then(function (res) {
                    if (request !== self.accountsRequest) return;
                    if (!res.data.success) throw new Error(res.data.message);
                    self.batchAccounts = res.data.data;
                    self.accountsPage = res.data.pagination.page;
                    self.accountsPageCount = res.data.pagination.page_count;
                    self.accountsTotal = res.data.pagination.total;
                }).catch(function (error) {
                    if (request !== self.accountsRequest) return;
                    self.batchAccounts = [];
                    self.accountsError = error.message || 'Не удалось загрузить счета';
                }).finally(function () {
                    if (request === self.accountsRequest) self.accountsLoading = false;
                });
            },
            confirmAccountRollback: function (account) {
                var self = this;
                if (self.busy || !self.accountBatch || !account.can_rollback) return;
                var batchId = self.accountBatch.id;
                return Swal.fire({
                    title: 'Откатить данные счёта?',
                    text: 'Счёт «' + account.account_name + '», пачка #' + batchId + '. Будет удалено записей: '
                        + account.live_entries + ', балансов: ' + account.live_balances + '. Действие необратимо.',
                    icon: 'warning', showCancelButton: true, confirmButtonText: 'Откатить', cancelButtonText: 'Отмена',
                    confirmButtonColor: '#ef4444',
                }).then(function (result) {
                    if (!result.isConfirmed || self.busy || !self.accountBatch || self.accountBatch.id !== batchId) return;
                    self.rollingAccountId = account.account_id;
                    return axios.post('<?= Url::to(['/import-batch/rollback-account']) ?>', {
                        id: batchId, account_id: account.account_id,
                    }, { params: { page: self.page, page_size: self.pageSize } }).then(function (res) {
                        if (res.data.success) self.applyList(res.data);
                        Swal.fire({ icon: res.data.success ? 'success' : 'error', title: res.data.success ? 'Откатано' : 'Откат недоступен', text: res.data.message });
                        return self.loadAccounts(self.accountsPage);
                    }).catch(function () {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Не удалось получить результат. Обновите список счетов перед повторным откатом.' });
                        return self.loadAccounts(self.accountsPage);
                    }).finally(function () { self.rollingAccountId = null; });
                });
            },
            applyList: function (response) {
                this.batches = response.data;
                this.page = response.pagination.page;
                this.pageSize = response.pagination.page_size;
                this.selectedPageSize = response.pagination.page_size;
                this.pageCount = response.pagination.page_count;
                this.total = response.pagination.total;
            },
            entriesUrl: function (id) {
                return '<?= Url::to(['/all-nostro']) ?>?batch_id=' + id;
            },
            balanceUrl: function (id) {
                return '<?= Url::to(['/all-balance']) ?>?batch_id=' + id;
            },
            fmtDateTime: function (v) {
                if (!v) return '—';
                var d = new Date(v.replace(' ', 'T'));
                if (isNaN(d.getTime())) return v;
                var p = function (n) { return ('0' + n).slice(-2); };
                return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear()
                    + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
            },
            displayCount: function (imported, live) {
                if (imported === null || imported === undefined) return live;
                return imported;
            },
            hasData: function (b) {
                return (b.live_entries > 0) || (b.live_balances > 0);
            },
            hasMissingAccounts: function (b) {
                return !!(b.skipped_accounts && b.skipped_accounts.length);
            },
            missingAccountsShort: function (list) {
                if (!list || !list.length) return '';
                if (list.length <= 3) return list.join(', ');
                return list.slice(0, 3).join(', ') + ' и ещё ' + (list.length - 3);
            },
            reload: function (page) {
                var self = this;
                if (self.busy) return;
                self.loading = true;
                return axios.get('<?= Url::to(['/import-batch/list']) ?>', {
                    params: { page: page || self.page, page_size: self.selectedPageSize },
                }).then(function (r) {
                    if (r.data.success) self.applyList(r.data);
                    else Swal.fire({ icon: 'error', title: 'Ошибка', text: r.data.message });
                }).catch(function () {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Не удалось загрузить страницу' });
                }).finally(function () {
                    self.selectedPageSize = self.pageSize;
                    self.loading = false;
                });
            },
            confirmLoad: function (b) {
                var self = this;
                Swal.fire({
                    title: 'Запустить загрузку?',
                    html: 'Пачка <b>#' + b.id + ' (' + b.type_label + ')</b> будет загружена в систему. '
                        + 'Процесс выполнится синхронно и может занять время на больших объёмах.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Запустить',
                    cancelButtonText: 'Отмена',
                    confirmButtonColor: '#4f46e5',
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    self.loadingId = b.id;
                    axios.post('<?= Url::to(['/import-batch/load']) ?>', { id: b.id }, {
                        params: { page: self.page, page_size: self.pageSize },
                    }).then(function (res) {
                        if (res.data.success) {
                            self.applyList(res.data);
                            Swal.fire({
                                icon: res.data.ok ? 'success' : 'warning',
                                title: res.data.ok ? 'Загружено' : 'Загружено частично',
                                text: res.data.message,
                                timer: res.data.ok ? 2500 : undefined,
                                showConfirmButton: !res.data.ok,
                            });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Не выполнено', text: res.data.message });
                        }
                    }).catch(function () {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
                    }).finally(function () {
                        self.loadingId = null;
                    });
                });
            },
            confirmRollback: function (b) {
                var self = this;
                Swal.fire({
                    title: 'Откатить пачку?',
                    html: 'Пачка <b>#' + b.id + ' (' + b.type_label + ')</b> будет удалена из системы '
                        + 'вместе с записями и балансами. Действие необратимо.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Откатить',
                    cancelButtonText: 'Отмена',
                    confirmButtonColor: '#ef4444',
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    self.rollingId = b.id;
                    axios.post('<?= Url::to(['/import-batch/rollback']) ?>', { id: b.id }, {
                        params: { page: self.page, page_size: self.pageSize },
                    }).then(function (res) {
                        if (res.data.success) {
                            self.applyList(res.data);
                            Swal.fire({ icon: 'success', title: 'Откатано', text: res.data.message, timer: 2200, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                        }
                    }).catch(function () {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
                    }).finally(function () { self.rollingId = null; });
                });
            },
        },
    });

});
</script>
