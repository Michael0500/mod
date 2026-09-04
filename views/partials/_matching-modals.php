<?php
/**
 * Общий partial модалок раздела выверки/квитования.
 *
 * Используется страницами `/site/index` (через `_modals.php`) и `/all-nostro`.
 * Содержит модалки правила, формы записи, истории, группы квитования и
 * автоквитования. На странице "Выверка по всем ностро-банкам" форма записи
 * получает дополнительный селект ностро-банка перед селектом счёта.
 *
 * @var yii\web\View $this
 * @var bool $showBankSelect — показывать ли селект ностро-банка в форме записи
 *                              (нужен на /all-nostro, т.к. сайдбара нет).
 */

$showBankSelect = $showBankSelect ?? false;
?>

<!-- ══════════════════════════ Правило квитования ══════════════════════════ -->
<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-sliders-h"></i></span>
                    {{ editingRule.id ? 'Редактировать правило' : 'Новое правило квитования' }}
                </h5>
                <button type="button" class="btn-close" @click="closeRuleModal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="editingRule.name" placeholder="Например: NRE — стандартное сопоставление">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Ностро-банк</label>
                        <select id="rule-pool-select2" multiple="multiple" style="width:100%"></select>
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px">
                            Общее правило применяется ко всем ностро-банкам компании; выбор банка ограничивает правило только им
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Раздел</label>
                        <select class="form-select" v-model="editingRule.section">
                            <option value="NRE">NRE</option>
                            <option value="INV">INV</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Тип пары</label>
                        <select class="form-select" v-model="editingRule.pair_type">
                            <option value="LS">Ledger ↔ Statement</option>
                            <option value="LL">Ledger ↔ Ledger</option>
                            <option value="SS">Statement ↔ Statement</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Приоритет</label>
                        <input type="number" class="form-control" v-model.number="editingRule.priority" min="1" max="999">
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px">Меньше = выше</div>
                    </div>
                </div>
                <div class="form-section mb-3">
                    <div class="form-section-title">
                        <i class="fas fa-check-double" style="color:#10b981"></i>Обязательные условия
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_dc" id="r_dc">
                                <label class="form-check-label" for="r_dc">Противоположный D/C</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_amount" id="r_amount">
                                <label class="form-check-label" for="r_amount">Совпадение суммы</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_value_date" id="r_vdate">
                                <label class="form-check-label" for="r_vdate">Дата валютирования</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-section mb-3">
                    <div class="form-section-title">
                        <i class="fas fa-fingerprint" style="color:#6366f1"></i>
                        Идентификаторы
                        <span style="font-weight:400;color:#c4c9d6;text-transform:none;letter-spacing:0">— хотя бы один</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_instruction_id" id="r_instr">
                                <label class="form-check-label" for="r_instr">Instruction ID</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_end_to_end_id" id="r_e2e">
                                <label class="form-check-label" for="r_e2e">EndToEnd ID</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_transaction_id" id="r_txn">
                                <label class="form-check-label" for="r_txn">Transaction ID</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_message_id" id="r_msg">
                                <label class="form-check-label" for="r_msg">Message ID</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2" style="margin-top:8px">
                        <div class="col-md-6">
                            <label class="form-label" for="r_reference_value">Искомое значение</label>
                            <input id="r_reference_value" type="text" class="form-control"
                                   v-model.trim="editingRule.reference_value"
                                   placeholder="Например: TEST-123">
                            <div style="font-size:11.5px;color:#9ca3af;margin-top:3px">
                                Если заполнено, автоквитование ищет это значение в любом выбранном референсе у обеих записей пары.
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8eaf0">
                        <div class="form-check form-switch" style="margin-bottom:10px">
                            <input class="form-check-input" type="checkbox" v-model="editingRule.id_prefix_match"
                                   :disabled="!editingRule.match_instruction_id && !editingRule.match_end_to_end_id && !editingRule.match_transaction_id && !editingRule.match_message_id" id="r_id_prefix">
                            <label class="form-check-label" for="r_id_prefix" style="font-weight:600 !important">
                                Сравнивать Transaction ID по первым символам
                            </label>
                        </div>
                        <div class="row g-2" v-if="editingRule.id_prefix_match">
                            <div class="col-md-6">
                                <label class="form-label" for="r_txn_prefix_len">Количество символов</label>
                                <input id="r_id_prefix_len" type="number" class="form-control"
                                       v-model.number="editingRule.id_prefix_length"
                                       min="1" max="60" step="1">
                            </div>
                        </div>
                        <div style="font-size:11.5px;color:#9ca3af;margin-top:3px;margin-left:42px;margin-bottom:10px">
                            Используется только для Transaction ID. Сравниваются первые N символов, начиная с первого.
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" v-model="editingRule.cross_id_search" id="r_cross">
                            <label class="form-check-label" for="r_cross" style="font-weight:600 !important">
                                Перекрёстный поиск ID
                            </label>
                        </div>
                        <div style="font-size:11.5px;color:#9ca3af;margin-top:3px;margin-left:42px">
                            ID из любого поля записи A ищется во всех полях записи B
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" v-model="editingRule.is_active" id="r_active">
                            <label class="form-check-label" for="r_active" style="font-weight:600 !important">Активно</label>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Описание</label>
                        <input type="text" class="form-control" v-model="editingRule.description" placeholder="Комментарий...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeRuleModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="saveRule"><i class="fas fa-save"></i>Сохранить правило</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════ МОДАЛ ДОБАВЛЕНИЯ/РЕДАКТИРОВАНИЯ ЗАПИСИ ════════════════ -->
