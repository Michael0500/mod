<?php
/** @var yii\web\View $this */
?>

<div class="modal fade" id="entryHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-history"></i></span>
                    История изменений записи
                    <span v-if="historyEntry" style="font-weight:400;color:#9ca3af;font-size:13px;margin-left:8px">
                        #{{ historyEntry.id }}
                        · <span style="color:#6366f1;font-weight:600">{{ historyEntry.ls }}</span>
                        · <span :style="historyEntry.dc==='Debit'?'color:#dc2626;font-weight:600':'color:#059669;font-weight:600'">
                            {{ historyEntry.dc === 'Debit' ? 'D' : 'C' }}
                        </span>
                        · {{ formatAmount(historyEntry.amount, historyEntry.currency) }} {{ historyEntry.currency }}
                    </span>
                </h5>
                <button type="button" class="btn-close" @click="closeEntryHistoryModal"></button>
            </div>

            <div class="modal-body" style="padding:0 !important">
                <div v-if="historyLoading" style="text-align:center;padding:48px">
                    <div class="spinner-border" style="color:#6366f1;width:32px;height:32px"></div>
                    <div style="margin-top:12px;color:#9ca3af;font-size:13px">Загрузка истории...</div>
                </div>

                <div v-else-if="!historyItems || historyItems.length === 0" class="empty-pool" style="padding:56px">
                    <i class="fas fa-clock" style="font-size:48px;color:#d1d5db"></i>
                    <p style="margin-top:12px;color:#9ca3af;font-size:14px">История изменений пуста</p>
                </div>

                <div v-else class="hist-table-wrap">
                    <table class="hist-table">
                        <thead>
                        <tr>
                            <th class="hist-th-meta" style="min-width:140px">Дата / Действие</th>
                            <th class="hist-th-meta" style="min-width:90px">Польз.</th>
                            <th>Счёт</th>
                            <th>L/S</th>
                            <th>D/C</th>
                            <th style="text-align:right">Сумма</th>
                            <th>Валюта</th>
                            <th>Value Date</th>
                            <th>Post Date</th>
                            <th>Instr. ID</th>
                            <th>E2E ID</th>
                            <th>Txn ID</th>
                            <th>Msg ID</th>
                            <th>Комментарий</th>
                            <th>Статус</th>
                            <th>Match ID</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="(item, index) in historyItems" :key="item.created_at + '-' + item.action + '-' + index"
                            :class="'hist-row hist-row-' + item.action">
                            <td class="hist-td-meta">
                                <div class="hist-action-badge" :class="'hist-badge-' + item.action">
                                    <i :class="getHistoryIcon(item.action)"></i>
                                    {{ getHistoryActionLabel(item.action) }}
                                </div>
                                <div class="hist-date">{{ formatDate(item.created_at) }}</div>
                                <div v-if="item.reason" class="hist-reason" :title="item.reason">
                                    <i class="fas fa-comment-alt" style="font-size:9px"></i>
                                    {{ item.reason }}
                                </div>
                            </td>
                            <td class="hist-td-meta">
                                <div class="hist-user">
                                    <i class="fas fa-user-circle" style="color:#9ca3af;font-size:14px"></i>
                                    <span>{{ item.username || ('User #' + item.user_id) }}</span>
                                </div>
                            </td>

                            <td :class="histCellClass(item, 'account_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'account_id')" class="hist-old-val">{{ getOldVal(item, 'account_id') || '—' }}</div>
                                    <div class="hist-new-val">{{ getSnapVal(item, 'account_name') || getSnapVal(item, 'account_id') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'ls')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'ls')" class="hist-old-val">{{ getOldVal(item, 'ls') || '—' }}</div>
                                    <span class="hist-new-val" style="font-weight:700;color:#6366f1">{{ getSnapVal(item, 'ls') || '—' }}</span>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'dc')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'dc')" class="hist-old-val">
                                        {{ getOldVal(item, 'dc') === 'Debit' ? 'D' : (getOldVal(item,'dc') === 'Credit' ? 'C' : '—') }}
                                    </div>
                                    <span class="hist-new-val" style="font-weight:700"
                                          :style="getSnapVal(item,'dc')==='Debit'?'color:#dc2626':'color:#059669'">
                                        {{ getSnapVal(item, 'dc') === 'Debit' ? 'D' : (getSnapVal(item,'dc') === 'Credit' ? 'C' : '—') }}
                                    </span>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'amount')" style="text-align:right">
                                <div class="hist-cell-inner" style="align-items:flex-end">
                                    <div v-if="isChanged(item, 'amount')" class="hist-old-val hist-mono">{{ formatAmount(getOldVal(item, 'amount'), getOldVal(item, 'currency') || historyEntry.currency) }}</div>
                                    <div class="hist-new-val hist-mono" style="font-weight:600">{{ formatAmount(getSnapVal(item, 'amount'), getSnapVal(item, 'currency') || historyEntry.currency) }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'currency')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'currency')" class="hist-old-val">{{ getOldVal(item,'currency') || '—' }}</div>
                                    <div class="hist-new-val" style="font-weight:600">{{ getSnapVal(item,'currency') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'value_date')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'value_date')" class="hist-old-val">{{ getOldVal(item,'value_date') || '—' }}</div>
                                    <div class="hist-new-val">{{ getSnapVal(item,'value_date') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'post_date')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'post_date')" class="hist-old-val">{{ getOldVal(item,'post_date') || '—' }}</div>
                                    <div class="hist-new-val">{{ getSnapVal(item,'post_date') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'instruction_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'instruction_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'instruction_id') || '—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'instruction_id') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'end_to_end_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'end_to_end_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'end_to_end_id') || '—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'end_to_end_id') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'transaction_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'transaction_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'transaction_id') || '—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'transaction_id') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'message_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'message_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'message_id') || '—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'message_id') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'comment')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'comment')" class="hist-old-val" style="font-style:italic">{{ getOldVal(item,'comment') || '—' }}</div>
                                    <div class="hist-new-val" style="font-style:italic">{{ getSnapVal(item,'comment') || '—' }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'match_status')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'match_status')" class="hist-old-val">{{ histStatusLabel(getOldVal(item,'match_status')) }}</div>
                                    <div class="hist-new-val">{{ histStatusLabel(getSnapVal(item,'match_status')) }}</div>
                                </div>
                            </td>
                            <td :class="histCellClass(item, 'match_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'match_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'match_id') || '—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'match_id') || '—' }}</div>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <div v-if="historyItems && historyItems.length > 0" style="font-size:12px;color:#9ca3af;margin-right:auto">
                    <i class="fas fa-info-circle me-1"></i>
                    Изменённые поля <span class="hist-legend-changed"></span> подсвечены.
                </div>
                <button class="modal-btn cancel" @click="closeEntryHistoryModal">
                    <i class="fas fa-times"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>
