<?php

namespace app\controllers;

use Yii;
use yii\data\Pagination;
use yii\db\Query;
use yii\web\Response;
use app\services\ImportRollbackService;
use app\commands\FccMergeController;
use app\commands\TdsMergeController;
use app\commands\DwhMergeController;

/**
 * Standalone-страница `/imports` — пачки импорта выписок и их откат.
 *
 * Показывает строки `tds_status` (FCC12 / CAMT053 / MT950 / ED211 / ED743 / DWH
 * и ручные загрузки ASB / БНД), их состояние и доступность отката. Откат удаляет
 * вставленные пачкой строки `nostro_entries` / `nostro_balance` вместе с аудитом,
 * если в пачке ещё нет сквитованных или заархивированных записей.
 *
 * Логика отката — в {@see ImportRollbackService}.
 */
class ImportBatchController extends BaseController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs'] = [
            'class' => \yii\filters\VerbFilter::class,
            'actions' => ['rollback' => ['POST'], 'rollback-account' => ['POST'], 'load' => ['POST'], 'accounts' => ['GET']],
        ];
        return $behaviors;
    }

    /** Человекочитаемые названия типов пачек. */
    const TYPE_LABELS = [
        'FCC12'            => 'FCC12',
        'PH_TDS'           => 'PH_TDS (CAMT053/MT950/ED211/ED743)',
        'SUSPENSE_POSTING' => 'Suspense posting (DWH)',
        // Legacy-типы (до объединения пакетов).
        'CAMT053'          => 'camt.053',
        'MT950'            => 'MT950',
        'ED211'            => 'ED211',
        'ED743'            => 'ED743',
        'DWH'              => 'DWH (suspend posting)',
        'ASB'              => 'Банк-клиент АСБ',
        'BND'              => 'Банк-клиент БНД',
    ];

    /** Типы пачек, видимые только в секции INV. */
    const TYPES_INV = ['SUSPENSE_POSTING', 'DWH'];

    /** Типы пачек, видимые только в секции NRE. */
    const TYPES_NRE = ['FCC12', 'PH_TDS', 'CAMT053', 'MT950', 'ED211', 'ED743'];

    /** Типы, видимые в обеих секциях (ручные загрузки). */
    const TYPES_BOTH = ['ASB', 'BND'];

    /** Типы, для которых поддержан ручной запуск загрузки (merge-команды). */
    const LOADABLE_TYPES = ['FCC12', 'PH_TDS', 'SUSPENSE_POSTING'];

    /**
     * Отключает CSRF для JSON API.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * ID компании текущего пользователя.
     *
     * @return int|null
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Рендерит страницу пачек импорта.
     *
     * @return string|\yii\web\Response
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        $this->view->title = 'Откат загруженных данных — SmartMatch';

        // Без компании layout сам покажет заглушку «Компания не выбрана».
        $list = $cid ? $this->buildBatchList($cid) : ['data' => [], 'pagination' => null];
        return $this->render('index', ['initData' => [
            'batches' => $list['data'],
            'pagination' => $list['pagination'],
        ]]);
    }

    /**
     * Возвращает актуальный список пачек (для обновления после отката).
     *
     * GET `/import-batch/list`.
     *
     * @return array
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        return array_merge(['success' => true], $this->buildBatchList($cid));
    }

    /**
     * Откатывает пачку импорта.
     *
     * POST `/import-batch/rollback` с параметром `id`.
     *
     * @return array
     */
    public function actionRollback(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $id = (int)Yii::$app->request->post('id');
        if (!$id) return ['success' => false, 'message' => 'Не указана пачка'];

        // Проверяем принадлежность пачки компании пользователя.
        $batch = Yii::$app->db->createCommand(
            "SELECT * FROM {{%tds_status}} WHERE id = :id",
            [':id' => $id]
        )->queryOne();
        if (!$batch || $this->effectiveCompanyId($batch) !== $cid
            || !$this->isVisibleForSection((string)$batch['type'], $this->currentSection())) {
            return ['success' => false, 'message' => 'Пачка не найдена'];
        }

        $result = (new ImportRollbackService())->rollback($id, (int)Yii::$app->user->id, $cid);
        if ($result['success']) {
            $result = array_merge($result, $this->buildBatchList($cid));
        }
        return $result;
    }

    /** Счета выбранной пачки, причины запрета и история откатов. */
    public function actionAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $batch = $this->findVisibleBatch((int)Yii::$app->request->get('id'));
        if (!$batch) return ['success' => false, 'message' => 'Пачка не найдена'];
        return array_merge(['success' => true], (new ImportRollbackService())->accounts(
            $batch, trim((string)Yii::$app->request->get('q', '')),
            (int)Yii::$app->request->get('page', 1), (int)Yii::$app->request->get('page_size', 25)
        ));
    }

    /** Откат ограничен компанией, пачкой и выбранным счётом. */
    public function actionRollbackAccount(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $batch = $this->findVisibleBatch((int)Yii::$app->request->post('id'));
        if (!$batch) return ['success' => false, 'message' => 'Пачка не найдена'];
        $result = (new ImportRollbackService())->rollbackAccount(
            (int)$batch['id'], (int)Yii::$app->request->post('account_id'),
            (int)Yii::$app->user->id, $this->cid()
        );
        return $result['success'] ? array_merge($result, $this->buildBatchList($this->cid())) : $result;
    }

    private function findVisibleBatch(int $id): ?array
    {
        $cid = $this->cid();
        if (!$cid) return null;
        $batch = (new Query())->from('{{%tds_status}}')->where(['id' => $id])->one();
        return $batch && $this->effectiveCompanyId($batch) === $cid
            && $this->isVisibleForSection((string)$batch['type'], $this->currentSection()) ? $batch : null;
    }

    /**
     * Выбирает страницу пачек компании со счётчиками и доступностью отката.
     *
     * @param int $cid ID компании.
     * @return array
     */
    private function buildBatchList(int $cid): array
    {
        $db = Yii::$app->db;

        $types = array_merge(self::TYPES_BOTH,
            $this->currentSection() === 'INV' ? self::TYPES_INV : self::TYPES_NRE);
        $query = (new Query())->from(['t' => '{{%tds_status}}'])
            ->where("COALESCE(t.company_id, CASE WHEN t.type IN ('DWH', 'SUSPENSE_POSTING') THEN 2 ELSE 1 END) = :cid", [':cid' => $cid])
            ->andWhere(['t.type' => $types]);
        $pagination = new Pagination([
            'totalCount' => (int)(clone $query)->count('*', $db),
            'defaultPageSize' => 25,
            'pageSizeLimit' => [1, 100],
            'pageSizeParam' => 'page_size',
        ]);
        $rows = $query->select(['t.*', 'account_name' => 'a.name'])
            ->leftJoin(['a' => '{{%accounts}}'], 'a.id = t.account_id AND a.company_id = :cid')
            ->orderBy(['t.id' => SORT_DESC])
            ->limit($pagination->getLimit())
            ->offset($pagination->getOffset())
            ->all($db);
        $batchIds = array_column($rows, 'id');
        // Все агрегаты ограничены текущей страницей и компанией.
        $statsWhere = ['batch_id' => $batchIds, 'company_id' => $cid];

        $entryStats = $this->indexBy((new Query())
            ->select(['batch_id', 'total' => 'COUNT(*)',
                'matched' => "COUNT(*) FILTER (WHERE match_id IS NOT NULL OR match_status IN ('M','I'))"])
            ->from('{{%nostro_entries}}')->where($statsWhere)
            ->groupBy('batch_id')->all($db), 'batch_id');

        $balanceStats = $this->indexBy((new Query())
            ->select(['batch_id', 'total' => 'COUNT(*)'])
            ->from('{{%nostro_balance}}')->where($statsWhere)
            ->groupBy('batch_id')->all($db), 'batch_id');

        $archiveStats = $this->indexBy((new Query())
            ->select(['batch_id', 'total' => 'COUNT(*)'])
            ->from('{{%nostro_entries_archive}}')->where($statsWhere)
            ->groupBy('batch_id')->all($db), 'batch_id');

        $rollbackStats = $this->indexBy((new Query())
            ->select(['batch_id', 'total' => 'COUNT(*)'])
            ->from('{{%import_account_rollbacks}}')->where($statsWhere)
            ->groupBy('batch_id')->all($db), 'batch_id');

        $balanceArchiveStats = $this->indexBy((new Query())
            ->select(['batch_id', 'total' => 'COUNT(*)'])
            ->from('{{%nostro_balance_archive}}')->where($statsWhere)
            ->groupBy('batch_id')->all($db), 'batch_id');

        $service = new ImportRollbackService();
        $automatchRunning = (bool)(new Query())->from('{{%automatch_jobs}}')
            ->where(['company_id' => $cid, 'status' => 'running'])->exists();
        $result = [];

        foreach ($rows as $row) {
            $type = (string)$row['type'];
            $id = (int)$row['id'];
            $check = $service->canRollback($row + ['automatch_running' => $automatchRunning]);
            $load  = $this->loadAvailability($row);

            $result[] = [
                'id'              => $id,
                'type'            => $type,
                'type_label'      => self::TYPE_LABELS[$type] ?? $type,
                'date_time'       => $row['date_time'],
                'account_name'    => $row['account_name'],
                'source_label'    => $row['source_label'],
                'is_merged'       => (bool)$row['is_merged'],
                'is_rolled_back'  => (bool)$row['is_rolled_back'],
                'rolled_back_at'  => $row['rolled_back_at'],
                'rolled_back_accounts' => (int)($rollbackStats[$id]['total'] ?? 0),
                'is_partially_rolled_back' => empty($row['is_rolled_back']) && !empty($rollbackStats[$id]['total']),
                'is_processing'   => (bool)$row['is_processing'],
                'processing_owner' => $row['processing_owner'],
                'imported_entries'  => $row['entries_count'] !== null ? (int)$row['entries_count'] : null,
                'imported_balances' => $row['balances_count'] !== null ? (int)$row['balances_count'] : null,
                'live_entries'    => (int)($entryStats[$id]['total'] ?? 0),
                'live_balances'   => (int)($balanceStats[$id]['total'] ?? 0),
                'matched'         => (int)($entryStats[$id]['matched'] ?? 0),
                'archived'        => (int)($archiveStats[$id]['total'] ?? 0) + (int)($balanceArchiveStats[$id]['total'] ?? 0),
                'can_rollback'    => $check['ok'],
                'reason'          => $check['reason'],
                'can_load'        => $load['ok'],
                'load_reason'     => $load['reason'],
                'skipped_accounts' => $this->decodeSkippedAccounts($row['skipped_accounts'] ?? null),
            ];
        }

        return [
            'data' => $result,
            'pagination' => [
                'total' => $pagination->totalCount,
                'page' => $pagination->getPage() + 1,
                'page_size' => $pagination->getPageSize(),
                'page_count' => max(1, $pagination->getPageCount()),
            ],
        ];
    }

    /**
     * Декодирует JSON-список ненайденных счетов из `tds_status.skipped_accounts`.
     *
     * @param string|null $raw Сырое значение колонки.
     * @return string[] Список имён счетов (пустой, если нет).
     */
    private function decodeSkippedAccounts(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn($v) => $v !== '')) : [];
    }

    /**
     * Секция текущего пользователя по компании (`INV` для компании с кодом INV).
     *
     * @return string `'INV'` или `'NRE'`.
     */
    private function currentSection(): string
    {
        $u = Yii::$app->user->identity;
        $company = $u ? $u->company : null;
        return ($company && $company->isInv()) ? 'INV' : 'NRE';
    }

    /**
     * Виден ли тип пачки в указанной секции.
     *
     * SUSPENSE_POSTING/DWH — только INV; FCC12/PH_TDS и legacy-форматы — только
     * NRE; ASB/BND — в обеих секциях.
     *
     * @param string $type Тип пачки.
     * @param string $section Секция пользователя.
     * @return bool
     */
    private function isVisibleForSection(string $type, string $section): bool
    {
        if (in_array($type, self::TYPES_BOTH, true)) {
            return true;
        }
        return $section === 'INV'
            ? in_array($type, self::TYPES_INV, true)
            : in_array($type, self::TYPES_NRE, true);
    }

    /**
     * Доступность ручного запуска загрузки для пачки.
     *
     * @param array $row Строка tds_status.
     * @return array `['ok'=>bool,'reason'=>string]`.
     */
    private function loadAvailability(array $row): array
    {
        $type = (string)$row['type'];
        if (!in_array($type, self::LOADABLE_TYPES, true)) {
            return ['ok' => false, 'reason' => 'Загрузка не применима для этого типа'];
        }
        if (!empty($row['is_rolled_back'])) {
            return ['ok' => false, 'reason' => 'Пачка откатана'];
        }
        if (!empty($row['is_merged'])) {
            return ['ok' => false, 'reason' => 'Уже загружено'];
        }
        if (!empty($row['is_processing'])) {
            $owner = $row['processing_owner'] === 'manual' ? 'вручную' : 'фоном';
            return ['ok' => false, 'reason' => "Выполняется ({$owner})"];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Запускает загрузку (merge) выбранной пачки вручную.
     *
     * POST `/import-batch/load` с параметром `id`. Синхронно выполняет
     * соответствующий merge (FCC12 / PH_TDS / SUSPENSE_POSTING) под блокировкой
     * строки tds_status (owner=manual). Если строку уже захватил фоновый или
     * другой ручной процесс — возвращает ошибку и ничего не делает.
     *
     * @return array
     */
    public function actionLoad(): array
    {
        set_time_limit(0); ini_set('memory_limit', '4256M');

        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $id = (int)Yii::$app->request->post('id');
        if (!$id) return ['success' => false, 'message' => 'Не указана пачка'];

        $batch = Yii::$app->db->createCommand(
            "SELECT * FROM {{%tds_status}} WHERE id = :id",
            [':id' => $id]
        )->queryOne();
        if (!$batch
            || $this->effectiveCompanyId($batch) !== $cid
            || !$this->isVisibleForSection((string)$batch['type'], $this->currentSection())) {
            return ['success' => false, 'message' => 'Пачка не найдена'];
        }

        $type = (string)$batch['type'];
        if (!in_array($type, self::LOADABLE_TYPES, true)) {
            return ['success' => false, 'message' => 'Тип пачки не поддерживает ручной запуск загрузки'];
        }
        if (!empty($batch['is_merged'])) {
            return ['success' => false, 'message' => 'Пачка уже загружена'];
        }
        if (!empty($batch['is_rolled_back'])) {
            return ['success' => false, 'message' => 'Пачка откатана'];
        }

        $controller = $this->mergeControllerFor($type);
        $controller->quiet = true;
        $res = $controller->processOne($id, 'manual');

        if (!empty($res['busy'])) {
            return ['success' => false, 'message' => 'Пачка уже обрабатывается (фоновый или другой ручной запуск)'];
        }
        if ($res['error'] !== null) {
            return ['success' => false, 'message' => 'Ошибка загрузки: ' . $res['error']];
        }

        $msg = "Загружено: записей {$res['entries']}, балансов {$res['balances']}";
        if (!$res['ok']) {
            $msg .= "; пропущено {$res['skipped']} — пакет не помечен загруженным";
            $missing = $res['skipped_accounts'] ?? [];
            if (!empty($missing)) {
                $msg .= ". Нет счетов в системе: " . implode(', ', $missing);
            }
        }

        return array_merge([
            'success' => true,
            'message' => $msg,
            'ok'      => $res['ok'],
            'skipped_accounts' => $res['skipped_accounts'] ?? [],
        ], $this->buildBatchList($cid));
    }

    /**
     * Возвращает merge-контроллер для типа пачки.
     *
     * @param string $type Тип пачки (FCC12 / PH_TDS / SUSPENSE_POSTING).
     * @return FccMergeController|TdsMergeController|DwhMergeController
     */
    private function mergeControllerFor(string $type)
    {
        switch ($type) {
            case 'FCC12':
                return new FccMergeController('fcc-merge', Yii::$app);
            case 'PH_TDS':
                return new TdsMergeController('tds-merge', Yii::$app);
            case 'SUSPENSE_POSTING':
                return new DwhMergeController('dwh-merge', Yii::$app);
        }
        throw new \InvalidArgumentException("Неподдерживаемый тип пачки: {$type}");
    }

    /**
     * Эффективная компания пачки.
     *
     * Для ручных загрузок и новых merge-пачек берётся из `company_id`.
     * Для legacy-строк без `company_id` компания выводится по типу
     * (DWH → 2, остальные → 1), как в merge-командах.
     *
     * @param array $row Строка tds_status.
     * @return int
     */
    private function effectiveCompanyId(array $row): int
    {
        if ($row['company_id'] !== null && $row['company_id'] !== '') {
            return (int)$row['company_id'];
        }
        $type = (string)$row['type'];
        return ($type === 'DWH' || $type === 'SUSPENSE_POSTING') ? 2 : 1;
    }

    /**
     * Индексирует массив строк по значению колонки.
     *
     * @param array $rows Строки.
     * @param string $key Имя колонки-ключа.
     * @return array Карта `value => row`.
     */
    private function indexBy(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row[$key]] = $row;
        }
        return $map;
    }
}
