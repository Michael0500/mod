<?php
/**
 * Общий partial панели фильтров NostroEntry.
 * Используется на странице выверки (без пула и счёта) и на странице
 * "Выверка по всем ностро-банкам" (с мультивыбором пулов и счётом).
 *
 * @var yii\web\View $this
 * @var bool $showMultiPoolFilter — показать Select2 мультивыбора ностро-банков
 * @var bool $showAccountFilter   — показать Select2 выбора счёта
 * @var string $poolSelectId      — id <select> для пулов (если используется)
 * @var string $accountSelectId   — id <select> для счёта (если используется)
 * @var string $currencySelectId  — id <select multiple> для мультивыбора валют
 */

$showMultiPoolFilter = $showMultiPoolFilter ?? false;
$showAccountFilter   = $showAccountFilter   ?? false;
$showBatchFilter     = $showBatchFilter     ?? false;
$poolSelectId        = $poolSelectId        ?? 'filter-pools-select2';
$accountSelectId     = $accountSelectId     ?? 'filter-account-select2';
$currencySelectId    = $currencySelectId    ?? 'filter-currency-select2';
?>
<div v-show="filtersOpen" class="filters-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">
            <i class="fas fa-filter me-1"></i>Фильтры
        </span>
        <button class="row-btn delete" @click="filtersOpen = false" title="Закрыть фильтры">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">

        <?php if ($showMultiPoolFilter): ?>
            <!-- Ностро банки (мультивыбор) -->
            <div class="filter-field" style="grid-column:span 2">
                <label class="filter-label">Ностро-банки (можно несколько)</label>
                <select id="<?= htmlspecialchars($poolSelectId) ?>" multiple="multiple" style="width:100%"></select>
            </div>
        <?php endif; ?>

        <?php if ($showAccountFilter): ?>
            <!-- Ностро счёт -->
            <div class="filter-field" style="grid-column:span 2">
                <label class="filter-label">Ностро счёт</label>
                <select id="<?= htmlspecialchars($accountSelectId) ?>" style="width:100%"></select>
            </div>
        <?php endif; ?>

        <?php if ($showBatchFilter): ?>
            <!-- Номер пакета загрузки -->
            <div class="filter-field" style="grid-column:span 2">
                <label class="filter-label">Номер пакета</label>
                <div class="filter-input-wrap">
                    <input type="number" class="filter-input" placeholder="ID пакета загрузки..."
                           :value="filters.batch_id||''"
                           @change="applyFilter('batch_id',$event.target.value)">
                    <button v-if="filters.batch_id" class="filter-clear-btn" @click="clearFilter('batch_id')">×</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- L/S -->
        <div class="filter-field">
            <label class="filter-label">L / S</label>
            <div class="filter-toggle-group">
                <button @click="applyFilter('ls','')" :class="['ftg-btn', !filters.ls?'active':'']">Все</button>
                <button @click="applyFilter('ls','L')" :class="['ftg-btn',filters.ls==='L'?'active-l':'']" style="color:#6366f1">L</button>
                <button @click="applyFilter('ls','S')" :class="['ftg-btn',filters.ls==='S'?'active-s':'']" style="color:#0284c7">S</button>
            </div>
        </div>

        <!-- D/C -->
        <div class="filter-field">
            <label class="filter-label">D / C</label>
            <div class="filter-toggle-group">
                <button @click="applyFilter('dc','')" :class="['ftg-btn', !filters.dc?'active':'']">Все</button>
                <button @click="applyFilter('dc','Debit')" :class="['ftg-btn',filters.dc==='Debit'?'active-d':'']" style="color:#ef4444">D</button>
                <button @click="applyFilter('dc','Credit')" :class="['ftg-btn',filters.dc==='Credit'?'active-c':'']" style="color:#10b981">C</button>
            </div>
        </div>

        <!-- Статус -->
        <div class="filter-field">
            <label class="filter-label">Статус</label>
            <div class="filter-toggle-group">
                <button @click="applyFilter('match_status','')" :class="['ftg-btn', !filters.match_status?'active':'']">Все</button>
                <button @click="applyFilter('match_status','U')" :class="['ftg-btn',filters.match_status==='U'?'active':'']">Ожидает</button>
                <button @click="applyFilter('match_status','M')" :class="['ftg-btn',filters.match_status==='M'?'active':'']">Сквит.</button>
            </div>
        </div>

        <!-- Валюта (мультивыбор) -->
        <div class="filter-field" style="grid-column:span 2">
            <label class="filter-label">Валюта (можно несколько)</label>
            <select id="<?= htmlspecialchars($currencySelectId) ?>" multiple="multiple" style="width:100%"></select>
        </div>

        <!-- Сумма от/до -->
        <div class="filter-field">
            <label class="filter-label">Сумма от</label>
            <div class="filter-input-wrap">
                <input type="number" class="filter-input" placeholder="0"
                       :value="filters.amount_min||''"
                       @change="applyFilter('amount_min',$event.target.value)">
                <button v-if="filters.amount_min" class="filter-clear-btn" @click="clearFilter('amount_min')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Сумма до</label>
            <div class="filter-input-wrap">
                <input type="number" class="filter-input" placeholder="∞"
                       :value="filters.amount_max||''"
                       @change="applyFilter('amount_max',$event.target.value)">
                <button v-if="filters.amount_max" class="filter-clear-btn" @click="clearFilter('amount_max')">×</button>
            </div>
        </div>

        <!-- Value Date -->
        <div class="filter-field">
            <label class="filter-label">Value Date от</label>
            <div class="filter-input-wrap">
                <input type="text" v-datepicker class="filter-input" :value="filters.value_date_from||''"
                       @change="applyFilter('value_date_from',$event.target.value)">
                <button v-if="filters.value_date_from" class="filter-clear-btn" @click="clearFilter('value_date_from')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Value Date до</label>
            <div class="filter-input-wrap">
                <input type="text" v-datepicker class="filter-input" :value="filters.value_date_to||''"
                       @change="applyFilter('value_date_to',$event.target.value)">
                <button v-if="filters.value_date_to" class="filter-clear-btn" @click="clearFilter('value_date_to')">×</button>
            </div>
        </div>

        <!-- Post Date -->
        <div class="filter-field">
            <label class="filter-label">Post Date от</label>
            <div class="filter-input-wrap">
                <input type="text" v-datepicker class="filter-input" :value="filters.post_date_from||''"
                       @change="applyFilter('post_date_from',$event.target.value)">
                <button v-if="filters.post_date_from" class="filter-clear-btn" @click="clearFilter('post_date_from')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Post Date до</label>
            <div class="filter-input-wrap">
                <input type="text" v-datepicker class="filter-input" :value="filters.post_date_to||''"
                       @change="applyFilter('post_date_to',$event.target.value)">
                <button v-if="filters.post_date_to" class="filter-clear-btn" @click="clearFilter('post_date_to')">×</button>
            </div>
        </div>

        <!-- ID поля -->
        <div class="filter-field">
            <label class="filter-label">Match ID</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="Match ID..."
                       :value="filters.match_id||''"
                       @input="debouncedFilter('match_id',$event.target.value)">
                <button v-if="filters.match_id" class="filter-clear-btn" @click="clearFilter('match_id')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Instruction ID</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="Instruction ID..."
                       :value="filters.instruction_id||''"
                       @input="debouncedFilter('instruction_id',$event.target.value)">
                <button v-if="filters.instruction_id" class="filter-clear-btn" @click="clearFilter('instruction_id')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">EndToEnd ID</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="EndToEnd ID..."
                       :value="filters.end_to_end_id||''"
                       @input="debouncedFilter('end_to_end_id',$event.target.value)">
                <button v-if="filters.end_to_end_id" class="filter-clear-btn" @click="clearFilter('end_to_end_id')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Transaction ID</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="Transaction ID..."
                       :value="filters.transaction_id||''"
                       @input="debouncedFilter('transaction_id',$event.target.value)">
                <button v-if="filters.transaction_id" class="filter-clear-btn" @click="clearFilter('transaction_id')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Message ID</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="Message ID..."
                       :value="filters.message_id||''"
                       @input="debouncedFilter('message_id',$event.target.value)">
                <button v-if="filters.message_id" class="filter-clear-btn" @click="clearFilter('message_id')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Other ID</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="Other ID..."
                       :value="filters.other_id||''"
                       @input="debouncedFilter('other_id',$event.target.value)">
                <button v-if="filters.other_id" class="filter-clear-btn" @click="clearFilter('other_id')">×</button>
            </div>
        </div>
        <div class="filter-field">
            <label class="filter-label">Комментарий</label>
            <div class="filter-input-wrap">
                <input type="text" class="filter-input" placeholder="Комментарий..."
                       :value="filters.comment||''"
                       @input="debouncedFilter('comment',$event.target.value)">
                <button v-if="filters.comment" class="filter-clear-btn" @click="clearFilter('comment')">×</button>
            </div>
        </div>

    </div>
</div>
