<?php /** @var yii\web\View $this */ ?>

<div>
    <div class="section-toolbar">
        <div class="section-title">
            <i class="fas fa-archive" style="color:#4f46e5;margin-right:6px"></i>
            Архив балансов
            <span v-if="balanceArchiveTotal > 0" style="font-size:11px;color:#9ca3af;margin-left:4px">
                {{ balanceArchiveTotal.toLocaleString() }} {{ recordText(balanceArchiveTotal) }}
            </span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <button type="button" class="toolbar-btn outline" @click.prevent="toggleBalanceArchiveFilters"
                    :style="(balanceArchiveFiltersOpen || activeBalanceArchiveFilterCount()>0) ? 'border-color:#4f46e5;color:#4f46e5' : ''">
                <i class="fas fa-filter"></i>Фильтры
                <span v-if="activeBalanceArchiveFilterCount()>0"
                      style="background:#4f46e5;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px">
                    {{ activeBalanceArchiveFilterCount() }}
                </span>
            </button>
            <button type="button" class="toolbar-btn outline" @click.prevent="openBalanceArchiveSettings">
                <i class="fas fa-cog"></i>Настройки
            </button>
            <button type="button" class="toolbar-btn outline" @click.prevent="purgeExpiredBalanceArchive" :disabled="balanceArchivePurging">
                <i :class="balanceArchivePurging?'fas fa-spinner fa-spin':'fas fa-trash-alt'" style="color:#ef4444"></i>
                Очистить просроченные
            </button>
            <button type="button" class="toolbar-btn primary" @click.prevent="runBalanceArchive" :disabled="balanceArchiveRunning">
                <i :class="balanceArchiveRunning?'fas fa-spinner fa-spin':'fas fa-archive'"></i>
                {{ balanceArchiveRunning ? 'Архивирование...' : 'Архивировать сейчас' }}
            </button>
            <div style="position:relative">
                <button type="button" class="toolbar-btn outline" @click.prevent="toggleBalanceArchiveColsDropdown"
                        data-balance-archive-col-toggle
                        :style="showBalanceArchiveColsDropdown ? 'border-color:#4f46e5;color:#4f46e5' : ''">
                    <i class="fas fa-columns"></i>Столбцы
                </button>
                <div v-if="showBalanceArchiveColsDropdown" class="col-mgr-dropdown">
                    <div class="col-mgr-title">Видимые столбцы</div>
                    <label v-for="col in balanceArchiveTableColumns" :key="col.key" class="col-mgr-item">
                        <input type="checkbox" v-model="col.visible">
                        {{ col.label }}
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div v-show="balanceArchiveProgressOpen" class="modal-backdrop-custom" style="display:none;z-index:9999">
        <div class="modal-card" style="max-width:420px;text-align:center">
            <div class="modal-card-header" style="justify-content:center;border-bottom:0">
                <span style="font-size:15px;font-weight:700">
                    <i class="fas fa-archive me-2" style="color:#4f46e5"></i>
                    Архивирование балансов
                </span>
            </div>
            <div class="modal-card-body" style="padding:24px 32px 28px">
                <div style="background:#f3f4f6;border-radius:99px;height:18px;overflow:hidden;margin-bottom:14px">
                    <div :style="{
                        width: balanceArchiveProgressPct + '%',
                        height: '100%',
                        background: 'linear-gradient(90deg,#4f46e5,#2563eb)',
                        borderRadius: '99px',
                        transition: 'width .3s ease'
                    }"></div>
                </div>
                <div style="font-size:28px;font-weight:700;color:#1a1f36;line-height:1">
                    {{ balanceArchiveProgressPct }}%
                </div>
                <div style="margin-top:8px;font-size:13px;color:#6b7280">
                    Заархивировано:
                    <strong style="color:#4f46e5">{{ balanceArchiveProgressDone.toLocaleString() }}</strong>
                    из
                    <strong>{{ balanceArchiveProgressAll.toLocaleString() }}</strong>
                </div>
                <div style="margin-top:18px;font-size:12px;color:#9ca3af">
                    <i class="fas fa-info-circle me-1"></i>
                    Не закрывайте страницу до завершения
                </div>
            </div>
        </div>
    </div>

    <div v-if="balanceArchiveStats" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div class="stat-chip stat-chip-purple">
            <i class="fas fa-archive"></i>
            <span>В архиве: <strong>{{ balanceArchiveStats.total_archived.toLocaleString() }}</strong></span>
        </div>
        <div class="stat-chip" :class="balanceArchiveStats.pending_archive>0?'stat-chip-orange':'stat-chip-gray'">
            <i class="fas fa-clock"></i>
            <span>Ожидают архивирования: <strong>{{ balanceArchiveStats.pending_archive.toLocaleString() }}</strong></span>
        </div>
        <div class="stat-chip" :class="balanceArchiveStats.expired_records>0?'stat-chip-red':'stat-chip-gray'">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Просрочено: <strong>{{ balanceArchiveStats.expired_records.toLocaleString() }}</strong></span>
        </div>
        <div class="stat-chip stat-chip-gray">
            <i class="fas fa-sliders-h"></i>
            <span>После даты баланса: <strong>{{ balanceArchiveStats.settings.archive_after_days }} дн.</strong></span>
        </div>
    </div>

    <div v-if="balanceArchiveFiltersOpen" class="filters-panel" style="margin-bottom:14px"
         @click.stop @change.stop @input.stop @submit.prevent.stop>
        <div style="display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-end">
            <div class="filter-field" style="min-width:200px">
                <label class="filter-label">Ностро банк</label>
                <select id="balance-archive-pool-select2" style="width:100%"></select>
            </div>
            <div class="filter-field" style="min-width:200px">
                <label class="filter-label">Ностро счёт</label>
                <select id="balance-archive-account-select2" style="width:100%"></select>
            </div>
            <div class="filter-field">
                <label class="filter-label">Тип</label>
                <div class="filter-toggle-group">
                    <button type="button" class="ftg-btn" :class="{active:!balanceArchiveFilters.ls_type}"
                            @click.prevent="applyBalanceArchiveFilter('ls_type','')">Все</button>
                    <button type="button" class="ftg-btn" :class="{active:balanceArchiveFilters.ls_type==='L'}"
                            @click.prevent="applyBalanceArchiveFilter('ls_type','L')">L</button>
                    <button type="button" class="ftg-btn" :class="{active:balanceArchiveFilters.ls_type==='S'}"
                            @click.prevent="applyBalanceArchiveFilter('ls_type','S')">S</button>
                </div>
            </div>
            <div class="filter-field">
                <label class="filter-label">Раздел</label>
                <template v-if="balanceArchiveUserSection">
                    <span style="background:#4f46e5;color:#fff;border-radius:6px;padding:3px 14px;font-size:12px;font-weight:700;display:inline-block">
                        {{ balanceArchiveUserSection }}
                    </span>
                </template>
                <template v-else>
                    <div class="filter-toggle-group">
                        <button type="button" class="ftg-btn" :class="{active:!balanceArchiveFilters.section}"
                                @click.prevent="applyBalanceArchiveFilter('section','')">Все</button>
                        <button type="button" class="ftg-btn" :class="{active:balanceArchiveFilters.section==='NRE'}"
                                @click.prevent="applyBalanceArchiveFilter('section','NRE')">NRE</button>
                        <button type="button" class="ftg-btn" :class="{active:balanceArchiveFilters.section==='INV'}"
                                @click.prevent="applyBalanceArchiveFilter('section','INV')">INV</button>
                    </div>
                </template>
            </div>
            <div class="filter-field">
                <label class="filter-label">Статус</label>
                <select class="filter-input" :value="balanceArchiveFilters.status||''"
                        @change="applyBalanceArchiveFilter('status',$event.target.value)">
                    <option value="">Все</option>
                    <option value="normal">normal</option>
                    <option value="confirmed">confirmed</option>
                    <option value="error">error</option>
                </select>
            </div>
            <div class="filter-field" style="min-width:220px">
                <label class="filter-label">Валюта (можно несколько)</label>
                <select id="balance-archive-currency-select2" multiple="multiple" style="width:100%"></select>
            </div>
            <div class="filter-field">
                <label class="filter-label">Дата с</label>
                <div class="filter-input-wrap">
                    <input type="text" v-datepicker class="filter-input"
                           :value="balanceArchiveFilters.value_date_from||''"
                           @change="applyBalanceArchiveFilter('value_date_from',$event.target.value)">
                    <button v-if="balanceArchiveFilters.value_date_from" type="button" class="filter-clear-btn"
                            @click.prevent="clearBalanceArchiveFilter('value_date_from')">×</button>
                </div>
            </div>
            <div class="filter-field">
                <label class="filter-label">Дата по</label>
                <div class="filter-input-wrap">
                    <input type="text" v-datepicker class="filter-input"
                           :value="balanceArchiveFilters.value_date_to||''"
                           @change="applyBalanceArchiveFilter('value_date_to',$event.target.value)">
                    <button v-if="balanceArchiveFilters.value_date_to" type="button" class="filter-clear-btn"
                            @click.prevent="clearBalanceArchiveFilter('value_date_to')">×</button>
                </div>
            </div>
            <div class="filter-field">
                <label class="filter-label">Архивирован от</label>
                <div class="filter-input-wrap">
                    <input type="text" v-datepicker class="filter-input"
                           :value="balanceArchiveFilters.archived_at_from||''"
                           @change="applyBalanceArchiveFilter('archived_at_from',$event.target.value)">
                    <button v-if="balanceArchiveFilters.archived_at_from" type="button" class="filter-clear-btn"
                            @click.prevent="clearBalanceArchiveFilter('archived_at_from')">×</button>
                </div>
            </div>
            <div class="filter-field">
                <label class="filter-label">Архивирован до</label>
                <div class="filter-input-wrap">
                    <input type="text" v-datepicker class="filter-input"
                           :value="balanceArchiveFilters.archived_at_to||''"
                           @change="applyBalanceArchiveFilter('archived_at_to',$event.target.value)">
                    <button v-if="balanceArchiveFilters.archived_at_to" type="button" class="filter-clear-btn"
                            @click.prevent="clearBalanceArchiveFilter('archived_at_to')">×</button>
                </div>
            </div>
            <div class="filter-field">
                <label class="filter-label">Источник</label>
                <select class="filter-input" :value="balanceArchiveFilters.source||''"
                        @change="applyBalanceArchiveFilter('source',$event.target.value)">
                    <option value="">Все</option>
                    <option value="BND">БНД</option><option value="ASB">АСБ</option>
                    <option value="MT950">MT950</option><option value="CAMT">camt</option>
                    <option value="ED211">ED211</option><option value="FCC12">FCC12</option>
                    <option value="BARS_GL">BARS GL</option><option value="MANUAL">Ручной</option>
                </select>
            </div>
            <div class="filter-field">
                <label class="filter-label">Поле поиска</label>
                <select class="filter-input" :value="balanceArchiveFilters.search_field||''"
                        @change="applyBalanceArchiveFilter('search_field',$event.target.value)">
                    <option value="">— Все поля —</option>
                    <option value="statement_number">№ выписки</option>
                    <option value="comment">Комментарий</option>
                    <option value="branch_code">Филиал</option>
                    <option value="extract_no">Extract No</option>
                    <option value="stmt_id">Stmt ID</option>
                    <option value="edno">ED No</option>
                    <option value="edauthor">ED Author</option>
                </select>
            </div>
            <div class="filter-field" style="min-width:180px">
                <label class="filter-label">Значение</label>
                <div class="filter-input-wrap">
                    <input type="text" class="filter-input" placeholder="Поиск..."
                           :value="balanceArchiveFilters.search_value||''"
                           @input="debouncedBalanceArchiveFilter('search_value',$event.target.value)">
                    <button v-if="balanceArchiveFilters.search_value" type="button" class="filter-clear-btn"
                            @click.prevent="clearBalanceArchiveFilter('search_value')">×</button>
                </div>
            </div>
            <div class="filter-field" style="align-self:flex-end">
                <button type="button" class="toolbar-btn outline" style="font-size:11px;padding:4px 10px"
                        @click.prevent="clearAllBalanceArchiveFilters">
                    <i class="fas fa-times"></i>Сбросить
                </button>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div v-if="balanceArchiveLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
            <div class="spinner-border" style="color:#4f46e5"></div>
        </div>
        <div v-else-if="balanceArchiveRows.length===0" class="empty-pool" style="padding:60px">
            <i class="fas fa-archive" style="opacity:.3"></i>
            <p>Архив балансов пуст</p>
            <p style="font-size:12px;color:#9ca3af;margin-top:4px">
                Старые балансы будут перенесены сюда после архивирования
            </p>
        </div>
        <div v-else class="table-scroll-wrap" @scroll="onBalanceArchiveScroll">
            <table class="entries-table">
                <thead>
                <tr>
                    <th v-for="col in balanceArchiveTableColumns"
                        v-show="balanceArchiveColVisible(col.key)"
                        :key="col.key"
                        class="th-sort th-resizable"
                        @click="sortBalanceArchive(col.key)"
                        :style="{width: col.width+'px', minWidth: col.width+'px', textAlign: (col.key==='opening_balance'||col.key==='closing_balance') ? 'right' : ''}">
                        <span>{{ col.label }}</span> <i :class="balanceArchiveSortIcon(col.key)"></i>
                        <div class="col-resize-handle" @mousedown.stop.prevent="startBalanceArchiveColResize($event, col)" @click.stop></div>
                    </th>
                    <th style="width:90px;min-width:90px;text-align:right;padding-right:12px">
                        <i class="fas fa-cog" style="opacity:.3"></i>
                    </th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="row in balanceArchiveRows" :key="row.id"
                    :style="isBalanceArchiveExpired(row.expires_at)?'background:#fff5f5':''">
                    <td v-for="col in balanceArchiveTableColumns"
                        v-show="balanceArchiveColVisible(col.key)"
                        :key="col.key"
                        :style="{textAlign: (col.key==='opening_balance'||col.key==='closing_balance') ? 'right' : ''}">
                        <template v-if="col.key==='account_id'">
                            <div :title="row.account_name + (row.pool_name ? ' · ' + row.pool_name : '')"
                                 style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <div style="font-size:12px;font-weight:600;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    {{ row.account_name || '—' }}
                                </div>
                                <div v-if="row.pool_name" style="font-size:10px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    <i class="fas fa-landmark" style="font-size:9px;color:#4f46e5"></i>
                                    {{ row.pool_name }}
                                </div>
                            </div>
                        </template>
                        <template v-else-if="col.key==='ls_type'">
                            <span :style="row.ls_type==='L'
                                ?'background:#dbeafe;color:#1e40af;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'
                                :'background:#fef3c7;color:#92400e;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'">
                                {{ row.ls_type }}
                            </span>
                        </template>
                        <template v-else-if="col.key==='section'">
                            <span style="background:#1e2532;color:#fff;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700">
                                {{ row.section }}
                            </span>
                        </template>
                        <template v-else-if="col.key==='opening_balance'">
                            <span style="font-family:monospace;font-size:12px">{{ formatBalanceArchiveAmount(row.opening_balance, row.currency) }}</span>
                        </template>
                        <template v-else-if="col.key==='closing_balance'">
                            <span style="font-family:monospace;font-size:12px">{{ formatBalanceArchiveAmount(row.closing_balance, row.currency) }}</span>
                        </template>
                        <template v-else-if="col.key==='opening_dc' || col.key==='closing_dc'">
                            <span :style="row[col.key]==='D'?'color:#ef4444;font-weight:700;font-size:11px':'color:#059669;font-weight:700;font-size:11px'">
                                {{ row[col.key] || '—' }}
                            </span>
                        </template>
                        <template v-else-if="col.key==='status'">
                            <span :title="row.comment" style="font-size:11px">
                                {{ balanceArchiveStatusLabel(row.status) }}
                            </span>
                        </template>
                        <template v-else-if="col.key==='value_date'">
                            <span style="white-space:nowrap;font-size:12px">{{ row.value_date_fmt || row.value_date || '—' }}</span>
                        </template>
                        <template v-else-if="col.key==='archived_at'">
                            <span style="white-space:nowrap;font-size:11px;color:#6b7280">{{ row.archived_at_fmt || '—' }}</span>
                        </template>
                        <template v-else-if="col.key==='expires_at'">
                            <span style="white-space:nowrap;font-size:11px"
                                  :style="isBalanceArchiveExpired(row.expires_at)?'color:#dc2626;font-weight:700':isBalanceArchiveExpiringSoon(row.expires_at)?'color:#d97706;font-weight:600':'color:#6b7280'">
                                <i v-if="isBalanceArchiveExpired(row.expires_at)" class="fas fa-exclamation-triangle me-1"></i>
                                {{ row.expires_at_fmt || '—' }}
                            </span>
                        </template>
                        <template v-else-if="col.key==='comment' || col.key==='statement_number'">
                            <span class="td-mono-truncate" :title="row[col.key]">{{ row[col.key] || '—' }}</span>
                        </template>
                        <template v-else>
                            <span style="font-size:12px">{{ row[col.key] || '—' }}</span>
                        </template>
                    </td>
                    <td style="text-align:right;padding-right:12px">
                        <div style="display:flex;gap:3px;justify-content:flex-end">
                            <button type="button" class="row-btn history" @click.prevent="showBalanceArchiveHistory(row)"
                                    title="История изменений">
                                <i class="fas fa-history"></i>
                            </button>
                            <button type="button" class="row-btn edit" @click.prevent="restoreBalanceFromArchive(row)"
                                    title="Восстановить в активные балансы">
                                <i class="fas fa-undo"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <div v-if="balanceArchiveLoadingMore" style="text-align:center;padding:12px">
                <div class="spinner-border spinner-border-sm" style="color:#4f46e5"></div>
            </div>
        </div>
    </div>

    <div v-show="balanceArchiveSettingsOpen" class="modal-backdrop-custom" @click.self="closeBalanceArchiveSettings">
        <div class="modal-card" style="max-width:440px">
            <div class="modal-card-header">
                <span><i class="fas fa-cog me-2"></i>Настройки архивирования балансов</span>
                <button type="button" class="btn-close" @click.prevent="closeBalanceArchiveSettings"></button>
            </div>
            <div class="modal-card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Дней после даты баланса
                            <span style="font-weight:400;color:#9ca3af;font-size:11px">(мин 1, макс 3650)</span>
                        </label>
                        <input type="number" class="form-control"
                               v-model.number="balanceArchiveSettings.archive_after_days"
                               min="1" max="3650">
                        <div class="form-text">
                            Балансы старше указанного числа дней по дате валютирования будут архивированы.
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Срок хранения в архиве (лет)</label>
                        <input type="number" class="form-control"
                               v-model.number="balanceArchiveSettings.retention_years"
                               min="1" max="20">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   v-model="balanceArchiveSettings.auto_archive_enabled" id="autoBalanceArchiveEnabled">
                            <label class="form-check-label fw-semibold" for="autoBalanceArchiveEnabled">
                                Автоматическое архивирование
                            </label>
                        </div>
                        <div class="form-text">
                            Настройка общая для архивных процессов компании.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-card-footer" style="display:flex;justify-content:flex-end;gap:8px">
                <button type="button" class="toolbar-btn outline" @click.prevent="closeBalanceArchiveSettings">Отмена</button>
                <button type="button" class="toolbar-btn primary" @click.prevent="saveBalanceArchiveSettings" :disabled="balanceArchiveSettingsSaving">
                    <i :class="balanceArchiveSettingsSaving?'fas fa-spinner fa-spin':'fas fa-save'"></i>
                    {{ balanceArchiveSettingsSaving ? 'Сохранение...' : 'Сохранить' }}
                </button>
            </div>
        </div>
    </div>

    <div v-show="balanceArchiveHistoryOpen" class="modal-backdrop-custom" @click.self="closeBalanceArchiveHistory">
        <div class="modal-card" style="max-width:900px">
            <div class="modal-card-header">
                <span>
                    <i class="fas fa-history me-2" style="color:#4f46e5"></i>
                    История баланса в архиве
                    <span v-if="balanceArchiveHistoryBalance"
                          style="font-weight:400;color:#9ca3af;font-size:12px;margin-left:8px">
                        {{ balanceArchiveHistoryBalance.account_name }} · {{ balanceArchiveHistoryBalance.ls_type }} · {{ balanceArchiveHistoryBalance.currency }} · {{ balanceArchiveHistoryBalance.value_date_fmt || balanceArchiveHistoryBalance.value_date }}
                    </span>
                </span>
                <button class="btn-close" @click="closeBalanceArchiveHistory"></button>
            </div>
            <div class="modal-card-body" style="padding:0">
                <div v-if="balanceArchiveHistoryLoading" style="text-align:center;padding:40px">
                    <div class="spinner-border spinner-border-sm" style="color:#4f46e5"></div>
                    <div style="margin-top:8px;color:#9ca3af;font-size:13px">Загрузка истории...</div>
                </div>
                <div v-else-if="!balanceArchiveHistoryLogs.length"
                     style="text-align:center;padding:48px;color:#9ca3af;font-size:13px">
                    <i class="fas fa-clock" style="font-size:36px;color:#d1d5db;display:block;margin-bottom:12px"></i>
                    История изменений пуста
                </div>
                <div v-else style="overflow-x:auto;max-height:500px;overflow-y:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                        <thead>
                        <tr style="background:#f8f9fb;position:sticky;top:0;z-index:2">
                            <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap;background:#f0f1f5">Дата / Действие</th>
                            <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap;background:#f0f1f5">Пользователь</th>
                            <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap">Причина / Комментарий</th>
                            <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap">Было</th>
                            <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap">Стало</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="log in balanceArchiveHistoryLogs" :key="log.id" style="border-bottom:1px solid #f3f4f6">
                            <td style="padding:8px 12px;vertical-align:top;white-space:nowrap;background:#fafafa;border-right:1px solid #e5e7eb">
                                <div style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px"
                                     :style="log.action==='archive'?'background:#ede9fe;color:#7c3aed':log.action==='restore'?'background:#d1fae5;color:#059669':log.action==='edit'?'background:#dbeafe;color:#2563eb':'background:#f3f4f6;color:#4b5563'">
                                    <i :class="log.action==='archive'?'fas fa-archive':log.action==='restore'?'fas fa-undo':log.action==='edit'?'fas fa-pen':'fas fa-clock'"></i>
                                    {{ balanceArchiveHistoryActionLabel(log.action) }}
                                </div>
                                <div style="font-size:11px;font-weight:600;color:#374151">{{ formatDate(log.created_at) }}</div>
                            </td>
                            <td style="padding:8px 12px;vertical-align:top;white-space:nowrap;background:#fafafa;border-right:1px solid #e5e7eb">
                                <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:#4b5563">
                                    <i class="fas fa-user-circle" style="color:#9ca3af;font-size:14px"></i>
                                    {{ log.username || ('User #' + log.user_id) }}
                                </div>
                            </td>
                            <td style="padding:8px 12px;vertical-align:top;max-width:220px">
                                <span style="font-size:12px;color:#6b7280;font-style:italic">{{ log.reason || '—' }}</span>
                            </td>
                            <td style="padding:8px 12px;vertical-align:top;max-width:240px">
                                <div v-if="log.old_values" style="font-size:11px;line-height:1.6">
                                    <div v-if="log.old_values.opening_balance !== undefined">
                                        <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Open:</span>
                                        <span style="font-family:monospace;color:#b91c1c">{{ formatBalanceArchiveAmount(log.old_values.opening_balance, log.old_values.currency || balanceArchiveHistoryBalance.currency) }} {{ log.old_values.opening_dc }}</span>
                                    </div>
                                    <div v-if="log.old_values.closing_balance !== undefined">
                                        <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Close:</span>
                                        <span style="font-family:monospace;color:#b91c1c">{{ formatBalanceArchiveAmount(log.old_values.closing_balance, log.old_values.currency || balanceArchiveHistoryBalance.currency) }} {{ log.old_values.closing_dc }}</span>
                                    </div>
                                    <div v-if="log.old_values.status">
                                        <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Статус:</span>
                                        <span style="color:#b91c1c">{{ log.old_values.status }}</span>
                                    </div>
                                </div>
                                <span v-else style="color:#d1d5db;font-size:11px">—</span>
                            </td>
                            <td style="padding:8px 12px;vertical-align:top;max-width:240px">
                                <div v-if="log.new_values" style="font-size:11px;line-height:1.6">
                                    <div v-if="log.new_values.opening_balance !== undefined">
                                        <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Open:</span>
                                        <span style="font-family:monospace;color:#059669">{{ formatBalanceArchiveAmount(log.new_values.opening_balance, log.new_values.currency || balanceArchiveHistoryBalance.currency) }} {{ log.new_values.opening_dc }}</span>
                                    </div>
                                    <div v-if="log.new_values.closing_balance !== undefined">
                                        <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Close:</span>
                                        <span style="font-family:monospace;color:#059669">{{ formatBalanceArchiveAmount(log.new_values.closing_balance, log.new_values.currency || balanceArchiveHistoryBalance.currency) }} {{ log.new_values.closing_dc }}</span>
                                    </div>
                                    <div v-if="log.new_values.status">
                                        <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Статус:</span>
                                        <span style="color:#059669">{{ log.new_values.status }}</span>
                                    </div>
                                </div>
                                <span v-else style="color:#d1d5db;font-size:11px">—</span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-card-footer" style="display:flex;justify-content:flex-end">
                <button class="toolbar-btn outline" @click="closeBalanceArchiveHistory">
                    <i class="fas fa-times me-1"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>