<div class="modal fade" id="entryModal" tabindex="-1" aria-labelledby="entryModalLabel"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width: 1000px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="entryModalLabel">
                    <span class="modal-icon" :class="editingEntry.id ? 'amber' : 'green'">
                        <i :class="editingEntry.id ? 'fas fa-pen' : 'fas fa-plus'"></i>
                    </span>
                    {{ editingEntry.id ? 'Редактировать запись #' + editingEntry.id : 'Добавить запись' }}
                </h5>
                <button type="button" class="btn-close" @click="closeEntryModal"></button>
            </div>
            <div class="modal-body">

                <?php if ($showBankSelect): ?>
                <!-- ── Ностро-банк (Select2, только на /all-nostro) ── -->
                <div class="form-section" style="margin-bottom:16px">
                    <label class="form-section-label">
                        <i class="fas fa-landmark"></i> Ностро-банк
                        <span class="required-star">*</span>
                    </label>
                    <select id="entry-pool-select2" style="width:100%">
                        <option v-if="editingEntry.pool_id" :value="editingEntry.pool_id">
                            {{ editingEntry.pool_name }}
                        </option>
                    </select>
                    <div class="field-hint">Сначала выберите ностро-банк, затем счёт</div>
                </div>
                <?php endif; ?>

                <!-- ── Счёт (Select2) ── -->
                <div class="form-section" style="margin-bottom:16px">
                    <label class="form-section-label">
                        <i class="fas fa-university"></i> Счёт
                        <span class="required-star">*</span>
                    </label>
                    <select id="entry-account-select2" style="width:100%">
                        <option v-if="editingEntry.account_id" :value="editingEntry.account_id">
                            {{ editingEntry.account_name }}
                        </option>
                    </select>
                    <div class="field-hint">Начните вводить название счёта для поиска</div>
                </div>

                <!-- ── L/S · D/C · Валюта ── -->
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label-sm">L/S <span class="required-star">*</span></label>
                        <div class="entry-radio-group">
                            <label class="entry-radio" :class="{ active: editingEntry.ls === 'L' }">
                                <input type="radio" v-model="editingEntry.ls" value="L">
                                <span class="badge-ls-l">L</span> Ledger
                            </label>
                            <label class="entry-radio" :class="{ active: editingEntry.ls === 'S' }">
                                <input type="radio" v-model="editingEntry.ls" value="S">
                                <span class="badge-ls-s">S</span> Statement
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">D/C <span class="required-star">*</span></label>
                        <div class="entry-radio-group">
                            <label class="entry-radio" :class="{ active: editingEntry.dc === 'Debit' }">
                                <input type="radio" v-model="editingEntry.dc" value="Debit">
                                <span class="badge-debit">D</span> Debit
                            </label>
                            <label class="entry-radio" :class="{ active: editingEntry.dc === 'Credit' }">
                                <input type="radio" v-model="editingEntry.dc" value="Credit">
                                <span class="badge-credit">C</span> Credit
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">Сумма <span class="required-star">*</span></label>
                        <input type="text" class="form-control-sm-custom" v-model="editingEntry.amount"
                               placeholder="0.00" @blur="editingEntry.amount = normalizeAmount(editingEntry.amount)">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">Валюта <span class="required-star">*</span></label>
                        <select class="form-control-sm-custom" v-model="editingEntry.currency">
                            <option value="">— выберите —</option>
                            <option v-for="c in dictCurrencies" :key="c.code" :value="c.code">{{ c.code }}</option>
                        </select>
                    </div>
                </div>

                <!-- ── Даты ── -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-sm">Value Date</label>
                        <input type="text" v-datepicker class="form-control-sm-custom" v-model="editingEntry.value_date">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">Post Date</label>
                        <input type="text" v-datepicker class="form-control-sm-custom" v-model="editingEntry.post_date">
                    </div>
                </div>

                <!-- ── ID поля ── -->
                <div class="form-section">
                    <div class="form-section-label">
                        <i class="fas fa-fingerprint"></i> Идентификаторы
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-sm">Instruction ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.instruction_id" maxlength="40" placeholder="INSTR...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">EndToEnd ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.end_to_end_id" maxlength="40" placeholder="E2E...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Transaction ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.transaction_id" maxlength="60" placeholder="TXN...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Message ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.message_id" maxlength="40" placeholder="MSG...">
                        </div>
                    </div>
                </div>

                <!-- ── Комментарий ── -->
                <div class="mt-3">
                    <label class="form-label-sm">Комментарий</label>
                    <input type="text" class="form-control-sm-custom"
                           v-model="editingEntry.comment" maxlength="40"
                           placeholder="До 40 символов...">
                </div>

            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeEntryModal">
                    <i class="fas fa-times"></i>Отмена
                </button>
                <button class="modal-btn save-green" @click="saveEntry" :disabled="entrySaving">
                    <i :class="entrySaving ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i>
                    {{ entrySaving ? 'Сохранение...' : (editingEntry.id ? 'Сохранить' : 'Добавить запись') }}
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ История изменений записи ══════════════════════════ -->
<div class="modal fade" id="entryHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:1400px">
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
                        · {{ formatAmount(historyEntry.amount) }} {{ historyEntry.currency }}
                    </span>
                </h5>
                <button type="button" class="btn-close" @click="closeEntryHistoryModal"></button>

            </div>

            <div class="modal-body" style="padding:0 !important">

                <!-- Загрузка -->
                <div v-if="historyLoading" style="text-align:center;padding:48px">
                    <div class="spinner-border" style="color:#6366f1;width:32px;height:32px"></div>
                    <div style="margin-top:12px;color:#9ca3af;font-size:13px">Загрузка истории...</div>
                </div>

                <!-- Пусто -->
                <div v-else-if="!historyItems || historyItems.length === 0" class="empty-pool" style="padding:56px">
                    <i class="fas fa-clock" style="font-size:48px;color:#d1d5db"></i>
                    <p style="margin-top:12px;color:#9ca3af;font-size:14px">История изменений пуста</p>
                </div>

                <!-- Таблица истории -->
                <div v-else class="hist-table-wrap">
                    <table class="hist-table">
                        <thead>
                        <tr>
                            <th class="hist-th-meta" style="min-width:140px">Дата / Действие</th>
                            <th class="hist-th-meta" style="min-width:80px">Польз.</th>
                            <!-- Колонки полей записи -->
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
                        <tr v-for="item in historyItems" :key="item.id"
                            :class="'hist-row hist-row-' + item.action">

                            <!-- Мета: дата + действие -->
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

                            <!-- Пользователь -->
                            <td class="hist-td-meta">
                                <div class="hist-user">
                                    <i class="fas fa-user-circle" style="color:#9ca3af;font-size:14px"></i>
                                    <span>{{ item.username || ('User #' + item.user_id) }}</span>
                                </div>
                            </td>

                            <!-- account_id / account_name -->
                            <td :class="histCellClass(item, 'account_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'account_id')" class="hist-old-val">
                                        {{ getOldVal(item, 'account_id') || '—' }}
                                    </div>
                                    <div class="hist-new-val">{{ getSnapVal(item, 'account_name') || getSnapVal(item, 'account_id') || '—' }}</div>
                                </div>
                            </td>

                            <!-- ls -->
                            <td :class="histCellClass(item, 'ls')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'ls')" class="hist-old-val">
                                        {{ getOldVal(item, 'ls') || '—' }}
                                    </div>
                                    <span class="hist-new-val" style="font-weight:700;color:#6366f1">
                                            {{ getSnapVal(item, 'ls') || '—' }}
                                        </span>
                                </div>
                            </td>

                            <!-- dc -->
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

                            <!-- amount -->
                            <td :class="histCellClass(item, 'amount')" style="text-align:right">
                                <div class="hist-cell-inner" style="align-items:flex-end">
                                    <div v-if="isChanged(item, 'amount')" class="hist-old-val" style="font-family:monospace">
                                        {{ formatAmount(getOldVal(item, 'amount')) }}
                                    </div>
                                    <div class="hist-new-val" style="font-family:monospace;font-weight:600">
                                        {{ formatAmount(getSnapVal(item, 'amount')) }}
                                    </div>
                                </div>
                            </td>

                            <!-- currency -->
                            <td :class="histCellClass(item, 'currency')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'currency')" class="hist-old-val">{{ getOldVal(item,'currency')||'—' }}</div>
                                    <div class="hist-new-val" style="font-weight:600">{{ getSnapVal(item,'currency')||'—' }}</div>
                                </div>
                            </td>

                            <!-- value_date -->
                            <td :class="histCellClass(item, 'value_date')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'value_date')" class="hist-old-val">{{ getOldVal(item,'value_date')||'—' }}</div>
                                    <div class="hist-new-val">{{ getSnapVal(item,'value_date')||'—' }}</div>
                                </div>
                            </td>

                            <!-- post_date -->
                            <td :class="histCellClass(item, 'post_date')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'post_date')" class="hist-old-val">{{ getOldVal(item,'post_date')||'—' }}</div>
                                    <div class="hist-new-val">{{ getSnapVal(item,'post_date')||'—' }}</div>
                                </div>
                            </td>

                            <!-- instruction_id -->
                            <td :class="histCellClass(item, 'instruction_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'instruction_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'instruction_id')||'—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'instruction_id')||'—' }}</div>
                                </div>
                            </td>

                            <!-- end_to_end_id -->
                            <td :class="histCellClass(item, 'end_to_end_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'end_to_end_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'end_to_end_id')||'—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'end_to_end_id')||'—' }}</div>
                                </div>
                            </td>

                            <!-- transaction_id -->
                            <td :class="histCellClass(item, 'transaction_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'transaction_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'transaction_id')||'—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'transaction_id')||'—' }}</div>
                                </div>
                            </td>

                            <!-- message_id -->
                            <td :class="histCellClass(item, 'message_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'message_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'message_id')||'—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'message_id')||'—' }}</div>
                                </div>
                            </td>

                            <!-- comment -->
                            <td :class="histCellClass(item, 'comment')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'comment')" class="hist-old-val" style="font-style:italic">{{ getOldVal(item,'comment')||'—' }}</div>
                                    <div class="hist-new-val" style="font-style:italic">{{ getSnapVal(item,'comment')||'—' }}</div>
                                </div>
                            </td>

                            <!-- match_status -->
                            <td :class="histCellClass(item, 'match_status')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'match_status')" class="hist-old-val">
                                        {{ histStatusLabel(getOldVal(item,'match_status')) }}
                                    </div>
                                    <div class="hist-new-val">
                                        {{ histStatusLabel(getSnapVal(item,'match_status')) }}
                                    </div>
                                </div>
                            </td>

                            <!-- match_id -->
                            <td :class="histCellClass(item, 'match_id')">
                                <div class="hist-cell-inner">
                                    <div v-if="isChanged(item, 'match_id')" class="hist-old-val hist-mono">{{ getOldVal(item,'match_id')||'—' }}</div>
                                    <div class="hist-new-val hist-mono">{{ getSnapVal(item,'match_id')||'—' }}</div>
                                </div>
                            </td>

                        </tr>
                        </tbody>
                    </table>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <div v-if="historyItems && historyItems.length > 0"
                     style="font-size:12px;color:#9ca3af;margin-right:auto">
                    <i class="fas fa-info-circle me-1"></i>
                    Изменённые поля <span class="hist-legend-changed"></span> подсвечены.
                    Зачёркнутое — прежнее значение, жирное — новое.
                </div>
                <button class="modal-btn cancel" @click="closeEntryHistoryModal">
                    <i class="fas fa-times"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Автоквитование — выбор области ══════════════════════════ -->
