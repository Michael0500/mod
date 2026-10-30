<?php /** @var yii\web\View $this */ ?>

    <div>

        <!-- Тулбар -->
        <div class="section-toolbar">
            <div class="section-title">
                <i class="fas fa-archive" style="color:#7c3aed;margin-right:6px"></i>
                Архив сквитованных записей
                <span v-if="archiveTotal > 0" style="font-size:11px;color:#9ca3af;margin-left:4px">
                        {{ archiveTotal.toLocaleString() }} {{ recordText(archiveTotal) }}
                    </span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <button type="button" class="toolbar-btn outline" @click.prevent="toggleArchiveFilters"
                        :style="(archiveFiltersOpen || activeArchiveFilterCount()>0) ? 'border-color:#7c3aed;color:#7c3aed' : ''">
                    <i class="fas fa-filter"></i>Фильтры
                    <span v-if="activeArchiveFilterCount()>0"
                          style="background:#7c3aed;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px">
                            {{ activeArchiveFilterCount() }}
                        </span>
                </button>
                <button type="button" class="toolbar-btn outline" @click.prevent="openArchiveSettings">
                    <i class="fas fa-cog"></i>Настройки
                </button>
                <button type="button" class="toolbar-btn outline" @click.prevent="purgeExpired" :disabled="archivePurging">
                    <i :class="archivePurging?'fas fa-spinner fa-spin':'fas fa-trash-alt'" style="color:#ef4444"></i>
                    Очистить просроченные
                </button>
                <button type="button" class="toolbar-btn primary" @click.prevent="runArchive" :disabled="archiveRunning">
                    <i :class="archiveRunning?'fas fa-spinner fa-spin':'fas fa-archive'"></i>
                    {{ archiveRunning ? 'Архивирование...' : 'Архивировать сейчас' }}
                </button>
                <!-- Кнопка управления колонками -->
                <div style="position:relative">
                    <button type="button" class="toolbar-btn outline" @click.prevent="toggleArchiveColsDropdown" data-archive-col-toggle
                            :style="showArchiveColsDropdown ? 'border-color:#7c3aed;color:#7c3aed' : ''">
                        <i class="fas fa-columns"></i>Столбцы
                    </button>
                    <div v-if="showArchiveColsDropdown" class="col-mgr-dropdown">
                        <div class="col-mgr-title">Видимые столбцы</div>
                        <label v-for="col in archiveTableColumns" :key="col.key" class="col-mgr-item">
                            <input type="checkbox" v-model="col.visible">
                            {{ col.label }}
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- МОДАЛКА ПРОГРЕССА АРХИВИРОВАНИЯ -->
        <div v-show="archiveProgressOpen" class="modal-backdrop-custom" style="display:none;z-index:9999">
            <div class="modal-card" style="max-width:420px;text-align:center">
                <div class="modal-card-header" style="justify-content:center;border-bottom:0">
                        <span style="font-size:15px;font-weight:700">
                            <i class="fas fa-archive me-2" style="color:#4f46e5"></i>
                            Архивирование записей
                        </span>
                </div>
                <div class="modal-card-body" style="padding:24px 32px 28px">

                    <!-- Прогресс-бар -->
                    <div style="background:#f3f4f6;border-radius:99px;height:18px;overflow:hidden;margin-bottom:14px">
                        <div :style="{
                                    width: archiveProgressPct + '%',
                                    height: '100%',
                                    background: 'linear-gradient(90deg,#4f46e5,#7c3aed)',
                                    borderRadius: '99px',
                                    transition: 'width .3s ease'
                                }"></div>
                    </div>

                    <!-- Цифры -->
                    <div style="font-size:28px;font-weight:700;color:#1a1f36;line-height:1">
                        {{ archiveProgressPct }}%
                    </div>
                    <div style="margin-top:8px;font-size:13px;color:#6b7280">
                        Заархивировано:
                        <strong style="color:#4f46e5">{{ archiveProgressDone.toLocaleString() }}</strong>
                        из
                        <strong>{{ archiveProgressAll.toLocaleString() }}</strong>
                    </div>
                    <div style="margin-top:18px;font-size:12px;color:#9ca3af">
                        <i class="fas fa-info-circle me-1"></i>
                        Не закрывайте страницу до завершения
                    </div>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div v-if="archiveStats" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
            <div class="stat-chip stat-chip-purple">
                <i class="fas fa-archive"></i>
                <span>В архиве: <strong>{{ archiveStats.total_archived.toLocaleString() }}</strong></span>
            </div>
            <div class="stat-chip" :class="archiveStats.pending_archive>0?'stat-chip-orange':'stat-chip-gray'">
                <i class="fas fa-clock"></i>
                <span>Ожидают архивирования: <strong>{{ archiveStats.pending_archive.toLocaleString() }}</strong></span>
            </div>
            <div class="stat-chip" :class="archiveStats.expired_records>0?'stat-chip-red':'stat-chip-gray'">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Просрочено (к удалению): <strong>{{ archiveStats.expired_records.toLocaleString() }}</strong></span>
            </div>
            <div class="stat-chip stat-chip-gray">
                <i class="fas fa-sliders-h"></i>
                <span>После квитования: <strong>{{ archiveStats.settings.archive_after_days }} дн.</strong></span>
            </div>
        </div>

        <!-- ФИЛЬТРЫ -->
        <div v-if="archiveFiltersOpen" class="filters-panel" style="margin-bottom:14px"
             @click.stop @change.stop @input.stop @submit.prevent.stop>
            <div style="display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-end">

                <!-- Ностро банк Select2 -->
                <div class="filter-field" style="min-width:200px">
                    <label class="filter-label">Ностро банк</label>
                    <select id="archive-pool-select2" style="width:100%"></select>
                </div>

                <!-- Ностро счёт Select2 -->
                <div class="filter-field" style="min-width:200px">
                    <label class="filter-label">Ностро счёт</label>
                    <select id="archive-account-select2" style="width:100%"></select>
                </div>

                <!-- L/S -->
                <div class="filter-field">
                    <label class="filter-label">L/S</label>
                    <div class="filter-toggle-group">
                        <button type="button" class="ftg-btn" :class="{active:!archiveFilters.ls}"
                                @click.prevent="applyArchiveFilter('ls','')">Все</button>
                        <button type="button" class="ftg-btn" :class="{active:archiveFilters.ls==='L'}"
                                @click.prevent="applyArchiveFilter('ls','L')">L</button>
                        <button type="button" class="ftg-btn" :class="{active:archiveFilters.ls==='S'}"
                                @click.prevent="applyArchiveFilter('ls','S')">S</button>
                    </div>
                </div>

                <!-- D/C -->
                <div class="filter-field">
                    <label class="filter-label">D/C</label>
                    <div class="filter-toggle-group">
                        <button type="button" class="ftg-btn" :class="{active:!archiveFilters.dc}"
                                @click.prevent="applyArchiveFilter('dc','')">Все</button>
                        <button type="button" class="ftg-btn" :class="{active:archiveFilters.dc==='Debit'}"
                                @click.prevent="applyArchiveFilter('dc','Debit')">D</button>
                        <button type="button" class="ftg-btn" :class="{active:archiveFilters.dc==='Credit'}"
                                @click.prevent="applyArchiveFilter('dc','Credit')">C</button>
                    </div>
                </div>

                <!-- Валюта -->
                <div class="filter-field" style="min-width:220px">
                    <label class="filter-label">Валюта (можно несколько)</label>
                    <select id="archive-currency-select2" multiple="multiple" style="width:100%"></select>
                </div>

                <!-- Сумма -->
                <div class="filter-field">
                    <label class="filter-label">Сумма от</label>
                    <div class="filter-input-wrap">
                        <input type="number" class="filter-input" placeholder="0"
                               :value="archiveFilters.amount_min||''"
                               @change="applyArchiveFilter('amount_min',$event.target.value)">
                        <button v-if="archiveFilters.amount_min" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('amount_min')">×</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Сумма до</label>
                    <div class="filter-input-wrap">
                        <input type="number" class="filter-input" placeholder="∞"
                               :value="archiveFilters.amount_max||''"
                               @change="applyArchiveFilter('amount_max',$event.target.value)">
                        <button v-if="archiveFilters.amount_max" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('amount_max')">×</button>
                    </div>
                </div>

                <!-- Value Date -->
                <div class="filter-field">
                    <label class="filter-label">Value Date от</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.value_date_from||''"
                               @change="applyArchiveFilter('value_date_from',$event.target.value)">
                        <button v-if="archiveFilters.value_date_from" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('value_date_from')">×</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Value Date до</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.value_date_to||''"
                               @change="applyArchiveFilter('value_date_to',$event.target.value)">
                        <button v-if="archiveFilters.value_date_to" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('value_date_to')">×</button>
                    </div>
                </div>

                <!-- Дата архивирования -->
                <div class="filter-field">
                    <label class="filter-label">Заархивирован от</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.archived_at_from||''"
                               @change="applyArchiveFilter('archived_at_from',$event.target.value)">
                        <button v-if="archiveFilters.archived_at_from" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('archived_at_from')">×</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Заархивирован до</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.archived_at_to||''"
                               @change="applyArchiveFilter('archived_at_to',$event.target.value)">
                        <button v-if="archiveFilters.archived_at_to" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('archived_at_to')">×</button>
                    </div>
                </div>

                <!-- Поиск по полю -->
                <div class="filter-field">
                    <label class="filter-label">Поле поиска</label>
                    <select class="filter-input"
                            :value="archiveFilters.search_field||''"
                            @change="applyArchiveFilter('search_field',$event.target.value)">
                        <option value="">— Все поля —</option>
                        <option value="match_id">Match ID</option>
                        <option value="instruction_id">Instruction ID</option>
                        <option value="end_to_end_id">EndToEnd ID</option>
                        <option value="transaction_id">Transaction ID</option>
                        <option value="message_id">Message ID</option>
                        <option value="other_id">Other ID</option>
                        <option value="comment">Комментарий</option>
                    </select>
                </div>
                <div class="filter-field" style="min-width:180px">
                    <label class="filter-label">Значение</label>
                    <div class="filter-input-wrap">
                        <input type="text" class="filter-input" placeholder="Поиск..."
                               :value="archiveFilters.search_value||''"
                               @input="debouncedArchiveFilter('search_value',$event.target.value)">
                        <button v-if="archiveFilters.search_value" type="button" class="filter-clear-btn"
                                @click.prevent="clearArchiveFilter('search_value')">×</button>
                    </div>
                </div>

                <!-- Сброс фильтров -->
                <div class="filter-field" style="align-self:flex-end">
                    <button type="button" class="toolbar-btn outline" style="font-size:11px;padding:4px 10px"
                            @click.prevent="clearAllArchiveFilters">
                        <i class="fas fa-times"></i>Сбросить
                    </button>
                </div>
            </div>
        </div>

        <!-- ТАБЛИЦА АРХИВА -->
        <div class="table-card">
            <div v-if="archiveLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
                <div class="spinner-border" style="color:#7c3aed"></div>
            </div>
            <div v-else-if="archiveRows.length===0" class="empty-pool" style="padding:60px">
                <i class="fas fa-archive" style="opacity:.3"></i>
                <p>Архив пуст</p>
                <p style="font-size:12px;color:#9ca3af;margin-top:4px">
                    Сквитованные записи старше {{ archiveSettings.archive_after_days }} дней от даты квитования будут перенесены сюда
                </p>
            </div>
            <div v-else class="table-scroll-wrap" @scroll="onArchiveScroll">
                <table class="entries-table">
                    <thead><tr>
                        <th v-show="archiveColVisible('id')" class="th-sort th-resizable" @click="sortArchive('id')"
                            :style="{width: archiveColByKey('id').width+'px', minWidth: archiveColByKey('id').width+'px'}">
                            <span>ID</span> <i :class="archiveSortIcon('id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('account_id')" class="th-sort th-resizable" @click="sortArchive('account_id')"
                            :style="{width: archiveColByKey('account_id').width+'px', minWidth: archiveColByKey('account_id').width+'px'}">
                            <span>Счёт</span> <i :class="archiveSortIcon('account_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('account_id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('match_id')" class="th-sort th-resizable" @click="sortArchive('match_id')"
                            :style="{width: archiveColByKey('match_id').width+'px', minWidth: archiveColByKey('match_id').width+'px'}">
                            <span>Match ID</span> <i :class="archiveSortIcon('match_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('match_id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('ls')" class="th-sort th-resizable" @click="sortArchive('ls')"
                            :style="{width: archiveColByKey('ls').width+'px', minWidth: archiveColByKey('ls').width+'px'}">
                            <span>L/S</span> <i :class="archiveSortIcon('ls')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('ls'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('dc')" class="th-sort th-resizable" @click="sortArchive('dc')"
                            :style="{width: archiveColByKey('dc').width+'px', minWidth: archiveColByKey('dc').width+'px'}">
                            <span>D/C</span> <i :class="archiveSortIcon('dc')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('dc'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('amount')" class="th-sort th-resizable" @click="sortArchive('amount')"
                            :style="{width: archiveColByKey('amount').width+'px', minWidth: archiveColByKey('amount').width+'px', textAlign:'right'}">
                            <span>Сумма</span> <i :class="archiveSortIcon('amount')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('amount'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('currency')" class="th-sort th-resizable" @click="sortArchive('currency')"
                            :style="{width: archiveColByKey('currency').width+'px', minWidth: archiveColByKey('currency').width+'px'}">
                            <span>Вал.</span> <i :class="archiveSortIcon('currency')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('currency'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('value_date')" class="th-sort th-resizable" @click="sortArchive('value_date')"
                            :style="{width: archiveColByKey('value_date').width+'px', minWidth: archiveColByKey('value_date').width+'px'}">
                            <span>Value Date</span> <i :class="archiveSortIcon('value_date')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('value_date'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('instruction_id')" class="th-resizable"
                            :style="{width: archiveColByKey('instruction_id').width+'px', minWidth: archiveColByKey('instruction_id').width+'px'}">
                            <span>Instr. ID</span>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('instruction_id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('end_to_end_id')" class="th-resizable"
                            :style="{width: archiveColByKey('end_to_end_id').width+'px', minWidth: archiveColByKey('end_to_end_id').width+'px'}">
                            <span>E2E ID</span>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('end_to_end_id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('transaction_id')" class="th-resizable"
                            :style="{width: archiveColByKey('transaction_id').width+'px', minWidth: archiveColByKey('transaction_id').width+'px'}">
                            <span>Txn ID</span>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('transaction_id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('message_id')" class="th-resizable"
                            :style="{width: archiveColByKey('message_id').width+'px', minWidth: archiveColByKey('message_id').width+'px'}">
                            <span>Msg ID</span>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('message_id'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('comment')" class="th-sort th-resizable" @click="sortArchive('comment')"
                            :style="{width: archiveColByKey('comment').width+'px', minWidth: archiveColByKey('comment').width+'px'}">
                            <span>Комментарий</span> <i :class="archiveSortIcon('comment')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('comment'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('archived_at')" class="th-sort th-resizable" @click="sortArchive('archived_at')"
                            :style="{width: archiveColByKey('archived_at').width+'px', minWidth: archiveColByKey('archived_at').width+'px'}">
                            <span>Архивирован</span> <i :class="archiveSortIcon('archived_at')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('archived_at'))" @click.stop></div>
                        </th>
                        <th v-show="archiveColVisible('expires_at')" class="th-sort th-resizable" @click="sortArchive('expires_at')"
                            :style="{width: archiveColByKey('expires_at').width+'px', minWidth: archiveColByKey('expires_at').width+'px', color:'#ef4444'}">
                            <span>Хранить до</span> <i :class="archiveSortIcon('expires_at')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startArchiveColResize($event, archiveColByKey('expires_at'))" @click.stop></div>
                        </th>
                        <th style="width:90px;min-width:90px;text-align:right;padding-right:12px">
                            <i class="fas fa-cog" style="opacity:.3"></i>
                        </th>
                    </tr></thead>
                    <tbody>
                    <tr v-for="row in archiveRows" :key="row.id"
                        :style="isExpired(row.expires_at)?'background:#fff5f5':''">
                        <td v-show="archiveColVisible('id')" style="color:#9ca3af;font-size:11px">{{ row.original_id }}</td>
                        <td v-show="archiveColVisible('account_id')" :title="row.account_name + (row.pool_name ? ' · ' + row.pool_name : '')" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <div style="font-size:12px;font-weight:600;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ row.account_name }}</div>
                            <div v-if="row.pool_name" style="font-size:10px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <i class="fas fa-landmark" style="font-size:9px;color:#4f46e5"></i>
                                {{ row.pool_name }}
                            </div>
                        </td>
                        <td v-show="archiveColVisible('match_id')" style="font-family:monospace;font-size:11px;color:#4f46e5"
                            :title="row.match_id">{{ row.match_id }}</td>
                        <td v-show="archiveColVisible('ls')">
                                    <span :style="row.ls==='L'
                                        ?'background:#dbeafe;color:#1e40af;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'
                                        :'background:#fef3c7;color:#92400e;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'">
                                        {{ row.ls }}
                                    </span>
                        </td>
                        <td v-show="archiveColVisible('dc')">
                                    <span :style="row.dc==='Debit'
                                        ?'color:#dc2626;font-weight:700;font-size:11px'
                                        :'color:#059669;font-weight:700;font-size:11px'">
                                        {{ row.dc==='Debit'?'D':'C' }}
                                    </span>
                        </td>
                        <td v-show="archiveColVisible('amount')" style="text-align:right;font-family:monospace;font-weight:600;font-size:12px">
                            {{ formatAmount(row.amount, row.currency) }}
                        </td>
                        <td v-show="archiveColVisible('currency')" style="font-weight:600;font-size:12px">{{ row.currency }}</td>
                        <td v-show="archiveColVisible('value_date')" style="white-space:nowrap;font-size:12px">{{ row.value_date_fmt||row.value_date||'—' }}</td>
                        <td v-show="archiveColVisible('instruction_id')" class="td-mono-truncate" :title="row.instruction_id">
                            {{ row.instruction_id||'—' }}
                        </td>
                        <td v-show="archiveColVisible('end_to_end_id')" class="td-mono-truncate" :title="row.end_to_end_id">
                            {{ row.end_to_end_id||'—' }}
                        </td>
                        <td v-show="archiveColVisible('transaction_id')" class="td-mono-truncate" :title="row.transaction_id">
                            {{ row.transaction_id||'—' }}
                        </td>
                        <td v-show="archiveColVisible('message_id')" class="td-mono-truncate" :title="row.message_id">
                            {{ row.message_id||'—' }}
                        </td>
                        <td v-show="archiveColVisible('comment')" class="td-mono-truncate" :title="row.comment">
                            {{ row.comment||'—' }}
                        </td>
                        <td v-show="archiveColVisible('archived_at')" style="white-space:nowrap;font-size:11px;color:#6b7280">
                            {{ row.archived_at_fmt||'—' }}
                        </td>
                        <td v-show="archiveColVisible('expires_at')" style="white-space:nowrap;font-size:11px"
                            :style="isExpired(row.expires_at)?'color:#dc2626;font-weight:700':isExpiringSoon(row.expires_at)?'color:#d97706;font-weight:600':'color:#6b7280'">
                            <i v-if="isExpired(row.expires_at)" class="fas fa-exclamation-triangle me-1"></i>
                            {{ row.expires_at_fmt||'—' }}
                        </td>
                        <td style="text-align:right;padding-right:12px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button type="button" class="row-btn history" @click.prevent="showArchiveHistory(row)"
                                        title="История изменений">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button type="button" class="row-btn edit" @click.prevent="restoreFromArchive(row)"
                                        title="Восстановить в активные записи">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <div v-if="archiveLoadingMore" style="text-align:center;padding:12px">
                    <div class="spinner-border spinner-border-sm" style="color:#7c3aed"></div>
                </div>
            </div>
        </div>

        <!-- МОДАЛ: Настройки архивирования -->
        <div v-show="archiveSettingsOpen" class="modal-backdrop-custom" @click.self="closeArchiveSettings">
            <div class="modal-card" style="max-width:440px">
                <div class="modal-card-header">
                    <span><i class="fas fa-cog me-2"></i>Настройки архивирования</span>
                    <button type="button" class="btn-close" @click.prevent="closeArchiveSettings"></button>
                </div>
                <div class="modal-card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Дней после квитования
                                <span style="font-weight:400;color:#9ca3af;font-size:11px">(мин 1, макс 3650)</span>
                            </label>
                            <input type="number" class="form-control"
                                   v-model.number="archiveSettings.archive_after_days"
                                   min="1" max="3650">
                            <div class="form-text">
                                Сквитованные записи старше указанного числа дней от даты квитования будут архивированы.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Срок хранения в архиве (лет)
                            </label>
                            <input type="number" class="form-control"
                                   v-model.number="archiveSettings.retention_years"
                                   min="1" max="20">
                            <div class="form-text">По умолчанию 5 лет согласно требованиям.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       v-model="archiveSettings.auto_archive_enabled" id="autoArchiveEnabled">
                                <label class="form-check-label fw-semibold" for="autoArchiveEnabled">
                                    Автоматическое архивирование
                                </label>
                            </div>
                            <div class="form-text">
                                Если включено — архивирование запускается автоматически по расписанию (cron).
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-card-footer" style="display:flex;justify-content:flex-end;gap:8px">
                    <button type="button" class="toolbar-btn outline" @click.prevent="closeArchiveSettings">Отмена</button>
                    <button type="button" class="toolbar-btn primary" @click.prevent="saveArchiveSettings" :disabled="archiveSettingsSaving">
                        <i :class="archiveSettingsSaving?'fas fa-spinner fa-spin':'fas fa-save'"></i>
                        {{ archiveSettingsSaving ? 'Сохранение...' : 'Сохранить' }}
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /archive section -->
