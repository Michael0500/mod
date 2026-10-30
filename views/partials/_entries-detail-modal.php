<?php /** @var yii\web\View $this */ ?>

<!-- Общая модалка деталей записи NostroEntry. Vue-инстанс должен иметь
     data.detailEntry и методы closeEntryDetail, formatAmount, fmtDate. -->
<div v-if="detailEntry" class="entry-detail-overlay" @click.self="closeEntryDetail">
    <div class="entry-detail-modal">
        <div class="entry-detail-header">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:32px;height:32px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:8px;display:flex;align-items:center;justify-content:center">
                    <i class="fas fa-info" style="color:#fff;font-size:14px"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:800;color:#1a1f36">Детали записи</div>
                    <div style="font-size:11px;color:#9ca3af">ID: {{ detailEntry.id }}</div>
                </div>
            </div>
            <button class="entry-detail-close" @click="closeEntryDetail"><i class="fas fa-times"></i></button>
        </div>
        <div class="entry-detail-body">
            <div class="entry-detail-grid">
                <div class="ed-field">
                    <div class="ed-label">L/S</div>
                    <div class="ed-value">
                        <span :class="detailEntry.ls==='L'?'badge-ls-l':'badge-ls-s'">{{ detailEntry.ls }}</span>
                    </div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">D/C</div>
                    <div class="ed-value">
                        <span :class="detailEntry.dc==='Debit'?'badge-debit':'badge-credit'">{{ detailEntry.dc==='Debit'?'Debit':'Credit' }}</span>
                    </div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Сумма</div>
                    <div class="ed-value ed-mono" style="font-size:16px;font-weight:800;color:#059669">{{ formatAmount(detailEntry.amount, detailEntry.currency) }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Валюта</div>
                    <div class="ed-value" style="font-weight:700">{{ detailEntry.currency || '—' }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Ностро-банк</div>
                    <div class="ed-value">{{ detailEntry.pool_name || '—' }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Счёт</div>
                    <div class="ed-value">{{ detailEntry.account_name || '—' }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Статус</div>
                    <div class="ed-value">
                        <span :class="detailEntry.match_status==='M'?'status-badge status-matched':detailEntry.match_status==='I'?'status-badge status-ignored':'status-badge status-waiting'">
                            {{ detailEntry.match_status==='M'?'Сквитовано':detailEntry.match_status==='I'?'Игнорировано':'Ожидает' }}
                        </span>
                    </div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Value Date</div>
                    <div class="ed-value">{{ fmtDate(detailEntry.value_date) }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Post Date</div>
                    <div class="ed-value">{{ fmtDate(detailEntry.post_date) }}</div>
                </div>
                <div class="ed-field ed-full">
                    <div class="ed-label">Match ID</div>
                    <div class="ed-value ed-mono">{{ detailEntry.match_id || '—' }}</div>
                </div>
                <div class="ed-field ed-full">
                    <div class="ed-label">Instruction ID</div>
                    <div class="ed-value ed-mono">{{ detailEntry.instruction_id || '—' }}</div>
                </div>
                <div class="ed-field ed-full">
                    <div class="ed-label">EndToEnd ID</div>
                    <div class="ed-value ed-mono">{{ detailEntry.end_to_end_id || '—' }}</div>
                </div>
                <div class="ed-field ed-full">
                    <div class="ed-label">Transaction ID</div>
                    <div class="ed-value ed-mono">{{ detailEntry.transaction_id || '—' }}</div>
                </div>
                <div class="ed-field ed-full">
                    <div class="ed-label">Message ID</div>
                    <div class="ed-value ed-mono">{{ detailEntry.message_id || '—' }}</div>
                </div>
                <div class="ed-field ed-full">
                    <div class="ed-label">Other ID</div>
                    <div class="ed-value ed-mono">{{ detailEntry.other_id || '—' }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Источник</div>
                    <div class="ed-value">{{ detailEntry.source || '—' }}</div>
                </div>
                <div class="ed-field">
                    <div class="ed-label">Комментарий</div>
                    <div class="ed-value">{{ detailEntry.comment || '—' }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
