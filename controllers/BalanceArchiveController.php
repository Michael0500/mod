<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Account;
use app\models\ArchiveSettings;
use app\models\NostroBalance;
use app\models\NostroBalanceArchive;
use app\models\NostroBalanceAudit;

/**
 * JSON-контроллер архива балансов Ностро.
 *
 * Архивирование переносит старые корректные/подтверждённые строки из
 * `nostro_balance` в `nostro_balance_archive` пакетами. Возраст баланса
 * считается по `value_date`, потому что у балансов нет даты квитования.
 */
class BalanceArchiveController extends BaseController
{
    private const BATCH_SIZE = 300;

    /**
     * Отключает CSRF для JSON API архива балансов.
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
     * Рендерит страницу архива балансов.
     *
     * @return string HTML страницы.
     */
    public function actionPage()
    {
        $this->view->title = 'Архив балансов';
        return $this->render('page');
    }

    /**
     * Возвращает ID компании текущего пользователя.
     *
     * @return int|null ID компании или `null`.
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Возвращает список архивных балансов с фильтрами и пагинацией.
     *
     * @return array JSON-ответ.
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r       = Yii::$app->request;
        $page    = max(1, (int)$r->get('page', 1));
        $limit   = min(200, max(10, (int)$r->get('limit', 50)));
        $sort    = $r->get('sort', 'archived_at');
        $dirRaw  = $r->get('dir', 'desc');
        $dir     = strtolower($dirRaw) === 'asc' ? SORT_ASC : SORT_DESC;
        $filters = json_decode($r->get('filters', '{}'), true) ?: [];

        $sortable = ['id', 'original_id', 'account_id', 'ls_type', 'statement_number',
            'currency', 'value_date', 'opening_balance', 'closing_balance', 'section',
            'source', 'status', 'opening_dc', 'closing_dc', 'comment', 'archived_at', 'expires_at'];
        if (!in_array($sort, $sortable, true)) $sort = 'archived_at';

        $q = NostroBalanceArchive::find()
            ->from(['nba' => NostroBalanceArchive::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = nba.account_id')
            ->leftJoin(['ap' => 'account_pools'], 'ap.id = a.pool_id')
            ->where(['nba.company_id' => $cid])
            ->addSelect(['nba.*', 'a.name AS account_name', 'ap.name AS pool_name']);

        if (!empty($filters['account_pool_id'])) {
            $q->andWhere(['a.pool_id' => (int)$filters['account_pool_id']]);
        }
        if (!empty($filters['pool_id'])) {
            $q->andWhere(['a.pool_id' => (int)$filters['pool_id']]);
        }
        if (!empty($filters['account_id'])) {
            $q->andWhere(['nba.account_id' => (int)$filters['account_id']]);
        }

        foreach (['ls_type', 'currency', 'section', 'source', 'status'] as $field) {
            if (!empty($filters[$field])) {
                $q->andWhere(is_array($filters[$field])
                    ? ['nba.' . $field => $filters[$field]]
                    : ['nba.' . $field => $filters[$field]]);
            }
        }

        if (!empty($filters['statement_number'])) {
            $q->andWhere(['ilike', 'nba.statement_number', $filters['statement_number']]);
        }
        if (!empty($filters['value_date_from'])) {
            $q->andWhere(['>=', 'nba.value_date', $filters['value_date_from']]);
        }
        if (!empty($filters['value_date_to'])) {
            $q->andWhere(['<=', 'nba.value_date', $filters['value_date_to']]);
        }
        if (!empty($filters['archived_at_from'])) {
            $q->andWhere(['>=', 'DATE(nba.archived_at)', $filters['archived_at_from']]);
        }
        if (!empty($filters['archived_at_to'])) {
            $q->andWhere(['<=', 'DATE(nba.archived_at)', $filters['archived_at_to']]);
        }
        if (!empty($filters['opening_min'])) {
            $q->andWhere(['>=', 'nba.opening_balance', $this->normalizeDecimalFilter($filters['opening_min'])]);
        }
        if (!empty($filters['opening_max'])) {
            $q->andWhere(['<=', 'nba.opening_balance', $this->normalizeDecimalFilter($filters['opening_max'])]);
        }
        if (!empty($filters['closing_min'])) {
            $q->andWhere(['>=', 'nba.closing_balance', $this->normalizeDecimalFilter($filters['closing_min'])]);
        }
        if (!empty($filters['closing_max'])) {
            $q->andWhere(['<=', 'nba.closing_balance', $this->normalizeDecimalFilter($filters['closing_max'])]);
        }

        $this->applySearchFilter($q, $filters);

        $total  = (int)(clone $q)->count('nba.id');
        $offset = ($page - 1) * $limit;

        $rows = $q->orderBy(["nba.{$sort}" => $dir])
            ->limit($limit)
            ->offset($offset)
            ->asArray()
            ->all();

        foreach ($rows as &$row) {
            $this->formatArchiveRowDates($row);
        }
        unset($row);

        return [
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int)ceil($total / $limit),
        ];
    }

    /**
     * Возвращает количество балансов, ожидающих архивирования.
     *
     * @return array JSON с полем `total`.
     */
    public function actionCount(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        return ['success' => true, 'total' => $this->countPending($cid)];
    }

