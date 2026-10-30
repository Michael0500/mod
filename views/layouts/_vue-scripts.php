<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
use app\models\Currency;
use app\models\Country;

$currentUser = Yii::$app->user->identity;
$currentComp = ($currentUser && $currentUser->company_id) ? $currentUser->company : null;
$companySection = $currentComp ? strtoupper($currentComp->code) : '';

// Справочники (общесистемные) — попадают во все Vue-инстансы через window.AppDictionaries.
// Запрашиваются один раз на запрос.
$dictCurrencies = [];
$dictCountries  = [];
try {
    foreach (Currency::activeList() as $c) {
        $dictCurrencies[] = [
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'fractional_digit' => (int)$c->fractional_digit,
        ];
    }
    foreach (Country::activeList() as $c) {
        $dictCountries[] = ['code' => $c->code, 'code3' => $c->code3, 'name' => $c->name];
    }
} catch (\Throwable $e) {
    // Если миграции справочников ещё не накатаны — оставляем пустые массивы
    $dictCurrencies = [];
    $dictCountries  = [];
}
?>
<script>
    window.AppRoutes = {
        // Категории
        categoryGetCategories: '<?= Url::to(['/category/get-categories']) ?>',
        categoryCreate:        '<?= Url::to(['/category/create']) ?>',
        categoryUpdate:        '<?= Url::to(['/category/update']) ?>',
        categoryDelete:        '<?= Url::to(['/category/delete']) ?>',

        // Ностро банки
        accountPoolList:               '<?= Url::to(['/account-pool/list']) ?>',
        accountPoolQuickCreate:        '<?= Url::to(['/account-pool/quick-create']) ?>',
        accountPoolMoveToCategory:     '<?= Url::to(['/account-pool/move-to-category']) ?>',
        accountPoolDelete:             '<?= Url::to(['/account-pool/delete']) ?>',
        accountPoolAvailableAccounts:  '<?= Url::to(['/account-pool/available-accounts']) ?>',

        // Записи (NostroEntry)
        entryList:           '<?= Url::to(['/nostro-entry/list']) ?>',
        entrySearchAccounts: '<?= Url::to(['/nostro-entry/search-accounts']) ?>',
        entryCreate:         '<?= Url::to(['/nostro-entry/create']) ?>',
        entryUpdate:         '<?= Url::to(['/nostro-entry/update']) ?>',
        entryDelete:         '<?= Url::to(['/nostro-entry/delete']) ?>',
        entryUpdateComment:  '<?= Url::to(['/nostro-entry/update-comment']) ?>',
        entryHistory:        '<?= Url::to(['/nostro-entry/history']) ?>',

        // Квитование
        matchManual:     '<?= Url::to(['/matching/match-manual']) ?>',
        unmatch:         '<?= Url::to(['/matching/unmatch']) ?>',
        autoMatch:       '<?= Url::to(['/matching/auto-match']) ?>',
        autoMatchStart:  '<?= Url::to(['/matching/auto-match-start']) ?>',
        autoMatchStep:   '<?= Url::to(['/matching/auto-match-step']) ?>',
        autoMatchCancel: '<?= Url::to(['/matching/auto-match-cancel']) ?>',
        matchGroup:      '<?= Url::to(['/matching/match-group']) ?>',
        getRules:        '<?= Url::to(['/matching/get-rules']) ?>',
        saveRule:        '<?= Url::to(['/matching/save-rule']) ?>',
        deleteRule:      '<?= Url::to(['/matching/delete-rule']) ?>',

        // Баланс Ностро
        balanceList:      '<?= Url::to(['/nostro-balance/list']) ?>',
        balanceCreate:    '<?= Url::to(['/nostro-balance/create']) ?>',
        balanceUpdate:    '<?= Url::to(['/nostro-balance/update']) ?>',
        balanceDelete:    '<?= Url::to(['/nostro-balance/delete']) ?>',
        balanceConfirm:   '<?= Url::to(['/nostro-balance/confirm']) ?>',
        balanceHistory:   '<?= Url::to(['/nostro-balance/history']) ?>',
        balanceAccounts:  '<?= Url::to(['/nostro-balance/accounts']) ?>',
        balanceImportBnd: '<?= Url::to(['/nostro-balance/import-bnd']) ?>',
        balanceImportAsb: '<?= Url::to(['/nostro-balance/import-asb']) ?>',

        // ── Архив ────────────────────────────────────────────────
        archiveList:         '<?= Url::to(['/archive/list']) ?>',
        archiveCount:        '<?= Url::to(['/archive/count']) ?>',
        archiveRunBatch:     '<?= Url::to(['/archive/run-batch']) ?>',
        archiveRestorePreview:'<?= Url::to(['/archive/restore-preview']) ?>',
        archiveRestore:      '<?= Url::to(['/archive/restore']) ?>',
        archivePurgeExpired: '<?= Url::to(['/archive/purge-expired']) ?>',
        archiveSettings:     '<?= Url::to(['/archive/settings']) ?>',
        archiveSaveSettings: '<?= Url::to(['/archive/save-settings']) ?>',
        archiveStats:        '<?= Url::to(['/archive/stats']) ?>',
        archiveAccounts:     '<?= Url::to(['/archive/accounts']) ?>',
        archiveHistory:      '<?= Url::to(['/archive/history']) ?>',

        // ── Архив балансов ───────────────────────────────────────
        balanceArchiveList:         '<?= Url::to(['/balance-archive/list']) ?>',
        balanceArchiveCount:        '<?= Url::to(['/balance-archive/count']) ?>',
        balanceArchiveRunBatch:     '<?= Url::to(['/balance-archive/run-batch']) ?>',
        balanceArchiveRestore:      '<?= Url::to(['/balance-archive/restore']) ?>',
        balanceArchivePurgeExpired: '<?= Url::to(['/balance-archive/purge-expired']) ?>',
        balanceArchiveSettings:     '<?= Url::to(['/balance-archive/settings']) ?>',
        balanceArchiveSaveSettings: '<?= Url::to(['/balance-archive/save-settings']) ?>',
        balanceArchiveStats:        '<?= Url::to(['/balance-archive/stats']) ?>',
        balanceArchiveAccounts:     '<?= Url::to(['/balance-archive/accounts']) ?>',
        balanceArchiveHistory:      '<?= Url::to(['/balance-archive/history']) ?>',

        // ── Пользовательские настройки UI ─────────────────────────
        userPreferenceGet:  '<?= Url::to(['/user-preference/get']) ?>',
        userPreferenceSave: '<?= Url::to(['/user-preference/save']) ?>',
    };

    window.AppConfig = {
        companySection:  '<?= addslashes($companySection) ?>',
        userId:          <?= Yii::$app->user->isGuest ? 'null' : (int)Yii::$app->user->id ?>,
    };

    // Глобальные справочники (валюты, страны) для всех Vue-инстансов и форм.
    // Заполняется списком активных значений из таблиц {{%currencies}} / {{%countries}}.
    window.AppDictionaries = {
        currencies: <?= json_encode($dictCurrencies, JSON_UNESCAPED_UNICODE) ?>,
        countries:  <?= json_encode($dictCountries,  JSON_UNESCAPED_UNICODE) ?>,
    };
</script>
