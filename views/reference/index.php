<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Справочники — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="references-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#0ea5e9,#6366f1);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-book" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Справочники</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">Валюты и страны для всей системы</div>
            </div>
        </div>
    </div>

    <!-- ══ ВКЛАДКИ ══════════════════════════════════════════════ -->
    <ul class="nav nav-pills mb-3" style="gap:6px">
        <li class="nav-item">
            <a class="nav-link" :class="{active:tab==='currencies'}" href="#" @click.prevent="tab='currencies'">
                <i class="fas fa-coins me-1"></i>Валюты
                <span class="ref-counter">{{ currencies.length }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" :class="{active:tab==='countries'}" href="#" @click.prevent="tab='countries'">
                <i class="fas fa-globe-europe me-1"></i>Страны
                <span class="ref-counter">{{ countries.length }}</span>
            </a>
        </li>
    </ul>

    <!-- ══ ВАЛЮТЫ ═══════════════════════════════════════════════ -->
    <div v-if="tab==='currencies'">
        <div class="sm-card" style="padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="text" class="form-control form-control-sm" v-model="currencySearch"
                   placeholder="Поиск по коду или названию..." style="width:260px">
            <span v-if="filteredCurrencies.length !== currencies.length" style="font-size:11px;color:#9ca3af">
                Показано {{ filteredCurrencies.length }} из {{ currencies.length }}
            </span>
            <div style="margin-left:auto">
                <button class="btn-action btn-primary-violet" @click="showCurrencyModal()">
                    <i class="fas fa-plus me-1"></i>Добавить валюту
                </button>
            </div>
        </div>

        <div class="sm-card" style="padding:0;overflow:hidden">
            <table class="ref-table">
                <thead>
                <tr>
                    <th style="width:80px">Код</th>
                    <th>Название</th>
                    <th style="width:80px">Символ</th>
                    <th style="width:130px;text-align:center">FRACTIONAL DIGIT</th>
                    <th style="width:80px;text-align:center">Активна</th>
                    <th style="width:80px;text-align:center">Порядок</th>
                    <th style="width:100px"></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="c in filteredCurrencies" :key="c.id">
                    <td><span class="ref-badge ref-badge-code">{{ c.code }}</span></td>
                    <td style="font-weight:600">{{ c.name }}</td>
                    <td>{{ c.symbol || '—' }}</td>
                    <td style="text-align:center">{{ c.fractional_digit }}</td>
                    <td style="text-align:center">
                        <i v-if="c.is_active" class="fas fa-check-circle" style="color:#059669"></i>
                        <i v-else class="fas fa-times-circle" style="color:#d1d5db"></i>
                    </td>
                    <td style="text-align:center;color:#9ca3af">{{ c.sort_order }}</td>
                    <td style="text-align:right">
                        <button class="ref-btn-icon" @click="showCurrencyModal(c)" title="Редактировать">
                            <i class="fas fa-pen" style="color:#6366f1"></i>
                        </button>
                        <button class="ref-btn-icon" @click="deleteCurrency(c)" title="Удалить">
                            <i class="fas fa-trash-alt" style="color:#ef4444"></i>
                        </button>
                    </td>
                </tr>
                <tr v-if="!filteredCurrencies.length">
                    <td colspan="7" style="text-align:center;color:#9ca3af;padding:30px">Валюты не найдены</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ СТРАНЫ ═══════════════════════════════════════════════ -->
    <div v-if="tab==='countries'">
        <div class="sm-card" style="padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="text" class="form-control form-control-sm" v-model="countrySearch"
                   placeholder="Поиск по коду или названию..." style="width:260px">
            <span v-if="filteredCountries.length !== countries.length" style="font-size:11px;color:#9ca3af">
                Показано {{ filteredCountries.length }} из {{ countries.length }}
            </span>
            <div style="margin-left:auto">
                <button class="btn-action btn-primary-violet" @click="showCountryModal()">
                    <i class="fas fa-plus me-1"></i>Добавить страну
                </button>
            </div>
        </div>

        <div class="sm-card" style="padding:0;overflow:hidden">
            <table class="ref-table">
                <thead>
                <tr>
                    <th style="width:80px">Код (2)</th>
                    <th style="width:80px">Код (3)</th>
                    <th>Название</th>
                    <th style="width:80px;text-align:center">Активна</th>
                    <th style="width:80px;text-align:center">Порядок</th>
                    <th style="width:100px"></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="c in filteredCountries" :key="c.id">
                    <td><span class="ref-badge ref-badge-code">{{ c.code }}</span></td>
                    <td style="color:#6b7280">{{ c.code3 || '—' }}</td>
                    <td style="font-weight:600">{{ c.name }}</td>
                    <td style="text-align:center">
                        <i v-if="c.is_active" class="fas fa-check-circle" style="color:#059669"></i>
                        <i v-else class="fas fa-times-circle" style="color:#d1d5db"></i>
                    </td>
                    <td style="text-align:center;color:#9ca3af">{{ c.sort_order }}</td>
                    <td style="text-align:right">
                        <button class="ref-btn-icon" @click="showCountryModal(c)" title="Редактировать">
                            <i class="fas fa-pen" style="color:#6366f1"></i>
                        </button>
                        <button class="ref-btn-icon" @click="deleteCountry(c)" title="Удалить">
                            <i class="fas fa-trash-alt" style="color:#ef4444"></i>
                        </button>
                    </td>
                </tr>
                <tr v-if="!filteredCountries.length">
                    <td colspan="6" style="text-align:center;color:#9ca3af;padding:30px">Страны не найдены</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ МОДАЛКА ВАЛЮТЫ ═══════════════════════════════════════ -->
    <div class="modal fade" id="currencyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-coins me-2" style="color:#6366f1"></i>
                        {{ currencyForm.id ? 'Редактировать валюту' : 'Новая валюта' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Код (ISO 4217) <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-control text-uppercase" v-model="currencyForm.code"
                                   placeholder="USD" maxlength="3" :disabled="!!currencyForm.id">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-control" v-model="currencyForm.name"
                                   placeholder="Доллар США" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Символ</label>
                            <input type="text" class="form-control" v-model="currencyForm.symbol"
                                   placeholder="$" maxlength="8">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">FRACTIONAL DIGIT</label>
                            <input type="number" class="form-control" v-model.number="currencyForm.fractional_digit"
                                   min="0" max="5" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Порядок</label>
                            <input type="number" class="form-control" v-model.number="currencyForm.sort_order">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <label class="ref-check">
                                <input type="checkbox" v-model="currencyForm.is_active">
                                <span>Активна</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="saveCurrency"
                            :disabled="!currencyForm.code.trim() || !currencyForm.name.trim() || saving">
                        <i v-if="saving" class="fas fa-spinner fa-spin me-1"></i>
                        {{ currencyForm.id ? 'Сохранить' : 'Создать' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ МОДАЛКА СТРАНЫ ═══════════════════════════════════════ -->
    <div class="modal fade" id="countryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-globe-europe me-2" style="color:#6366f1"></i>
                        {{ countryForm.id ? 'Редактировать страну' : 'Новая страна' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Код (2) <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-control text-uppercase" v-model="countryForm.code"
                                   placeholder="RU" maxlength="2" :disabled="!!countryForm.id">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Код (3)</label>
                            <input type="text" class="form-control text-uppercase" v-model="countryForm.code3"
                                   placeholder="RUS" maxlength="3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-control" v-model="countryForm.name"
                                   placeholder="Россия" maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Порядок</label>
                            <input type="number" class="form-control" v-model.number="countryForm.sort_order">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <label class="ref-check">
                                <input type="checkbox" v-model="countryForm.is_active">
                                <span>Активна</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="saveCountry"
                            :disabled="!countryForm.code.trim() || !countryForm.name.trim() || saving">
                        <i v-if="saving" class="fas fa-spinner fa-spin me-1"></i>
                        {{ countryForm.id ? 'Сохранить' : 'Создать' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
.ref-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.ref-table th {
    padding: 10px 12px;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #6b7280;
    border-bottom: 2px solid #e5e7eb;
    background: #f9fafb;
    white-space: nowrap;
}
.ref-table td {
    padding: 9px 12px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
    vertical-align: middle;
}
.ref-table tr:last-child td { border-bottom: none; }
.ref-table tr:hover td { background: #fafbff; }

.ref-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}
.ref-badge-code { background: #e0e7ff; color: #3730a3; font-family: monospace; }

.ref-btn-icon {
    border: none;
    background: none;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: background .15s;
}
.ref-btn-icon:hover { background: #f3f4f6; }

.ref-check {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    color: #374151;
    cursor: pointer;
    margin: 0;
}
.ref-check input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #4f46e5;
}

.ref-counter {
    display: inline-block;
    background: #e5e7eb;
    color: #4b5563;
    border-radius: 10px;
    padding: 0 7px;
    font-size: 10px;
    font-weight: 700;
    margin-left: 5px;
}
.nav-pills .nav-link.active .ref-counter { background: rgba(255,255,255,.3); color: #fff; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var _init = <?= $initJson ?>;

    new Vue({
        el: '#references-app',

        data: function () {
            return {
                tab: 'currencies',
                currencies: _init.currencies || [],
                countries:  _init.countries  || [],

                currencySearch: '',
                countrySearch:  '',

                saving: false,

                currencyForm: this._emptyCurrencyForm(),
                countryForm:  this._emptyCountryForm(),
            };
        },

        computed: {
            filteredCurrencies: function () {
                var q = this.currencySearch.trim().toLowerCase();
                if (!q) return this.currencies;
                return this.currencies.filter(function (c) {
                    return c.code.toLowerCase().indexOf(q) !== -1
                        || c.name.toLowerCase().indexOf(q) !== -1;
                });
            },
            filteredCountries: function () {
                var q = this.countrySearch.trim().toLowerCase();
                if (!q) return this.countries;
                return this.countries.filter(function (c) {
                    return c.code.toLowerCase().indexOf(q) !== -1
                        || (c.code3 && c.code3.toLowerCase().indexOf(q) !== -1)
                        || c.name.toLowerCase().indexOf(q) !== -1;
                });
            },
        },

        methods: {
            _emptyCurrencyForm: function () {
                return { id: null, code: '', name: '', symbol: '', fractional_digit: 2, is_active: true, sort_order: 0 };
            },
            _emptyCountryForm: function () {
                return { id: null, code: '', code3: '', name: '', is_active: true, sort_order: 0 };
            },
            _modal: function (id, action) {
                var el = document.getElementById(id);
                if (!el) return;
                var inst = bootstrap.Modal.getInstance(el);
                if (action === 'show') inst ? inst.show() : new bootstrap.Modal(el).show();
                else if (inst) inst.hide();
            },

            // ── Валюты ────────────────────────────────────────
            showCurrencyModal: function (c) {
                this.currencyForm = c
                    ? Object.assign({}, c)
                    : this._emptyCurrencyForm();
                this._modal('currencyModal', 'show');
            },
            saveCurrency: function () {
                var self = this;
                self.saving = true;
                var url = self.currencyForm.id
                    ? '<?= Url::to(['/reference/currency-update']) ?>'
                    : '<?= Url::to(['/reference/currency-create']) ?>';
                var payload = {
                    code:       (self.currencyForm.code || '').toUpperCase().trim(),
                    name:       self.currencyForm.name,
                    symbol:     self.currencyForm.symbol || '',
                    fractional_digit: self.currencyForm.fractional_digit,
                    is_active:  self.currencyForm.is_active ? '1' : '0',
                    sort_order: self.currencyForm.sort_order || 0,
                };
                if (self.currencyForm.id) payload.id = self.currencyForm.id;

                axios.post(url, payload).then(function (r) {
                    if (r.data.success) {
                        self._modal('currencyModal', 'hide');
                        if (self.currencyForm.id) {
                            var idx = self.currencies.findIndex(function (x) { return x.id === self.currencyForm.id; });
                            if (idx !== -1) self.$set(self.currencies, idx, r.data.data);
                        } else {
                            self.currencies.push(r.data.data);
                            self.currencies.sort(function (a, b) {
                                return a.sort_order - b.sort_order || a.code.localeCompare(b.code);
                            });
                        }
                        Swal.fire({ icon: 'success', title: r.data.message, timer: 1500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: r.data.message });
                    }
                }).catch(function () {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
                }).finally(function () { self.saving = false; });
            },
            deleteCurrency: function (c) {
                var self = this;
                Swal.fire({
                    title: 'Удалить валюту?',
                    html: 'Валюта <b>' + c.code + '</b> будет удалена.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Удалить',
                    cancelButtonText:  'Отмена',
                    confirmButtonColor: '#ef4444',
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    axios.post('<?= Url::to(['/reference/currency-delete']) ?>', { id: c.id }).then(function (res) {
                        if (res.data.success) {
                            self.currencies = self.currencies.filter(function (x) { return x.id !== c.id; });
                            Swal.fire({ icon: 'success', title: res.data.message, timer: 1500, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                        }
                    });
                });
            },

            // ── Страны ────────────────────────────────────────
            showCountryModal: function (c) {
                this.countryForm = c
                    ? Object.assign({}, c, { code3: c.code3 || '' })
                    : this._emptyCountryForm();
                this._modal('countryModal', 'show');
            },
            saveCountry: function () {
                var self = this;
                self.saving = true;
                var url = self.countryForm.id
                    ? '<?= Url::to(['/reference/country-update']) ?>'
                    : '<?= Url::to(['/reference/country-create']) ?>';
                var payload = {
                    code:       (self.countryForm.code  || '').toUpperCase().trim(),
                    code3:      (self.countryForm.code3 || '').toUpperCase().trim(),
                    name:       self.countryForm.name,
                    is_active:  self.countryForm.is_active ? '1' : '0',
                    sort_order: self.countryForm.sort_order || 0,
                };
                if (self.countryForm.id) payload.id = self.countryForm.id;

                axios.post(url, payload).then(function (r) {
                    if (r.data.success) {
                        self._modal('countryModal', 'hide');
                        if (self.countryForm.id) {
                            var idx = self.countries.findIndex(function (x) { return x.id === self.countryForm.id; });
                            if (idx !== -1) self.$set(self.countries, idx, r.data.data);
                        } else {
                            self.countries.push(r.data.data);
                            self.countries.sort(function (a, b) {
                                return a.sort_order - b.sort_order || a.name.localeCompare(b.name);
                            });
                        }
                        Swal.fire({ icon: 'success', title: r.data.message, timer: 1500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: r.data.message });
                    }
                }).catch(function () {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
                }).finally(function () { self.saving = false; });
            },
            deleteCountry: function (c) {
                var self = this;
                Swal.fire({
                    title: 'Удалить страну?',
                    html: 'Страна <b>' + c.name + '</b> будет удалена.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Удалить',
                    cancelButtonText:  'Отмена',
                    confirmButtonColor: '#ef4444',
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    axios.post('<?= Url::to(['/reference/country-delete']) ?>', { id: c.id }).then(function (res) {
                        if (res.data.success) {
                            self.countries = self.countries.filter(function (x) { return x.id !== c.id; });
                            Swal.fire({ icon: 'success', title: res.data.message, timer: 1500, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                        }
                    });
                });
            },
        }
    });

});
</script>