    /**
     * Архивирует одну порцию старых балансов.
     *
     * @return array JSON-прогресс batch-архивирования.
     */
    public function actionRunBatch(): array
    {
        return \app\services\ImportRollbackGuard::shared(function () {
            return $this->actionRunBatchLocked();
        });
    }

    private function actionRunBatchLocked(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $totalDone = (int)Yii::$app->request->post('total_done', 0);
        $totalAll  = (int)Yii::$app->request->post('total_all', 0);

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = $this->cutoffDate($settings);
        $archivedAt = date('Y-m-d H:i:s');
        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$settings->retention_years} years"));
        $userId     = (int)Yii::$app->user->id;
        $db         = Yii::$app->db;

        $rows = $db->createCommand("
            SELECT id, company_id, account_id, ls_type, statement_number, currency, value_date, batch_id,
                   opening_balance, opening_dc, closing_balance, closing_dc, section, source,
                   status, comment, branch_code, extract_no, line_no, stmt_id, edno, eddate,
                   edauthor, created_by, updated_by, created_at, updated_at
              FROM {{%nostro_balance}}
             WHERE company_id = :cid
               AND status IN ('normal', 'confirmed')
               AND is_intermediate = FALSE
               AND value_date < :cutoff
             ORDER BY value_date ASC, id ASC
             LIMIT :lim
        ", [
            ':cid'    => $cid,
            ':cutoff' => $cutoffDate,
            ':lim'    => self::BATCH_SIZE,
        ])->queryAll();

        if (!$rows) {
            return [
                'success'     => true,
                'archived'    => 0,
                'total_done'  => $totalDone,
                'total_all'   => $totalAll,
                'remaining'   => 0,
                'is_finished' => true,
                'message'     => "Готово! Заархивировано балансов: {$totalDone}",
            ];
        }

        $archiveCols = [
            'original_id', 'company_id', 'account_id', 'ls_type', 'statement_number',
            'currency', 'value_date', 'opening_balance', 'opening_dc',
            'closing_balance', 'closing_dc', 'section', 'source', 'status',
            'comment', 'branch_code', 'extract_no', 'line_no', 'stmt_id', 'edno',
            'eddate', 'edauthor', 'created_by', 'updated_by', 'archived_at',
            'expires_at', 'archived_by', 'original_created_at', 'original_updated_at', 'batch_id',
        ];

        $insertRows = [];
        $ids        = [];
        foreach ($rows as $row) {
            $insertRows[] = [
                $row['id'], $row['company_id'], $row['account_id'], $row['ls_type'], $row['statement_number'],
                $row['currency'], $row['value_date'], $row['opening_balance'], $row['opening_dc'],
                $row['closing_balance'], $row['closing_dc'], $row['section'], $row['source'], $row['status'],
                $row['comment'], $row['branch_code'], $row['extract_no'], $row['line_no'], $row['stmt_id'], $row['edno'],
                $row['eddate'], $row['edauthor'], $row['created_by'], $row['updated_by'], $archivedAt,
                $expiresAt, $userId, $row['created_at'], $row['updated_at'], $row['batch_id'],
            ];
            $ids[] = (int)$row['id'];
        }
        $rowsForAudit = $rows;

        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()
                ->batchInsert('{{%nostro_balance_archive}}', $archiveCols, $insertRows)
                ->execute();

            $archiveIdByOriginalId = $this->findArchiveIdsByOriginalIds($ids, $archivedAt, $cid);
            $this->writeArchiveAuditRows($rowsForAudit, $archiveIdByOriginalId, $archivedAt, $userId);

            $db->createCommand(
                'DELETE FROM {{%nostro_balance}} WHERE id = ANY(:ids)',
                [':ids' => '{' . implode(',', $ids) . '}']
            )->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        $archived  = count($ids);
        $newDone   = $totalDone + $archived;
        $remaining = $this->countPending($cid);
        $percent   = $totalAll > 0 ? min(100, (int)round($newDone / $totalAll * 100)) : 0;

        return [
            'success'     => true,
            'archived'    => $archived,
            'total_done'  => $newDone,
            'total_all'   => $totalAll,
            'remaining'   => $remaining,
            'percent'     => $percent,
            'is_finished' => $remaining === 0,
            'message'     => $remaining === 0
                ? "Готово! Заархивировано балансов: {$newDone}"
                : "Заархивировано балансов: {$newDone} из {$totalAll}",
        ];
    }

    /**
     * Восстанавливает архивный баланс в активную таблицу.
     *
     * @return array JSON-результат.
     */
    public function actionRestore(): array
    {
        return \app\services\ImportRollbackGuard::shared(function () {
            return $this->actionRestoreLocked();
        });
    }

    private function actionRestoreLocked(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $id = (int)Yii::$app->request->post('id');
        $archived = NostroBalanceArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) return ['success' => false, 'message' => 'Архивный баланс не найден'];

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $balance = $this->createBalanceFromArchive($archived);
            if (!$balance->validate() || !$balance->save(false)) {
                $transaction->rollBack();
                return ['success' => false, 'message' => $this->firstModelError($balance->errors) ?: 'Ошибка восстановления', 'errors' => $balance->errors];
            }

            NostroBalanceAudit::log(
                (int)$balance->id,
                NostroBalanceAudit::ACTION_RESTORE,
                $archived->toApiArray(),
                $balance->toApiArray(),
                'Баланс восстановлен из архива',
                (int)$archived->id
            );

            if ($archived->delete() === false) {
                $transaction->rollBack();
                return ['success' => false, 'message' => 'Ошибка удаления строки из архива'];
            }

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Баланс восстановлен из архива',
                'data'    => $balance->toApiArray(),
            ];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Удаляет архивные балансы с истёкшим сроком хранения.
     *
     * @return array JSON с количеством удалённых строк.
     */
    public function actionPurgeExpired(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $deleted = NostroBalanceArchive::deleteAll([
            'and',
            ['company_id' => $cid],
            ['<', 'expires_at', date('Y-m-d H:i:s')],
        ]);

        return ['success' => true, 'message' => "Удалено просроченных балансов: {$deleted}", 'deleted' => $deleted];
    }

    /**
     * Возвращает настройки архивирования текущей компании.
     *
     * @return array JSON-настройки.
     */
    public function actionSettings(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        return ['success' => true, 'data' => ArchiveSettings::getForCompany($cid)->toApiArray()];
    }

    /**
     * Сохраняет настройки архивирования текущей компании.
     *
     * @return array JSON-результат.
     */
    public function actionSaveSettings(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $settings = ArchiveSettings::getForCompany($cid);
        $post = Yii::$app->request->post();

        $settings->archive_after_days   = (int)($post['archive_after_days'] ?? $settings->archive_after_days);
        $settings->retention_years      = (int)($post['retention_years'] ?? $settings->retention_years);
        $settings->auto_archive_enabled = (bool)($post['auto_archive_enabled'] ?? $settings->auto_archive_enabled);
        $settings->updated_by           = Yii::$app->user->id;

        if (!$settings->validate()) {
            return ['success' => false, 'message' => 'Ошибка валидации', 'errors' => $settings->errors];
        }
        $settings->save(false);

        return ['success' => true, 'message' => 'Настройки сохранены', 'data' => $settings->toApiArray()];
    }

    /**
     * Возвращает статистику архива балансов.
     *
     * @return array JSON-статистика.
     */
    public function actionStats(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $settings = ArchiveSettings::getForCompany($cid);
        $now      = date('Y-m-d H:i:s');

        return [
            'success' => true,
            'data' => [
                'total_archived'  => (int)NostroBalanceArchive::find()->where(['company_id' => $cid])->count(),
                'pending_archive' => $this->countPending($cid),
                'expired_records' => (int)NostroBalanceArchive::find()
                    ->where(['company_id' => $cid])
                    ->andWhere(['<', 'expires_at', $now])
                    ->count(),
                'settings'        => $settings->toApiArray(),
            ],
        ];
    }

    /**
     * Возвращает счета и ностро-банки для фильтров архива.
     *
     * @return array JSON со списками.
     */
    public function actionAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'data' => [], 'pools' => []];

        $accounts = Account::find()
            ->where(['company_id' => $cid])
            ->select(['id', 'name', 'currency', 'pool_id'])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        $pools = \app\models\AccountPool::find()
            ->where(['company_id' => $cid])
            ->select(['id', 'name'])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        return ['success' => true, 'data' => $accounts, 'pools' => $pools];
    }

    /**
     * Возвращает историю архивного баланса.
     *
     * @return array JSON со списком событий аудита.
     */
    public function actionHistory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $id = (int)Yii::$app->request->get('id', 0);
        $archived = NostroBalanceArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) return ['success' => false, 'message' => 'Архивный баланс не найден'];

        $audits = $this->findBalanceAuditsForArchive((int)$archived->original_id, (int)$archived->id);

        $users = $this->loadAuditUsers($audits);
        $rows = [];
        foreach ($audits as $audit) {
            $rows[] = [
                'id'          => $audit['id'],
                'action'      => $audit['action'],
                'user_id'     => $audit['user_id'],
                'username'    => $users[$audit['user_id']] ?? ('User #' . $audit['user_id']),
                'old_values'  => $audit['old_values'] ? json_decode($audit['old_values'], true) : null,
                'new_values'  => $audit['new_values'] ? json_decode($audit['new_values'], true) : null,
                'reason'      => $audit['reason'],
                'archived_id' => $audit['archived_id'] ?? null,
                'created_at'  => $audit['created_at'],
            ];
        }

        return ['success' => true, 'data' => $rows];
    }

    /**
     * Находит все события аудита, относящиеся к текущей архивной строке баланса.
     *
     * При восстановлении баланс получает новый физический `id`, поэтому история
     * проходит назад по `restore.old_values.original_id` и архивным ID.
     *
     * @param int $balanceId Текущий `original_id` архивной строки.
     * @param int $archiveId Текущий ID архивной строки.
     * @return array События аудита от новых к старым.
     */
    private function findBalanceAuditsForArchive(int $balanceId, int $archiveId): array
    {
        $auditsById = [];
        $balanceQueue = [$balanceId];
        $archiveQueue = [$archiveId];
        $visitedBalanceIds = [];
        $visitedArchiveIds = [];

        while (!empty($balanceQueue) || !empty($archiveQueue)) {
            $balanceIds = [];
            while (!empty($balanceQueue)) {
                $id = (int)array_shift($balanceQueue);
                if ($id > 0 && !isset($visitedBalanceIds[$id])) {
                    $visitedBalanceIds[$id] = true;
                    $balanceIds[] = $id;
                }
            }

            $archiveIds = [];
            while (!empty($archiveQueue)) {
                $id = (int)array_shift($archiveQueue);
                if ($id > 0 && !isset($visitedArchiveIds[$id])) {
                    $visitedArchiveIds[$id] = true;
                    $archiveIds[] = $id;
                }
            }

            if (empty($balanceIds) && empty($archiveIds)) {
                continue;
            }

            $conditions = ['or'];
            if (!empty($balanceIds)) {
                $conditions[] = ['balance_id' => $balanceIds];
            }
            if (!empty($archiveIds)) {
                $conditions[] = ['archived_id' => $archiveIds];
            }

            $audits = NostroBalanceAudit::find()
                ->where($conditions)
                ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
                ->asArray()
                ->all();

            foreach ($audits as $audit) {
                $auditId = (int)$audit['id'];
                $auditsById[$auditId] = $audit;

                $oldValues = !empty($audit['old_values']) ? json_decode($audit['old_values'], true) : null;
                $newValues = !empty($audit['new_values']) ? json_decode($audit['new_values'], true) : null;

                if (($audit['action'] ?? '') === NostroBalanceAudit::ACTION_RESTORE && is_array($oldValues)) {
                    if (!empty($oldValues['original_id'])) {
                        $balanceQueue[] = (int)$oldValues['original_id'];
                    }
                    if (!empty($oldValues['id'])) {
                        $archiveQueue[] = (int)$oldValues['id'];
                    }
                }

                if (($audit['action'] ?? '') === NostroBalanceAudit::ACTION_ARCHIVE && is_array($oldValues) && !empty($oldValues['id'])) {
                    $balanceQueue[] = (int)$oldValues['id'];
                }

                if (is_array($newValues) && !empty($newValues['id'])) {
                    $balanceQueue[] = (int)$newValues['id'];
                }
                if (!empty($audit['archived_id'])) {
                    $archiveQueue[] = (int)$audit['archived_id'];
                }
            }
        }

        $result = array_values($auditsById);
        usort($result, function ($a, $b) {
            return (int)$b['id'] <=> (int)$a['id'];
        });

        return $result;
    }

    /**
     * Применяет полнотекстовый фильтр по безопасному whitelist полей.
     *
     * @param \yii\db\ActiveQuery $query Запрос архива.
     * @param array $filters Фильтры UI.
     * @return void
     */
    private function applySearchFilter(\yii\db\ActiveQuery $query, array $filters): void
    {
        $searchValue = trim((string)($filters['search_value'] ?? ''));
        if ($searchValue === '') {
            return;
        }

        $fields = [
            'statement_number' => 'nba.statement_number',
            'comment'          => 'nba.comment',
            'branch_code'      => 'nba.branch_code',
            'extract_no'       => 'CAST(nba.extract_no AS TEXT)',
            'line_no'          => 'CAST(nba.line_no AS TEXT)',
            'stmt_id'          => 'CAST(nba.stmt_id AS TEXT)',
            'edno'             => 'CAST(nba.edno AS TEXT)',
            'edauthor'         => 'nba.edauthor',
        ];

        $searchField = (string)($filters['search_field'] ?? '');
        if ($searchField && isset($fields[$searchField])) {
            $query->andWhere(
                new \yii\db\Expression($fields[$searchField] . ' ILIKE :balance_archive_search', [
                    ':balance_archive_search' => '%' . $searchValue . '%',
                ])
            );
            return;
        }

        $parts = [];
        $params = [];
        $i = 0;
        foreach ($fields as $expr) {
            $param = ':balance_archive_search_' . $i++;
            $parts[] = $expr . ' ILIKE ' . $param;
            $params[$param] = '%' . $searchValue . '%';
        }
        $query->andWhere(new \yii\db\Expression('(' . implode(' OR ', $parts) . ')', $params));
    }

    /**
     * Считает ожидающие архивирования балансы.
     *
     * @param int $cid ID компании.
     * @return int Количество строк.
     */
    private function countPending(int $cid): int
    {
        $settings = ArchiveSettings::getForCompany($cid);
        return (int)NostroBalance::find()
            ->where(['company_id' => $cid, 'status' => [NostroBalance::STATUS_NORMAL, NostroBalance::STATUS_CONFIRMED]])
            ->andWhere(['is_intermediate' => false])
            ->andWhere(['<', 'value_date', $this->cutoffDate($settings)])
            ->count();
    }

    /**
     * Возвращает дату порога архивирования балансов.
     *
     * @param ArchiveSettings $settings Настройки компании.
     * @return string Дата в формате `Y-m-d`.
     */
    private function cutoffDate(ArchiveSettings $settings): string
    {
        return date('Y-m-d', strtotime("-{$settings->archive_after_days} days"));
    }

    /**
     * Находит архивные ID после batchInsert.
     *
     * @param int[] $originalIds Исходные ID балансов.
     * @param string $archivedAt Timestamp архивации.
     * @param int $cid ID компании.
     * @return array Карта `original_id => archive_id`.
     */
    private function findArchiveIdsByOriginalIds(array $originalIds, string $archivedAt, int $cid): array
    {
        if (!$originalIds) {
            return [];
        }

        $rows = NostroBalanceArchive::find()
            ->select(['id', 'original_id'])
            ->where(['company_id' => $cid, 'original_id' => $originalIds, 'archived_at' => $archivedAt])
            ->asArray()
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['original_id']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * Пишет batch-аудит архивации балансов.
     *
     * @param array $rows Активные строки.
     * @param array $archiveIdByOriginalId Карта архивных ID.
     * @param string $archivedAt Timestamp архивации.
     * @param int $userId ID пользователя.
     * @return void
     */
    private function writeArchiveAuditRows(array $rows, array $archiveIdByOriginalId, string $archivedAt, int $userId): void
    {
        if (!$rows) {
            return;
        }

        $auditRows = [];
        foreach ($rows as $row) {
            $originalId = (int)$row['id'];
            $auditRows[] = [
                $originalId,
                $userId,
                NostroBalanceAudit::ACTION_ARCHIVE,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                'Баланс заархивирован',
                $archivedAt,
                $archiveIdByOriginalId[$originalId] ?? null,
            ];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_balance_audit}}', [
                'balance_id', 'user_id', 'action', 'old_values', 'new_values',
                'reason', 'created_at', 'archived_id',
            ], $auditRows)
            ->execute();
    }

    /**
     * Создаёт активный баланс из архивной строки.
     *
     * @param NostroBalanceArchive $archived Архивная строка.
     * @return NostroBalance Несохранённая модель.
     */
    private function createBalanceFromArchive(NostroBalanceArchive $archived): NostroBalance
    {
        $balance = new NostroBalance();
        foreach ([
            'company_id', 'account_id', 'ls_type', 'statement_number', 'currency', 'value_date',
            'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc', 'section', 'source',
            'status', 'comment', 'branch_code', 'extract_no', 'line_no', 'stmt_id', 'edno',
            'eddate', 'edauthor', 'batch_id',
        ] as $attribute) {
            if ($balance->hasAttribute($attribute) && $archived->hasAttribute($attribute)) {
                $balance->$attribute = $archived->$attribute;
            }
        }

        return $balance;
    }

    /**
     * Форматирует даты строки архива для UI.
     *
     * @param array $row Строка по ссылке.
     * @return void
     */
    private function formatArchiveRowDates(array &$row): void
    {
        if (!empty($row['value_date'])) {
            $row['value_date_fmt'] = date('d.m.Y', strtotime($row['value_date']));
        }
        if (!empty($row['eddate'])) {
            $row['eddate_fmt'] = date('d.m.Y', strtotime($row['eddate']));
        }
        if (!empty($row['archived_at'])) {
            $row['archived_at_fmt'] = date('d.m.Y H:i', strtotime($row['archived_at']));
        }
        if (!empty($row['expires_at'])) {
            $row['expires_at_fmt'] = date('d.m.Y', strtotime($row['expires_at']));
        }
    }

    /**
     * Нормализует decimal-фильтр без приведения к float.
     *
     * @param mixed $value Значение фильтра.
     * @return string Нормализованная строка.
     */
    private function normalizeDecimalFilter($value): string
    {
        return str_replace(',', '.', trim((string)$value));
    }

    /**
     * Возвращает первую ошибку модели.
     *
     * @param array $errors Ошибки Yii-модели.
     * @return string|null Текст первой ошибки.
     */
    private function firstModelError(array $errors): ?string
    {
        foreach ($errors as $messages) {
            if (!empty($messages[0])) {
                return $messages[0];
            }
        }
        return null;
    }

    /**
     * Загружает имена пользователей для событий аудита.
     *
     * @param array $audits События аудита.
     * @return array Карта `user_id => username`.
     */
    private function loadAuditUsers(array $audits): array
    {
        $userIds = array_unique(array_filter(array_column($audits, 'user_id')));
        if (!$userIds) {
            return [];
        }

        $rows = \app\models\User::find()
            ->select(['id', 'username', 'email'])
            ->where(['id' => $userIds])
            ->asArray()
            ->all();

        $users = [];
        foreach ($rows as $row) {
            $users[$row['id']] = $row['username'] ?: $row['email'];
        }
        return $users;
    }
}
