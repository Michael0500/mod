<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\NostroEntryAudit;
use app\models\NostroEntryArchive;
use app\models\ArchiveSettings;
use app\models\Account;

/**
 * JSON-контроллер архивирования записей выверки.
 *
 * Архивирование переносит сквитованные записи из `nostro_entries` в
 * `nostro_entries_archive` пакетами, пишет явные события аудита и удаляет
 * активные строки. Восстановление выполняется группой по `match_id`, чтобы
 * сохранить целостность квитования.
 */
class ArchiveController extends BaseController
{
    /**
     * Отключает CSRF для JSON API архива.
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
     * Рендерит отдельную страницу архива.
     *
     * @return string HTML страницы `views/archive/page.php`.
     */
    public function actionPage()
    {
        $this->view->title = 'Архив';
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
     * Возвращает постраничный список архивных записей.
     *
     * GET `/archive/list`. Поддерживает фильтры по ностро-банку, счёту,
     * L/S, D/C, валюте, датам, сумме и поиску по ID-полям.
     *
     * @return array JSON с архивными строками и параметрами пагинации.
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

        $sortable = ['id', 'original_id', 'match_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'matched_at', 'archived_at', 'expires_at',
            'account_id', 'comment'];
        if (!in_array($sort, $sortable, true)) $sort = 'archived_at';

        $q = NostroEntryArchive::find()
            ->from(['na' => NostroEntryArchive::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = na.account_id')
            ->leftJoin(['ap' => 'account_pools'], 'ap.id = a.pool_id')
            ->where(['na.company_id' => $cid])
            ->addSelect(['na.*', 'a.name AS account_name', 'ap.name AS pool_name']);

        // ── Фильтры ──────────────────────────────────────────────

        // Ностро банк / счёт
        if (!empty($filters['account_pool_id'])) {
            $q->andWhere(['a.pool_id' => (int)$filters['account_pool_id']]);
        }
        if (!empty($filters['account_id'])) {
            $q->andWhere(['na.account_id' => (int)$filters['account_id']]);
        }
        if (!empty($filters['account_name'])) {
            $q->andWhere(['ilike', 'a.name', $filters['account_name']]);
        }

        // L/S
        if (!empty($filters['ls'])) {
            $q->andWhere(['na.ls' => $filters['ls']]);
        }

        // D/C
        if (!empty($filters['dc'])) {
            $q->andWhere(['na.dc' => $filters['dc']]);
        }

        // Валюта (одна или массив)
        if (!empty($filters['currency'])) {
            $q->andWhere(is_array($filters['currency'])
                ? ['na.currency' => $filters['currency']]
                : ['na.currency' => $filters['currency']]);
        }

        // Даты валютирования
        if (!empty($filters['value_date_from'])) {
            $q->andWhere(['>=', 'na.value_date', $filters['value_date_from']]);
        }
        if (!empty($filters['value_date_to'])) {
            $q->andWhere(['<=', 'na.value_date', $filters['value_date_to']]);
        }

        // Даты проводки
        if (!empty($filters['post_date_from'])) {
            $q->andWhere(['>=', 'na.post_date', $filters['post_date_from']]);
        }
        if (!empty($filters['post_date_to'])) {
            $q->andWhere(['<=', 'na.post_date', $filters['post_date_to']]);
        }

        // Даты архивирования
        if (!empty($filters['archived_at_from'])) {
            $q->andWhere(['>=', 'DATE(na.archived_at)', $filters['archived_at_from']]);
        }
        if (!empty($filters['archived_at_to'])) {
            $q->andWhere(['<=', 'DATE(na.archived_at)', $filters['archived_at_to']]);
        }

        // Сумма
        if (!empty($filters['amount_min'])) {
            $q->andWhere(['>=', 'na.amount', (float)$filters['amount_min']]);
        }
        if (!empty($filters['amount_max'])) {
            $q->andWhere(['<=', 'na.amount', (float)$filters['amount_max']]);
        }

        // Поиск по конкретному полю
        $searchField = $filters['search_field'] ?? '';
        $searchValue = trim($filters['search_value'] ?? '');
        $searchFields = [
            'match_id', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'comment',
        ];

        if ($searchValue !== '') {
            if ($searchField && in_array($searchField, $searchFields, true)) {
                // Поиск в конкретном поле
                $q->andWhere(['ilike', "na.{$searchField}", $searchValue]);
            } else {
                // Поиск по всем ID-полям
                $q->andWhere(['or',
                    ['ilike', 'na.match_id',        $searchValue],
                    ['ilike', 'na.instruction_id',  $searchValue],
                    ['ilike', 'na.end_to_end_id',   $searchValue],
                    ['ilike', 'na.transaction_id',  $searchValue],
                    ['ilike', 'na.message_id',       $searchValue],
                    ['ilike', 'na.other_id',         $searchValue],
                    ['ilike', 'na.comment',          $searchValue],
                ]);
            }
        }

        $total  = (int)(clone $q)->count('na.id');
        $offset = ($page - 1) * $limit;

        $rows = $q->orderBy(["na.{$sort}" => $dir])
            ->limit($limit)
            ->offset($offset)
            ->asArray()
            ->all();

        // Форматирование дат
        foreach ($rows as &$row) {
            if ($row['value_date'])   $row['value_date_fmt']   = date('d.m.Y', strtotime($row['value_date']));
            if ($row['post_date'])    $row['post_date_fmt']    = date('d.m.Y', strtotime($row['post_date']));
            if ($row['matched_at'])    $row['matched_at_fmt']    = date('d.m.Y H:i', strtotime($row['matched_at']));
            if ($row['archived_at'])  $row['archived_at_fmt']  = date('d.m.Y H:i', strtotime($row['archived_at']));
            if ($row['expires_at'])   $row['expires_at_fmt']   = date('d.m.Y', strtotime($row['expires_at']));
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
     * Возвращает количество записей, ожидающих архивирования.
     *
     * POST `/archive/count`. Считает сквитованные записи старше
     * `archive_after_days` по `matched_at`; `updated_at` намеренно не используется.
     *
     * @return array JSON с полем `total`.
     */
    public function actionCount(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));

        $total = (int)NostroEntry::find()
            ->where(['company_id' => $cid, 'match_status' => NostroEntry::STATUS_MATCHED])
            ->andWhere(['is not', 'match_id', null])
            ->andWhere(['is not', 'matched_at', null])
            ->andWhere(['<', 'matched_at', $cutoffDate])
            ->count();

        return ['success' => true, 'total' => $total];
    }

    /**
     * Архивирует одну порцию подходящих записей.
     *
     * POST `/archive/run-batch`. Клиент вызывает метод повторно, пока
     * `is_finished` не станет `true`. Для производительности используется
     * `batchInsert`, явная запись audit-событий и `DELETE WHERE id = ANY(...)`.
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

        $batchSize  = 300;
        $totalDone  = (int)Yii::$app->request->post('total_done', 0);
        $totalAll   = (int)Yii::$app->request->post('total_all',  0);

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));
        $archivedAt = date('Y-m-d H:i:s');
        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$settings->retention_years} years"));
        $userId     = Yii::$app->user->id;
        $db         = Yii::$app->db;

        // Берём ровно $batchSize строк через чистый SQL — минимум памяти
        $rows = $db->createCommand("
            SELECT id, account_id, company_id, match_id, ls, dc, amount, currency,
                   value_date, post_date, instruction_id, end_to_end_id,
                   transaction_id, message_id, statement_number, other_id, comment, source,
                   matched_at, created_at, updated_at, batch_id
            FROM {{%nostro_entries}}
            WHERE company_id   = :cid
              AND match_status = 'M'
              AND match_id     IS NOT NULL
              AND matched_at   IS NOT NULL
              AND matched_at   < :cutoff
            ORDER BY id ASC
            LIMIT :lim
        ", [':cid' => $cid, ':cutoff' => $cutoffDate, ':lim' => $batchSize])
            ->queryAll();

        if (empty($rows)) {
            return [
                'success'     => true,
                'archived'    => 0,
                'total_done'  => $totalDone,
                'total_all'   => $totalAll,
                'remaining'   => 0,
                'is_finished' => true,
                'message'     => "Готово! Заархивировано: {$totalDone}",
            ];
        }

        $archiveCols = [
            'original_id','account_id','company_id','match_id',
            'ls','dc','amount','currency','value_date','post_date',
            'instruction_id','end_to_end_id','transaction_id','message_id','statement_number','other_id',
            'comment','source','match_status',
            'matched_at',
            'archived_at','expires_at','archived_by',
            'original_created_at','original_updated_at',
            'batch_id',
        ];

        $insertRows = [];
        $ids        = [];
        foreach ($rows as $r) {
            $insertRows[] = [
                $r['id'], $r['account_id'], $r['company_id'], $r['match_id'],
                $r['ls'], $r['dc'], $r['amount'], $r['currency'],
                $r['value_date'], $r['post_date'],
                $r['instruction_id'], $r['end_to_end_id'],
                $r['transaction_id'], $r['message_id'], $r['statement_number'], $r['other_id'],
                $r['comment'], $r['source'],
                'A',
                $r['matched_at'],
                $archivedAt, $expiresAt, $userId,
                $r['created_at'], $r['updated_at'],
                $r['batch_id'],
            ];
            $ids[] = (int)$r['id'];
        }
        $rowsForAudit = $rows;
        unset($rows); // дальше используем только компактный буфер для audit

        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()
                ->batchInsert('{{%nostro_entries_archive}}', $archiveCols, $insertRows)
                ->execute();

            $archiveIdByOriginalId = $this->findArchiveIdsByOriginalIds($ids, $archivedAt);
            $this->writeArchiveAuditRows($rowsForAudit, $archiveIdByOriginalId, $archivedAt, $userId);

            // PostgreSQL: DELETE WHERE id = ANY(ARRAY[...]) — один параметр, работает с любым кол-вом ID
            $db->createCommand(
                'DELETE FROM {{%nostro_entries}} WHERE id = ANY(:ids)',
                [':ids' => '{' . implode(',', $ids) . '}']
            )->execute();

            $transaction->commit();
        } catch (\Exception $ex) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка: ' . $ex->getMessage()];
        }

        unset($insertRows);

        $archived  = count($ids);
        $newDone   = $totalDone + $archived;

        // Быстрый COUNT — только цифра, без данных
        $remaining = (int)$db->createCommand("
            SELECT COUNT(*) FROM {{%nostro_entries}}
            WHERE company_id   = :cid
              AND match_status = 'M'
              AND match_id     IS NOT NULL
              AND matched_at   IS NOT NULL
              AND matched_at   < :cutoff
        ", [':cid' => $cid, ':cutoff' => $cutoffDate])->queryScalar();

        $percent = $totalAll > 0 ? min(100, (int)round($newDone / $totalAll * 100)) : 0;

        return [
            'success'     => true,
            'archived'    => $archived,
            'total_done'  => $newDone,
            'total_all'   => $totalAll,
            'remaining'   => $remaining,
            'percent'     => $percent,
            'is_finished' => $remaining === 0,
            'message'     => $remaining === 0
                ? "Готово! Заархивировано: {$newDone}"
                : "Заархивировано: {$newDone} из {$totalAll}",
        ];
    }

    /**
     * Показывает архивные строки, которые будут восстановлены группой.
     *
     * GET `/archive/restore-preview?id=`. Группа определяется по `match_id`
     * выбранной архивной строки.
     *
     * @return array JSON с количеством и строками предпросмотра.
     */
    public function actionRestorePreview(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $id = (int)Yii::$app->request->get('id');
        $archived = NostroEntryArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) return ['success' => false, 'message' => 'Архивная запись не найдена'];

        $rows = $this->findArchiveRestoreGroup($archived)->asArray()->all();

        return [
            'success'  => true,
            'match_id' => $archived->match_id,
            'count'    => count($rows),
            'data'     => array_map([$this, 'formatRestorePreviewRow'], $rows),
        ];
    }

    /**
     * Восстанавливает группу архивных записей в активную таблицу.
     *
     * POST `/archive/restore`. Все архивные строки с тем же `match_id`
     * переносятся обратно в `nostro_entries` в одной транзакции, затем
     * удаляются из архива. Для новых физических строк пишется restore-аудит.
     *
     * @return array JSON-результат восстановления.
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

        $id  = (int)Yii::$app->request->post('id');

        $archived = NostroEntryArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) return ['success' => false, 'message' => 'Архивная запись не найдена'];

        $archiveRows = $this->findArchiveRestoreGroup($archived)->all();
        if (!$archiveRows) return ['success' => false, 'message' => 'Связанные архивные записи не найдены'];

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $restoredRows = [];
            foreach ($archiveRows as $archiveRow) {
                $entry = $this->createEntryFromArchive($archiveRow);

                if (!$entry->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка восстановления', 'errors' => $entry->errors];
                }

                $this->writeRestoreAuditRow($archiveRow, $entry);

                $restoredRows[] = $this->formatRestorePreviewRow($archiveRow->toArray(), $entry->id);
                if ($archiveRow->delete() === false) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка удаления записи из архива'];
                }
            }

            $transaction->commit();

            $count = count($restoredRows);
            return [
                'success'  => true,
                'message'  => $count === 1
                    ? 'Запись восстановлена из архива'
                    : "Восстановлено записей из архива: {$count}",
                'count'    => $count,
                'match_id' => $archived->match_id,
                'data'     => $restoredRows,
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Возвращает запрос архивной группы для восстановления.
     *
     * @param NostroEntryArchive $archived Выбранная архивная запись.
     * @return \yii\db\ActiveQuery Запрос всех архивных строк той же компании и `match_id`.
     */
    private function findArchiveRestoreGroup(NostroEntryArchive $archived): \yii\db\ActiveQuery
    {
        return NostroEntryArchive::find()
            ->where([
                'company_id' => $archived->company_id,
                'match_id'   => $archived->match_id,
            ])
            ->orderBy(['original_id' => SORT_ASC, 'id' => SORT_ASC]);
    }

    /**
     * Создаёт активную запись из архивной строки.
     *
     * `matched_at` и `match_id` сохраняются, чтобы восстановленная группа
     * оставалась сквитованной. Автоматический create-аудит отключается,
     * потому что контроллер пишет специальное событие restore.
     *
     * @param NostroEntryArchive $archived Архивная строка.
     * @return NostroEntry Несохранённая активная модель.
     */
    private function createEntryFromArchive(NostroEntryArchive $archived): NostroEntry
    {
        $entry                   = new NostroEntry();
        $entry->account_id       = $archived->account_id;
        $entry->company_id       = $archived->company_id;
        $entry->match_id         = $archived->match_id;
        $entry->ls               = $archived->ls;
        $entry->dc               = $archived->dc;
        $entry->amount           = $archived->amount;
        $entry->currency         = $archived->currency;
        $entry->value_date       = $archived->value_date;
        $entry->post_date        = $archived->post_date;
        $entry->instruction_id   = $archived->instruction_id;
        $entry->end_to_end_id    = $archived->end_to_end_id;
        $entry->transaction_id   = $archived->transaction_id;
        $entry->message_id       = $archived->message_id;
        $entry->statement_number = $archived->statement_number;
        $entry->other_id         = $archived->other_id;
        $entry->comment          = $archived->comment;
        $entry->source           = $archived->source;
        $entry->match_status     = NostroEntry::STATUS_MATCHED;
        $entry->matched_at       = $archived->matched_at;
        $entry->batch_id         = $archived->batch_id;
        $entry->skipAudit        = true;

        return $entry;
    }

    /**
     * Форматирует строку предпросмотра или результата восстановления.
     *
     * @param array $row Архивная строка в виде массива.
     * @param int|null $newId Новый ID активной записи после восстановления.
     * @return array JSON-совместимая строка для UI.
     */
    private function formatRestorePreviewRow(array $row, ?int $newId = null): array
    {
        return [
            'id'          => isset($row['id']) ? (int)$row['id'] : null,
            'original_id' => isset($row['original_id']) ? (int)$row['original_id'] : null,
            'new_id'      => $newId,
            'match_id'    => $row['match_id'] ?? null,
            'ls'          => $row['ls'] ?? null,
            'dc'          => $row['dc'] ?? null,
            'amount'      => isset($row['amount']) ? (float)$row['amount'] : null,
            'currency'    => $row['currency'] ?? null,
            'value_date'  => $row['value_date'] ?? null,
            'account_id'  => isset($row['account_id']) ? (int)$row['account_id'] : null,
        ];
    }

    /**
     * Удаляет архивные записи с истёкшим сроком хранения.
     *
     * POST `/archive/purge-expired`. Удаление ограничено текущей компанией.
     *
     * @return array JSON с количеством удалённых строк.
     */
    public function actionPurgeExpired(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $now     = date('Y-m-d H:i:s');
        $deleted = NostroEntryArchive::deleteAll([
            'and',
            ['company_id' => $cid],
            ['<', 'expires_at', $now],
        ]);

        return ['success' => true, 'message' => "Удалено просроченных записей: {$deleted}", 'deleted' => $deleted];
    }

    /**
     * Возвращает настройки архивирования текущей компании.
     *
     * GET `/archive/settings`.
     *
     * @return array JSON с настройками или ошибкой.
     */
    public function actionSettings(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $s = ArchiveSettings::getForCompany($cid);
        return ['success' => true, 'data' => $s->toApiArray()];
    }

    /**
     * Сохраняет настройки архивирования текущей компании.
     *
     * POST `/archive/settings`. Если записи настроек ещё нет, используется
     * дефолтная модель `ArchiveSettings::getForCompany()`.
     *
     * @return array JSON с сохранёнными настройками или ошибками валидации.
     */
    public function actionSaveSettings(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $s = ArchiveSettings::getForCompany($cid);
        $p = Yii::$app->request->post();

        $s->archive_after_days   = (int)($p['archive_after_days']   ?? $s->archive_after_days);
        $s->retention_years      = (int)($p['retention_years']       ?? $s->retention_years);
        $s->auto_archive_enabled = (bool)($p['auto_archive_enabled'] ?? $s->auto_archive_enabled);
        $s->updated_by           = Yii::$app->user->id;

        if (!$s->validate()) {
            return ['success' => false, 'message' => 'Ошибка валидации', 'errors' => $s->errors];
        }
        $s->save(false);

        return ['success' => true, 'message' => 'Настройки сохранены', 'data' => $s->toApiArray()];
    }

    /**
     * Возвращает статистику архива текущей компании.
     *
     * GET `/archive/stats`: всего в архиве, ожидают архивирования,
     * просрочены по retention и текущие настройки.
     *
     * @return array JSON со статистикой.
     */
    public function actionStats(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));
        $now        = date('Y-m-d H:i:s');

