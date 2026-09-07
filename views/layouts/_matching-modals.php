<?php /** @var yii\web\View $this */ ?>

<!-- ═══════════════════════════════════════════════════════════════
     Модал: Правило автоквитования (создать/редактировать)
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-sliders-h me-2"></i>
                    {{ editingRule.id ? 'Редактировать правило' : 'Новое правило квитования' }}
                </h5>
                <button type="button" class="btn-close btn-close-white" @click="closeRuleModal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">

                    <!-- Название -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Название правила <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" v-model="editingRule.name"
                               placeholder="Например: NRE — Ledger vs Statement (стандарт)">
                    </div>

                    <!-- Раздел + Тип пары + Приоритет -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Раздел</label>
                        <select class="form-select" v-model="editingRule.section">
                            <option value="NRE">NRE</option>
                            <option value="INV">INV</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Тип пары</label>
                        <select class="form-select" v-model="editingRule.pair_type">
                            <option value="LS">Ledger ↔ Statement</option>
                            <option value="LL">Ledger ↔ Ledger</option>
                            <option value="SS">Statement ↔ Statement</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Приоритет</label>
                        <input type="number" class="form-control" v-model.number="editingRule.priority"
                               min="1" max="999" placeholder="100">
                        <div class="form-text">Меньше = выше</div>
                    </div>

                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.group_match_enabled" id="r_group_match">
                                    <label class="form-check-label fw-semibold" for="r_group_match">
                                        Групповое квитование
                                    </label>
                                </div>
                                <div class="row g-2" v-if="editingRule.group_match_enabled">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="r_group_ls_type">
                                            Тип бух.проводки (группа) <span class="text-danger">*</span>
                                        </label>
                                        <select id="r_group_ls_type" class="form-select" v-model="editingRule.group_ls_type">
                                            <option :value="null">Выберите тип</option>
                                            <option value="LS">Ledger и Statement</option>
                                            <option value="L">Только Ledger</option>
                                            <option value="S">Только Statement</option>
                                        </select>
                                        <div class="form-text">
                                            Сумма выбранного типа по совпадающим референсам должна совпасть с суммой второй стороны из поля «Тип пары».
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Условия совпадения -->
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title mb-3 text-muted">
                                    <i class="fas fa-check-double me-1"></i> Обязательные условия
                                </h6>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_dc" id="r_dc">
                                            <label class="form-check-label" for="r_dc">
                                                Противоположный D/C
                                                <i class="fas fa-info-circle text-muted ms-1"
                                                   title="Debit ↔ Credit" data-bs-toggle="tooltip"></i>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_amount" id="r_amount">
                                            <label class="form-check-label" for="r_amount">Совпадение суммы</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_value_date" id="r_vdate">
                                            <label class="form-check-label" for="r_vdate">Дата валютирования</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Идентификаторы -->
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title mb-3 text-muted">
                                    <i class="fas fa-fingerprint me-1"></i>
                                    Референс/Идентификатор транзакции
                                    <span class="badge bg-secondary ms-1">хотя бы один</span>
                                </h6>
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_instruction_id" id="r_instr">
                                            <label class="form-check-label" for="r_instr">Instruction ID</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_end_to_end_id" id="r_e2e">
                                            <label class="form-check-label" for="r_e2e">EndToEnd ID</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_transaction_id" id="r_txn">
                                            <label class="form-check-label" for="r_txn">Transaction ID</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   v-model="editingRule.match_message_id" id="r_msg">
                                            <label class="form-check-label" for="r_msg">Message ID</label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Перекрёстный поиск -->
                                <div class="mt-3 pt-2 border-top">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox"
                                               v-model="editingRule.id_prefix_match"
                                               :disabled="!editingRule.match_instruction_id && !editingRule.match_end_to_end_id && !editingRule.match_transaction_id && !editingRule.match_message_id" id="r_id_prefix">
                                        <label class="form-check-label fw-semibold" for="r_id_prefix">
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
                                    <div class="form-text mb-2">
                                        Используется только для Transaction ID. Сравниваются первые N символов, начиная с первого.
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox"
                                               v-model="editingRule.cross_id_search" id="r_cross">
                                        <label class="form-check-label fw-semibold" for="r_cross">
                                            Перекрёстный поиск ID
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Включено: ID из любого поля записи A ищется в любом ID-поле записи B
                                        (например, Instruction_ID из Statement совпадает с EndToEnd_ID из Ledger).
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Активность + описание -->
                    <div class="col-md-3">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox"
                                   v-model="editingRule.is_active" id="r_active">
                            <label class="form-check-label fw-semibold" for="r_active">Активно</label>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Описание</label>
                        <input type="text" class="form-control" v-model="editingRule.description"
                               placeholder="Необязательный комментарий">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="closeRuleModal">Отмена</button>
                <button class="btn btn-primary" @click="saveRule">
                    <i class="fas fa-save me-1"></i>Сохранить
                </button>
            </div>
        </div>
    </div>
</div>
