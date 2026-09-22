<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Выверка по всем ностро-банкам — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="all-nostro-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-globe" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Выверка по всем ностро-банкам</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">
                    <span v-if="entriesTotal > 0">{{ entriesTotal.toLocaleString() }} {{ recordText(entriesTotal) }}</span>
                    <span v-else>Записи со всех ностро-банков компании</span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <button class="toolbar-btn outline" @click="toggleFiltersPanel"
                    :style="(filtersOpen || activeFilterCount() > 0) ? 'border-color:#6366f1;color:#6366f1' : ''">
                <i class="fas fa-filter"></i>Фильтры
                <span v-if="activeFilterCount() > 0"
                      style="background:#6366f1;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px">
                    {{ activeFilterCount() }}
                </span>
            </button>
            <button v-if="activeFilterCount() > 0" class="toolbar-btn outline" @click="clearAllFilters"
                    style="border-color:#ef4444;color:#ef4444">
                <i class="fas fa-times"></i>Сбросить фильтры
            </button>
            <button class="toolbar-btn outline" @click="showAddEntryModal()">
                <i class="fas fa-plus"></i>Добавить запись
            </button>
            <button class="toolbar-btn outline" @click="loadMatchingRules(); _showModal('rulesListModal')">
                <i class="fas fa-sliders-h"></i>Правила
            </button>
            <button class="toolbar-btn primary" :disabled="autoMatchRunning" @click="runAutoMatch()">
                <i :class="autoMatchRunning ? 'fas fa-spinner fa-spin' : 'fas fa-magic'"></i>
                <template v-if="autoMatchRunning && autoMatchProgress">
                    {{ autoMatchProgress.percent }}% (пар: {{ autoMatchProgress.total_matched }})
                </template>
                <template v-else-if="autoMatchRunning">Запуск...</template>
                <template v-else>Автоквитование</template>
            </button>
            <button v-if="autoMatchRunning" class="toolbar-btn outline" @click="cancelAutoMatch()" title="Остановить автоквитование">
                <i class="fas fa-stop"></i>Стоп
            </button>
            <button class="toolbar-btn success"
                    :disabled="!hasSelection || !matchValidity.ok"
                    :title="!matchValidity.ok ? matchValidity.reason : ''"
                    @click="matchSelected">
                <i class="fas fa-link"></i>
                Сквитовать{{ selectedIds.length > 0 ? ' (' + selectedIds.length + ')' : '' }}
            </button>
            <button class="toolbar-btn danger-soft" v-if="selectedIds.length > 0" @click="clearSelection">
                <i class="fas fa-times"></i>Сбросить
            </button>
            <div style="position:relative">
                <button class="toolbar-btn outline" @click="toggleColsDropdown" data-col-toggle
                        :style="showColsDropdown ? 'border-color:#6366f1;color:#6366f1' : ''">
                    <i class="fas fa-columns"></i>Столбцы
                </button>
                <div v-if="showColsDropdown" class="col-mgr-dropdown">
                    <div class="col-mgr-title">Видимые столбцы</div>
                    <label v-for="col in tableColumns" :key="col.key" class="col-mgr-item">
                        <input type="checkbox" v-model="col.visible">
                        {{ col.label }}
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- ПРОГРЕСС АВТОКВИТОВАНИЯ -->
    <div v-if="autoMatchProgress && autoMatchRunning" class="auto-match-progress-bar">
        <div class="d-flex align-items-center justify-content-between mb-1">
            <small class="text-muted">
                <i :class="autoMatchProgress.phase === 'searching' ? 'fas fa-search me-1' : 'fas fa-cog fa-spin me-1'"></i>
                <template v-if="autoMatchProgress.phase === 'searching'">
                    Поиск пар: <b>{{ autoMatchProgress.current_rule_name }}</b>
                </template>
                <template v-else>
                    Квитование: <b>{{ autoMatchProgress.current_rule_name }}</b>
                </template>
            </small>
            <small class="fw-bold">{{ autoMatchProgress.percent }}%</small>
        </div>
        <div class="progress" style="height:6px">
            <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated"
                 :style="{ width: autoMatchProgress.percent + '%' }">
            </div>
        </div>
        <div class="d-flex align-items-center justify-content-between mt-1">
            <small class="text-muted">
                Сквитовано пар: <b>{{ autoMatchProgress.total_matched }}</b>
                · осталось ≈ <b>{{ autoMatchProgress.remaining_unmatched }}</b> зап.
            </small>
            <small class="text-muted">
                <i class="far fa-clock me-1"></i>осталось: {{ autoMatchProgress.eta_text }}
            </small>
        </div>
    </div>

    <?= $this->render("//partials/_entries-filters", [
        "showMultiPoolFilter" => true,
        "showAccountFilter"   => true,
        "showBatchFilter"     => true,
        "poolSelectId"        => "an-filter-pools",
        "accountSelectId"     => "an-filter-account",
        "currencySelectId"    => "an-filter-currency",
    ]) ?>

    <!-- ПАНЕЛЬ ИТОГОВ -->
    <div v-if="selectionSummary && selectedIds.length > 0" class="summary-bar"
         :class="summaryBalanced ? 'balanced' : 'unbalanced'"
         style="margin-bottom:14px">
        <span class="summary-item">
            <i class="fas fa-check-square" style="color:#6366f1"></i>
            <strong>{{ selectedIds.length }}</strong> выбрано
        </span>
        <template v-if="userSection !== 'INV'">
            <span class="summary-sep">|</span>
            <span class="summary-item">
                <span class="mono" style="color:#6366f1">L:</span>
                <strong class="mono">{{ formatAmount(selectionSummary.sum_ledger) }}</strong>
                <span style="font-size:10px;color:#9ca3af">({{ selectionSummary.cnt_ledger }})</span>
            </span>
            <span class="summary-sep">|</span>
            <span class="summary-item">
                <span class="mono" style="color:#0284c7">S:</span>
                <strong class="mono">{{ formatAmount(selectionSummary.sum_statement) }}</strong>
                <span style="font-size:10px;color:#9ca3af">({{ selectionSummary.cnt_statement }})</span>
            </span>
        </template>
        <template v-else>
            <span class="summary-sep">|</span>
            <span class="summary-item">
                <span class="mono" style="color:#ef4444">D:</span>
                <strong class="mono">{{ formatAmount(selectionSummary.sum_debit) }}</strong>
            </span>
            <span class="summary-sep">|</span>
            <span class="summary-item">
                <span class="mono" style="color:#10b981">C:</span>
                <strong class="mono">{{ formatAmount(selectionSummary.sum_credit) }}</strong>
            </span>
        </template>
        <span class="summary-sep">|</span>
        <span class="summary-item" :style="summaryBalanced ? 'color:#059669;font-weight:700' : 'color:#d97706;font-weight:700'">
            <i :class="summaryBalanced ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
            Разница: <span style="font-family:monospace">{{ formatAmount(summaryDiff) }}</span>
            <span v-if="summaryBalanced" style="font-weight:500;margin-left:4px">— готово!</span>
            <span v-else style="font-weight:500;margin-left:4px">— квитование недоступно</span>
        </span>
        <span v-if="!matchValidity.ok" class="summary-sep">|</span>
        <span v-if="!matchValidity.ok" class="summary-item" style="color:#dc2626;font-weight:700">
            <i class="fas fa-ban"></i>
            {{ matchValidity.reason }}
        </span>
    </div>

    <!-- ══ ТАБЛИЦА ══════════════════════════════════════════════ -->
    <div class="table-card">
        <div v-if="entriesLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
            <div class="spinner-border" style="color:#6366f1"></div>
        </div>
        <div v-else-if="entries.length === 0" class="empty-pool" style="padding:60px">
            <i class="fas fa-inbox"></i>
            <p>Нет записей</p>
        </div>
        <div v-else class="table-scroll-wrap" @scroll="onTableScroll">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th style="width:36px;min-width:36px;padding-left:14px">
                            <input type="checkbox"
                                   style="width:14px;height:14px;cursor:pointer;accent-color:#6366f1"
                                   :checked="allUnmatchedSelected"
                                   :indeterminate.prop="someSelected"
                                   @change="toggleSelectAll($event.target.checked)">
                        </th>
                        <th v-show="tblColVisible('id')" class="th-sort th-resizable" @click="sortBy('id')"
                            :style="{width: colByKey('id').width+'px', minWidth: colByKey('id').width+'px'}">
                            <span>ID</span> <i :class="sortIcon('id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('account_id')" class="th-sort th-resizable" @click="sortBy('account_id')"
                            :style="{width: colByKey('account_id').width+'px', minWidth: colByKey('account_id').width+'px'}">
                            <span>Банк / Счёт</span> <i :class="sortIcon('account_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('account_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('match_id')" class="th-sort th-resizable" @click="sortBy('match_id')"
                            :style="{width: colByKey('match_id').width+'px', minWidth: colByKey('match_id').width+'px'}">
                            <span>Match ID</span> <i :class="sortIcon('match_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('match_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('ls')" class="th-sort th-resizable" @click="sortBy('ls')"
                            :style="{width: colByKey('ls').width+'px', minWidth: colByKey('ls').width+'px'}">
                            <span>L/S</span> <i :class="sortIcon('ls')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('ls'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('dc')" class="th-sort th-resizable" @click="sortBy('dc')"
                            :style="{width: colByKey('dc').width+'px', minWidth: colByKey('dc').width+'px'}">
                            <span>D/C</span> <i :class="sortIcon('dc')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('dc'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('amount')" class="th-sort th-resizable" @click="sortBy('amount')"
                            :style="{width: colByKey('amount').width+'px', minWidth: colByKey('amount').width+'px', textAlign:'right'}">
                            <span>Сумма</span> <i :class="sortIcon('amount')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('amount'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('currency')" class="th-sort th-resizable" @click="sortBy('currency')"
                            :style="{width: colByKey('currency').width+'px', minWidth: colByKey('currency').width+'px'}">
                            <span>Вал.</span> <i :class="sortIcon('currency')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('currency'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('value_date')" class="th-sort th-resizable" @click="sortBy('value_date')"
                            :style="{width: colByKey('value_date').width+'px', minWidth: colByKey('value_date').width+'px'}">
                            <span>Value Date</span> <i :class="sortIcon('value_date')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('value_date'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('post_date')" class="th-sort th-resizable" @click="sortBy('post_date')"
                            :style="{width: colByKey('post_date').width+'px', minWidth: colByKey('post_date').width+'px'}">
                            <span>Post Date</span> <i :class="sortIcon('post_date')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('post_date'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('instruction_id')" class="th-sort th-resizable" @click="sortBy('instruction_id')"
                            :style="{width: colByKey('instruction_id').width+'px', minWidth: colByKey('instruction_id').width+'px'}">
                            <span>Instr.ID</span> <i :class="sortIcon('instruction_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('instruction_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('end_to_end_id')" class="th-sort th-resizable" @click="sortBy('end_to_end_id')"
                            :style="{width: colByKey('end_to_end_id').width+'px', minWidth: colByKey('end_to_end_id').width+'px'}">
                            <span>E2E ID</span> <i :class="sortIcon('end_to_end_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('end_to_end_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('transaction_id')" class="th-sort th-resizable" @click="sortBy('transaction_id')"
                            :style="{width: colByKey('transaction_id').width+'px', minWidth: colByKey('transaction_id').width+'px'}">
                            <span>Txn ID</span> <i :class="sortIcon('transaction_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('transaction_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('message_id')" class="th-sort th-resizable" @click="sortBy('message_id')"
                            :style="{width: colByKey('message_id').width+'px', minWidth: colByKey('message_id').width+'px'}">
                            <span>Msg ID</span> <i :class="sortIcon('message_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('message_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('comment')" class="th-sort th-resizable" @click="sortBy('comment')"
                            :style="{width: colByKey('comment').width+'px', minWidth: colByKey('comment').width+'px'}">
                            <span>Комментарий</span> <i :class="sortIcon('comment')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('comment'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('match_status')" class="th-sort th-resizable" @click="sortBy('match_status')"
                            :style="{width: colByKey('match_status').width+'px', minWidth: colByKey('match_status').width+'px'}">
                            <span>Статус</span> <i :class="sortIcon('match_status')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('match_status'))" @click.stop></div>
                        </th>
                        <th style="width:90px;min-width:90px;text-align:right;padding-right:14px">
                            <i class="fas fa-cog" style="opacity:.3"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id"
                        :class="{'entry-selected':isSelected(entry.id),'entry-matched':!isSelected(entry.id)&&entry.match_status==='M'}">

                        <td style="padding-left:14px">
                            <input type="checkbox"
                                   style="width:14px;height:14px;cursor:pointer;accent-color:#6366f1"
                                   v-if="entry.match_status==='U'"
                                   :checked="isSelected(entry.id)"
                                   @change="toggleEntrySelection(entry.id)">
                            <i v-else class="fas fa-lock" style="font-size:10px;color:#d1d5db" title="Сквитовано"></i>
                        </td>
                        <td v-show="tblColVisible('id')" style="font-size:11px;color:#9ca3af;font-family:monospace">{{ entry.id }}</td>
                        <td v-show="tblColVisible('account_id')">
                            <div style="font-size:12px;font-weight:600;color:#374151">{{ entry.account_name || '—' }}</div>
                            <div v-if="entry.pool_name" style="font-size:10px;color:#9ca3af">
                                <i class="fas fa-landmark" style="font-size:9px;color:#4f46e5"></i>
                                {{ entry.pool_name }}
                            </div>
                        </td>
                        <td v-show="tblColVisible('match_id')">
                            <span v-if="entry.match_id" class="match-id-badge" @click="showMatchGroup(entry.match_id)" title="Посмотреть сквитованную пару">
                                <i class="fas fa-link" style="font-size:8px"></i>{{ entry.match_id }}
                            </span>
                            <span v-else style="color:#d1d5db;font-size:11px">—</span>
                        </td>
                        <td v-show="tblColVisible('ls')">
                            <span :class="entry.ls==='L'?'badge-ls-l':'badge-ls-s'">{{ entry.ls }}</span>
                        </td>
                        <td v-show="tblColVisible('dc')">
                            <span :class="entry.dc==='Debit'?'badge-debit':'badge-credit'">
                                {{ entry.dc==='Debit'?'D':'C' }}
                            </span>
                        </td>
                        <td v-show="tblColVisible('amount')" style="text-align:right;font-family:monospace;font-weight:600;color:#1a202c;white-space:nowrap">
                            {{ formatAmount(entry.amount) }}
                        </td>
                        <td v-show="tblColVisible('currency')"><span style="font-size:11px;color:#6b7280;font-weight:700">{{ entry.currency }}</span></td>
                        <td v-show="tblColVisible('value_date')" style="white-space:nowrap;font-size:12px">{{ fmtDate(entry.value_date) }}</td>
                        <td v-show="tblColVisible('post_date')" style="white-space:nowrap;font-size:12px">{{ fmtDate(entry.post_date) }}</td>
                        <td v-show="tblColVisible('instruction_id')" class="td-mono-truncate" :title="entry.instruction_id">{{ entry.instruction_id||'—' }}</td>
                        <td v-show="tblColVisible('end_to_end_id')" class="td-mono-truncate" :title="entry.end_to_end_id">{{ entry.end_to_end_id||'—' }}</td>
                        <td v-show="tblColVisible('transaction_id')" class="td-mono-truncate" :title="entry.transaction_id">{{ entry.transaction_id||'—' }}</td>
                        <td v-show="tblColVisible('message_id')" class="td-mono-truncate" :title="entry.message_id">{{ entry.message_id||'—' }}</td>
                        <td v-show="tblColVisible('comment')" class="td-mono-truncate" :title="entry.comment">{{ entry.comment||'—' }}</td>
                        <td v-show="tblColVisible('match_status')">
                            <span :class="entry.match_status==='M'?'status-badge status-matched':
                                          entry.match_status==='I'?'status-badge status-ignored':
                                          'status-badge status-waiting'">
                                {{ entry.match_status==='M'?'Сквит.':entry.match_status==='I'?'Игнор':'Ожидает' }}
                            </span>
                        </td>
                        <td style="text-align:right;padding-right:8px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button class="row-btn info" @click="openEntryDetail(entry)" title="Подробнее">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="row-btn history" @click="showHistory(entry)" title="История изменений">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button v-if="entry.match_status!=='M'" class="row-btn edit" @click="editEntry(entry)" title="Редактировать">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button class="row-btn unlink" v-if="entry.match_status==='M'"
                                        @click="unmatchEntry(entry.match_id)" title="Расквитовать">
                                    <i class="fas fa-unlink"></i>
                                </button>
                                <div v-if="entry.match_status!=='M'" class="row-actions-dropdown">
                                    <button class="row-btn more" @click.stop="toggleRowMenu('entry', entry.id, $event)" title="Ещё">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div v-if="openRowMenu==='entry-'+entry.id" class="row-actions-menu" :style="rowMenuStyle">
                                        <button class="row-actions-menu-item danger" @click.stop="deleteEntry(entry); openRowMenu=null">
                                            <i class="fas fa-trash"></i> Удалить
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="entriesLoadingMore">
                        <td colspan="17" style="text-align:center;padding:16px">
                            <div class="spinner-border spinner-border-sm"
                                 style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
                            <span style="margin-left:8px;font-size:12px;color:#9ca3af">Загрузка...</span>
                        </td>
                    </tr>
                    <tr v-if="!hasMoreEntries && entries.length>0 && !entriesLoading">
                        <td colspan="17" style="text-align:center;padding:12px;font-size:11px;color:#c4c9d6;border-top:1px solid #f4f5f8">
                            <i class="fas fa-check-circle me-1"></i>
                            Все {{ entriesTotal.toLocaleString() }} {{ recordText(entriesTotal) }} загружены
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?= $this->render("//partials/_entries-detail-modal") ?>
    <?= $this->render("//partials/_matching-modals", ['showBankSelect' => true]) ?>

</div>

<script>
    window.AllNostroInit = <?= $initJson ?>;
    window.AllNostroRoutes = {
        list:           '<?= Url::to(['/all-nostro/list']) ?>',
        searchAccounts: '<?= Url::to(['/all-nostro/search-accounts']) ?>'
    };
</script>