        $totalArchived = (int)NostroEntryArchive::find()
            ->where(['company_id' => $cid])
            ->count();

        $expiredCount = (int)NostroEntryArchive::find()
            ->where(['company_id' => $cid])
            ->andWhere(['<', 'expires_at', $now])
            ->count();

        $pendingCount = (int)NostroEntry::find()
            ->where(['company_id' => $cid, 'match_status' => NostroEntry::STATUS_MATCHED])
            ->andWhere(['is not', 'match_id', null])
            ->andWhere(['is not', 'matched_at', null])
            ->andWhere(['<', 'matched_at', $cutoffDate])
            ->count();

        return [
            'success' => true,
            'data'    => [
                'total_archived'  => $totalArchived,
                'pending_archive' => $pendingCount,
                'expired_records' => $expiredCount,
                'settings'        => $settings->toApiArray(),
            ],
        ];
    }

    /**
     * Возвращает счета текущей компании для фильтров архива.
     *
     * @return array JSON со списком счетов.
     */
    public function actionAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'data' => []];

        $rows = Account::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return [
            'success' => true,
            'data'    => array_map(fn($a) => [
                'id'       => $a->id,
                'name'     => $a->name,
                'currency' => $a->currency,
            ], $rows),
        ];
    }

    /**
     * Возвращает историю изменений архивной записи.
     *
     * GET `/archive/history?id=`. История ищется по `original_id` и следует
     * через цепочки restore/archive, чтобы показать изменения до архивации.
     *
     * @return array JSON со снапшотами записи после событий аудита.
     */
    public function actionHistory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $id  = (int)Yii::$app->request->get('id', 0);

        $archived = NostroEntryArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) {
            return ['success' => false, 'message' => 'Архивная запись не найдена'];
        }

        $baseState = [
            'account_id'     => $archived->account_id,
            'ls'             => $archived->ls,
            'dc'             => $archived->dc,
            'amount'         => $archived->amount,
            'currency'       => $archived->currency,
            'value_date'     => $archived->value_date,
            'post_date'      => $archived->post_date,
            'instruction_id' => $archived->instruction_id,
            'end_to_end_id'  => $archived->end_to_end_id,
            'transaction_id' => $archived->transaction_id,
            'message_id'     => $archived->message_id,
            'other_id'       => $archived->other_id,
            'comment'        => $archived->comment,
            'match_status'   => $archived->match_status,
            'match_id'       => $archived->match_id,
            'matched_at'     => $archived->matched_at,
        ];

        return ['success' => true, 'data' => $this->buildEntryHistoryRows((int)$archived->original_id, $cid, $baseState)];
    }

    /**
     * Собирает историю записи в формате общей Vue-модалки.
     *
     * @param int $entryId Исходный ID активной записи.
     * @param int $cid ID компании.
     * @param array $baseState Состояние архивной строки как база для реконструкции.
     * @return array Строки истории от новых к старым.
     */
    private function buildEntryHistoryRows(int $entryId, int $cid, array $baseState): array
    {
        $audits = $this->findEntryAuditsForArchive($entryId);

        if (empty($audits)) {
            return [];
        }

        $userIds = array_unique(array_column($audits, 'user_id'));
        $users   = [];
        if (!empty($userIds)) {
            $userRows = \app\models\User::find()
                ->select(['id', 'username', 'email'])
                ->where(['id' => $userIds])
                ->asArray()
                ->all();
            foreach ($userRows as $u) {
                $users[$u['id']] = $u['username'] ?: $u['email'];
            }
        }

        $groups = [];
        $groupOrder = [];
        foreach ($audits as $audit) {
            $ts  = substr($audit['created_at'], 0, 19);
            $key = $ts . '|' . $audit['user_id'] . '|' . $audit['action'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'            => $key,
                    'action'         => $audit['action'],
                    'user_id'        => $audit['user_id'],
                    'username'       => $users[$audit['user_id']] ?? ('User #' . $audit['user_id']),
                    'reason'         => $audit['reason'],
                    'created_at'     => $ts,
                    'changes'        => [],
                    'snapshot'       => [],
                    'changed_fields' => [],
                ];
                $groupOrder[] = $key;
            }

            $field   = $audit['changed_field'];
            $oldVals = $audit['old_values'] ? json_decode($audit['old_values'], true) : null;
            $newVals = $audit['new_values'] ? json_decode($audit['new_values'], true) : null;

            if ($field) {
                $oldV = is_array($oldVals) ? ($oldVals[$field] ?? null) : null;
                $newV = is_array($newVals) ? ($newVals[$field] ?? null) : null;
                $groups[$key]['changes'][$field] = ['old' => $oldV, 'new' => $newV];
                $groups[$key]['changed_fields'][] = $field;
            } elseif ($oldVals || $newVals) {
                $fieldsInEvent = array_unique(array_merge(
                    is_array($oldVals) ? array_keys($oldVals) : [],
                    is_array($newVals) ? array_keys($newVals) : []
                ));
                foreach ($fieldsInEvent as $eventField) {
                    $groups[$key]['changes'][$eventField] = [
                        'old' => is_array($oldVals) ? ($oldVals[$eventField] ?? null) : null,
                        'new' => is_array($newVals) ? ($newVals[$eventField] ?? null) : null,
                    ];
                }
            }
        }

        $fields = ['account_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'comment',
            'match_status', 'match_id', 'matched_at'];

        $current = array_fill_keys($fields, null);
        $firstGroup = $groups[$groupOrder[0]];
        if ($firstGroup['action'] === NostroEntryAudit::ACTION_CREATE) {
            foreach ($fields as $f) {
                $current[$f] = $firstGroup['changes'][$f]['new'] ?? null;
            }
        } else {
            foreach ($fields as $f) {
                $current[$f] = $baseState[$f] ?? null;
            }
            foreach (array_reverse($groupOrder) as $key) {
                foreach ($groups[$key]['changes'] as $field => $change) {
                    if (in_array($field, $fields, true)) {
                        $current[$field] = $change['old'];
                    }
                }
            }
        }

        foreach ($groupOrder as $key) {
            $group = &$groups[$key];
            $action = $group['action'];

            if ($action === NostroEntryAudit::ACTION_CREATE) {
                foreach ($fields as $f) {
                    $current[$f] = $group['changes'][$f]['new'] ?? $current[$f];
                }
                $group['snapshot'] = $current;
            } elseif ($action === NostroEntryAudit::ACTION_DELETE
                || $action === NostroEntryAudit::ACTION_ARCHIVE) {
                $snap = $current;
                foreach ($group['changes'] as $field => $change) {
                    if (in_array($field, $fields, true) && $change['old'] !== null) {
                        $snap[$field] = $change['old'];
                    }
                }
                if ($action === NostroEntryAudit::ACTION_ARCHIVE) {
                    $snap['match_status'] = NostroEntryArchive::STATUS_ARCHIVED;
                }
                $group['snapshot'] = $snap;
                $current = $snap;
            } else {
                foreach ($group['changes'] as $field => $change) {
                    if (in_array($field, $fields, true)) {
                        $current[$field] = $change['new'];
                    }
                }
                $group['snapshot'] = $current;
            }
            unset($group);
        }

        $accountIds = [];
        foreach ($groups as $g) {
            if (!empty($g['snapshot']['account_id'])) {
                $accountIds[] = (int)$g['snapshot']['account_id'];
            }
            if (!empty($g['changes']['account_id'])) {
                foreach (['old', 'new'] as $side) {
                    if (!empty($g['changes']['account_id'][$side])) {
                        $accountIds[] = (int)$g['changes']['account_id'][$side];
                    }
                }
            }
        }
        $accountIds = array_unique($accountIds);
        $accountNames = [];
        if (!empty($accountIds)) {
            $accRows = Account::find()
                ->select(['id', 'name'])
                ->where(['company_id' => $cid, 'id' => $accountIds])
                ->asArray()
                ->all();
            foreach ($accRows as $a) {
                $accountNames[$a['id']] = $a['name'];
            }
        }

        $rows = [];
        foreach (array_reverse($groupOrder) as $key) {
            $g = $groups[$key];
            $snap = $g['snapshot'];
            $changes = $g['changes'];
            if (!empty($snap['account_id'])) {
                $snap['account_name'] = $accountNames[$snap['account_id']] ?? ('ID: ' . $snap['account_id']);
            } else {
                $snap['account_name'] = '—';
            }
            if (!empty($changes['account_id'])) {
                foreach (['old', 'new'] as $side) {
                    $accountId = $changes['account_id'][$side] ?? null;
                    $changes['account_id'][$side . '_name'] = $accountId
                        ? ($accountNames[(int)$accountId] ?? ('ID: ' . $accountId))
                        : null;
                }
            }

            $rows[] = [
                'action'         => $g['action'],
                'user_id'        => $g['user_id'],
                'username'       => $g['username'],
                'reason'         => $g['reason'],
                'created_at'     => $g['created_at'],
                'changed_fields' => array_values(array_unique($g['changed_fields'])),
                'snapshot'       => $snap,
                'changes'        => $changes,
            ];
        }

        return $rows;
    }

    /**
     * Находит ID архивных строк по исходным ID и времени архивации.
     *
     * Используется сразу после batchInsert, чтобы связать audit-события
     * с созданными архивными строками.
     *
     * @param int[] $originalIds Исходные ID активных записей.
     * @param string $archivedAt Timestamp batch-архивирования.
     * @return array Карта `original_id => archive_id`.
     */
    private function findArchiveIdsByOriginalIds(array $originalIds, string $archivedAt): array
    {
        if (empty($originalIds)) {
            return [];
        }

        $rows = NostroEntryArchive::find()
            ->select(['id', 'original_id'])
            ->where(['original_id' => $originalIds])
            ->andWhere(['archived_at' => $archivedAt])
            ->asArray()
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['original_id']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * Пишет batch-события аудита архивации.
     *
     * Метод нужен, потому что архивирование использует raw SQL и не вызывает
     * хуки `NostroEntry::beforeDelete()` для каждой строки.
     *
     * @param array $rows Активные строки, перенесённые в архив.
     * @param array $archiveIdByOriginalId Карта `original_id => archive_id`.
     * @param string $archivedAt Timestamp архивации.
     * @param int $userId ID пользователя.
     * @return void
     */
    private function writeArchiveAuditRows(array $rows, array $archiveIdByOriginalId, string $archivedAt, int $userId): void
    {
        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        foreach ($rows as $row) {
            $originalId = (int)$row['id'];
            $auditRows[] = [
                $originalId,
                $userId,
                NostroEntryAudit::ACTION_ARCHIVE,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                null,
                $archiveIdByOriginalId[$originalId] ?? null,
                'Запись заархивирована',
                $archivedAt,
            ];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_entry_audit}}', [
                'entry_id', 'user_id', 'action', 'old_values', 'new_values',
                'changed_field', 'archived_id', 'reason', 'created_at',
            ], $auditRows)
            ->execute();
    }

    /**
     * Пишет событие аудита восстановления архивной строки.
     *
     * @param NostroEntryArchive $archiveRow Исходная архивная строка.
     * @param NostroEntry $entry Новая активная запись.
     * @return void
     */
    private function writeRestoreAuditRow(NostroEntryArchive $archiveRow, NostroEntry $entry): void
    {
        NostroEntryAudit::log(
            (int)$entry->id,
            NostroEntryAudit::ACTION_RESTORE,
            $archiveRow->toArray(),
            $entry->getAttributes(),
            null,
            (int)$archiveRow->id,
            'Запись восстановлена из архива'
        );
    }

    /**
     * Находит все события аудита, относящиеся к архивной записи.
     *
     * Метод обходит цепочки восстановлений через `restore.old_values.original_id`
     * и убирает технические create-события новых физических строк.
     *
     * @param int $entryId Исходный ID записи.
     * @return array События аудита от старых к новым.
     */
    private function findEntryAuditsForArchive(int $entryId): array
    {
        $auditsById = [];
        $visitedEntryIds = [];
        $restoredEntryIds = [];
        $queue = [$entryId];

        while (!empty($queue)) {
            $currentEntryId = (int)array_shift($queue);
            if ($currentEntryId <= 0 || isset($visitedEntryIds[$currentEntryId])) {
                continue;
            }
            $visitedEntryIds[$currentEntryId] = true;

            foreach ($this->findAuditsByEntryId($currentEntryId) as $audit) {
                $auditId = (int)$audit['id'];
                $auditsById[$auditId] = $audit;

                if (($audit['action'] ?? '') !== NostroEntryAudit::ACTION_RESTORE) {
                    continue;
                }

                $oldValues = !empty($audit['old_values']) ? json_decode($audit['old_values'], true) : null;
                if (is_array($oldValues) && !empty($oldValues['original_id'])) {
                    $restoredEntryIds[$currentEntryId] = true;
                    $queue[] = (int)$oldValues['original_id'];
                }
            }
        }

        $audits = array_values($auditsById);
        if (!empty($restoredEntryIds)) {
            $audits = array_values(array_filter($audits, function ($audit) use ($restoredEntryIds) {
                $auditEntryId = (int)($audit['entry_id'] ?? 0);
                return !(($audit['action'] ?? '') === NostroEntryAudit::ACTION_CREATE
                    && isset($restoredEntryIds[$auditEntryId]));
            }));
        }

        usort($audits, function ($a, $b) {
            $dateCmp = strcmp((string)$a['created_at'], (string)$b['created_at']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });

        return $audits;
    }

    /**
     * Загружает события аудита для одного ID записи.
     *
     * Учитывает события с прямым `entry_id` и технические события, где ID
     * находится внутри JSON `old_values/new_values`.
     *
     * @param int $entryId ID записи.
     * @return array События аудита от старых к новым.
     */
    private function findAuditsByEntryId(int $entryId): array
    {
        return Yii::$app->db->createCommand(
            "SELECT *
               FROM {{%nostro_entry_audit}}
              WHERE entry_id = :entry_id
                 OR (entry_id IS NULL AND (
                        (old_values IS NOT NULL AND old_values::jsonb ->> 'id' = :entry_id_text)
                     OR (new_values IS NOT NULL AND new_values::jsonb ->> 'id' = :entry_id_text)
                 ))
              ORDER BY created_at ASC, id ASC",
            [
                ':entry_id'      => $entryId,
                ':entry_id_text' => (string)$entryId,
            ]
        )->queryAll();
    }
}