<div class="modal fade" id="autoMatchScopeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-magic"></i></span>
                    Автоквитование
                </h5>
                <button type="button" class="btn-close" @click="_hideModal('autoMatchScopeModal')"></button>
            </div>
            <div class="modal-body" style="padding:20px 24px">
                <p style="font-size:13px;color:#6b7280;margin-bottom:16px">
                    Выберите область записей для автоквитования:
                </p>

                <!-- Опция: по всем записям -->
                <label class="automatch-scope-option" :class="{ active: autoMatchScope.type === 'all' }"
                       @click="autoMatchScope.type = 'all'">
                    <div class="automatch-scope-radio">
                        <div class="automatch-scope-dot" v-if="autoMatchScope.type === 'all'"></div>
                    </div>
                    <div>
                        <div class="automatch-scope-title">Все записи</div>
                        <div class="automatch-scope-desc">Квитовать по всем незаквитованным записям раздела</div>
                    </div>
                </label>

                <!-- Опция: по категории -->
                <label class="automatch-scope-option" :class="{ active: autoMatchScope.type === 'category' }"
                       @click="autoMatchScope.type = 'category'"
                       v-if="selectedCategory">
                    <div class="automatch-scope-radio">
                        <div class="automatch-scope-dot" v-if="autoMatchScope.type === 'category'"></div>
                    </div>
                    <div>
                        <div class="automatch-scope-title">По категории</div>
                        <div class="automatch-scope-desc">
                            Только записи категории <b>{{ selectedCategory ? selectedCategory.name : '' }}</b>
                        </div>
                    </div>
                </label>

                <!-- Опция: по ностробанку -->
                <label class="automatch-scope-option" :class="{ active: autoMatchScope.type === 'pool' }"
                       @click="autoMatchScope.type = 'pool'"
                       v-if="autoMatchScope.poolId">
                    <div class="automatch-scope-radio">
                        <div class="automatch-scope-dot" v-if="autoMatchScope.type === 'pool'"></div>
                    </div>
                    <div>
                        <div class="automatch-scope-title">По ностробанку</div>
                        <div class="automatch-scope-desc">
                            Только записи ностробанка <b>{{ autoMatchScope.poolName }}</b>
                        </div>
                    </div>
                </label>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="_hideModal('autoMatchScopeModal')">
                    <i class="fas fa-times"></i>Отмена
                </button>
                <button class="modal-btn save" @click="confirmAutoMatch" style="background:#6366f1">
                    <i class="fas fa-magic"></i>Запустить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Просмотр сквитованной пары ══════════════════════════ -->
