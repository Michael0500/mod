<?php /** @var yii\web\View $this */ ?>

<!-- ══════════════════════════ Категория — Создать ══════════════════════════ -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-folder-plus"></i></span>
                    Новая категория
                </h5>
                <button type="button" class="btn-close" @click="closeAddCategoryModal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="newCategory.name" placeholder="Например: NRE банки Европы">
                </div>
                <div>
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="newCategory.description" rows="2" placeholder="Необязательно..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeAddCategoryModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="createCategory"><i class="fas fa-plus"></i>Создать</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Категория — Редактировать ══════════════════════════ -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon blue"><i class="fas fa-pen"></i></span>
                    Редактировать категорию
                </h5>
                <button type="button" class="btn-close" @click="closeEditCategoryModal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="editingCategory.name">
                </div>
                <div>
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="editingCategory.description" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeEditCategoryModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="updateCategory"><i class="fas fa-save"></i>Сохранить</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Ностро-банк — Создать (из сайдбара) ══════════════════════════ -->
<div class="modal fade" id="addPoolModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon green"><i class="fas fa-landmark"></i></span>
                    Новый ностро-банк
                    <span v-if="newPool.category_name" style="font-weight:400;color:#9ca3af;font-size:13px;margin-left:6px">
                        — {{ newPool.category_name }}
                    </span>
                </h5>
                <button type="button" class="btn-close" @click="_hideModal('addPoolModal')"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="newPool.name"
                           placeholder="Например: Deutsche Bank AG" maxlength="100"
                           ref="addPoolNameInput"
                           @keyup.enter="createPoolFromSidebar">
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="newPool.description" rows="2" placeholder="Необязательно..."></textarea>
                </div>

                <hr style="border-color:#e5e7eb;margin:12px 0">
                <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">
                    <i class="fas fa-link me-1"></i> Привязка счетов
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" style="font-size:13px">
                            <span style="display:inline-block;width:18px;height:18px;line-height:18px;text-align:center;border-radius:4px;font-size:10px;font-weight:800;background:#dbeafe;color:#1d4ed8;margin-right:4px">L</span>
                            Ledger счета
                        </label>
                        <div v-if="loadingPoolAccounts" style="font-size:12px;color:#9ca3af;padding:6px 0">
                            <i class="fas fa-spinner fa-spin me-1"></i> Загрузка...
                        </div>
                        <select v-else id="add-pool-ledger-select2" style="width:100%"></select>
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px">Необязательно · можно выбрать несколько</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label" style="font-size:13px">
                            <span style="display:inline-block;width:18px;height:18px;line-height:18px;text-align:center;border-radius:4px;font-size:10px;font-weight:800;background:#dcfce7;color:#15803d;margin-right:4px">S</span>
                            Statement счета
                        </label>
                        <div v-if="loadingPoolAccounts" style="font-size:12px;color:#9ca3af;padding:6px 0">
                            <i class="fas fa-spinner fa-spin me-1"></i> Загрузка...
                        </div>
                        <select v-else id="add-pool-statement-select2" style="width:100%"></select>
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px">Необязательно · можно выбрать несколько</div>
                    </div>
                </div>

                <div style="font-size:11.5px;color:#6b7280;margin-top:14px;padding:8px 12px;background:#f5f3ff;border-radius:8px;border-left:3px solid #6366f1">
                    <i class="fas fa-info-circle me-1" style="color:#6366f1"></i>
                    Ностро-банк будет создан в категории <strong>{{ newPool.category_name || '—' }}</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="_hideModal('addPoolModal')"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save-green" @click="createPoolFromSidebar" :disabled="!newPool.name">
                    <i class="fas fa-plus"></i>Создать
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Ностро-банк — Переместить в категорию ══════════════════════════ -->
<div class="modal fade" id="movePoolModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon blue"><i class="fas fa-arrows-alt"></i></span>
                    Переместить ностро-банк
                </h5>
                <button type="button" class="btn-close" @click="_hideModal('movePoolModal')"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3" style="font-size:13px;color:#374151">
                    <i class="fas fa-landmark me-1" style="color:#4f46e5"></i>
                    <strong>{{ movingPool.name }}</strong>
                    <div v-if="movingPool.from_category_name" style="font-size:11.5px;color:#9ca3af;margin-top:2px">
                        Сейчас в категории: <em>{{ movingPool.from_category_name }}</em>
                    </div>
                </div>
                <div>
                    <label class="form-label">Новая категория</label>
                    <select class="form-select" v-model="movingPool.target_category_id">
                        <option value="">— Без категории —</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                            {{ cat.name }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="_hideModal('movePoolModal')"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save" @click="confirmMovePool"><i class="fas fa-check"></i>Переместить</button>
            </div>
        </div>
    </div>
</div>