<div class="modal fade" id="matchGroupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-link"></i></span>
                    Сквитованная пара
                    <code v-if="matchGroupId" style="margin-left:8px;font-size:13px">{{ matchGroupId }}</code>
                </h5>
                <button type="button" class="btn-close" @click="closeMatchGroupModal"></button>
            </div>
            <div class="modal-body" style="padding:0">
                <div v-if="matchGroupLoading" style="text-align:center;padding:40px">
                    <div class="spinner-border spinner-border-sm" style="color:#6366f1;width:20px;height:20px;border-width:2px"></div>
                    <span style="margin-left:8px;font-size:13px;color:#9ca3af">Загрузка...</span>
                </div>
                <div v-else-if="matchGroupEntries.length">
                    <table class="table table-sm table-bordered mb-0" style="font-size:12px">
                        <thead>
                            <tr style="background:#f8fafc">
                                <th style="width:40px;text-align:center">L/S</th>
                                <th style="width:40px;text-align:center">D/C</th>
                                <th>Счёт</th>
                                <th style="text-align:right">Сумма</th>
                                <th>Валюта</th>
                                <th>Дата валют.</th>
                                <th>Дата пров.</th>
                                <th>Instruction ID</th>
                                <th>E2E ID</th>
                                <th>Txn ID</th>
                                <th>Msg ID</th>
                                <th>Комментарий</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in matchGroupEntries" :key="e.id">
                                <td style="text-align:center">
                                    <span :class="e.ls==='L'?'badge-ls-l':'badge-ls-s'">{{ e.ls }}</span>
                                </td>
                                <td style="text-align:center">
                                    <span :class="e.dc==='Debit'?'badge-debit':'badge-credit'">{{ e.dc==='Debit'?'D':'C' }}</span>
                                </td>
                                <td>{{ e.account_name || '—' }}</td>
                                <td style="text-align:right;font-family:monospace;font-weight:600">{{ formatAmount(e.amount) }}</td>
                                <td><span style="font-size:11px;color:#6b7280;font-weight:700">{{ e.currency }}</span></td>
                                <td style="white-space:nowrap">{{ fmtDate(e.value_date) }}</td>
                                <td style="white-space:nowrap">{{ fmtDate(e.post_date) }}</td>
                                <td class="td-mono-truncate" :title="e.instruction_id" style="max-width:120px">{{ e.instruction_id||'—' }}</td>
                                <td class="td-mono-truncate" :title="e.end_to_end_id" style="max-width:120px">{{ e.end_to_end_id||'—' }}</td>
                                <td class="td-mono-truncate" :title="e.transaction_id" style="max-width:120px">{{ e.transaction_id||'—' }}</td>
                                <td class="td-mono-truncate" :title="e.message_id" style="max-width:120px">{{ e.message_id||'—' }}</td>
                                <td>{{ e.comment||'—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:space-between">
                <button class="modal-btn cancel" @click="unmatchFromGroup" style="background:#ef4444;color:#fff;border-color:#ef4444">
                    <i class="fas fa-unlink"></i>Расквитовать
                </button>
                <button class="modal-btn cancel" @click="closeMatchGroupModal">
                    <i class="fas fa-times"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модал: Список правил -->
<div class="modal fade" id="rulesListModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-sliders-h"></i></span>
                    Правила автоквитования
                </h5>
                <button type="button" class="btn-close" @click="_hideModal('rulesListModal')"></button>
            </div>
            <div class="modal-body" style="padding:0 !important">
                <div style="padding:12px 16px;border-bottom:1px solid #f1f3f7">
                    <button class="toolbar-btn success" @click="showAddRuleModal">
                        <i class="fas fa-plus"></i>Добавить правило
                    </button>
                </div>
                <div v-if="loadingRules" style="text-align:center;padding:40px">
                    <div class="spinner-border" style="color:#6366f1"></div>
                </div>
                <div v-else-if="!matchingRules||matchingRules.length===0" class="empty-pool" style="padding:48px">
                    <i class="fas fa-inbox"></i><p>Нет правил. Создайте первое.</p>
                </div>
                <table v-else class="entries-table">
                    <thead><tr>
                        <th style="padding-left:16px">Название</th>
                        <th>Ностро-банк</th>
                        <th>Раздел</th><th>Тип пары</th><th>Условия</th>
                        <th style="width:55px">Приор.</th>
                        <th style="width:80px">Статус</th>
                        <th style="width:72px;text-align:right;padding-right:16px"></th>
                    </tr></thead>
                    <tbody>
                    <tr v-for="rule in matchingRules" :key="rule.id">
                        <td style="padding-left:16px">
                            <span style="font-weight:600">{{ rule.name }}</span>
                            <div v-if="rule.description" style="font-size:11px;color:#9ca3af">{{ rule.description }}</div>
                        </td>
                        <td style="font-size:12px">
                            <span v-if="rule.pool_id" style="font-weight:600">
                                <i class="fas fa-landmark" style="color:#6366f1;font-size:11px;margin-right:4px"></i>{{ rule.pool_name || ('#' + rule.pool_id) }}
                            </span>
                            <span v-else style="color:#9ca3af">Все банки</span>
                        </td>
                        <td><span style="background:#1e2532;color:#fff;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700">{{ rule.section }}</span></td>
                        <td style="font-size:12px">{{ rule.pair_type_label }}</td>
                        <td style="font-size:11px;color:#6b7280">{{ rule.conditions_summary }}</td>
                        <td style="text-align:center;font-size:12px">{{ rule.priority }}</td>
                        <td><span :class="rule.is_active?'status-badge status-matched':'status-badge status-ignored'">{{ rule.is_active?'Активно':'Откл.' }}</span></td>
                        <td style="text-align:right;padding-right:16px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button class="row-btn edit" @click="editRule(rule)"><i class="fas fa-pen"></i></button>
                                <div class="row-actions-dropdown">
                                    <button class="row-btn more" @click.stop="toggleRowMenu('rule', rule.id, $event)"><i class="fas fa-ellipsis-v"></i></button>
                                    <div v-if="openRowMenu==='rule-'+rule.id" class="row-actions-menu" :style="rowMenuStyle">
                                        <button class="row-actions-menu-item danger" @click.stop="deleteRule(rule); openRowMenu=null">
                                            <i class="fas fa-trash"></i> Удалить
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="_hideModal('rulesListModal')">
                    <i class="fas fa-times"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>
