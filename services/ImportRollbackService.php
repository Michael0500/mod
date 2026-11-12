<?php

namespace app\services;

use Yii;

/** Откат пачки или одного счёта с сохранением истории операции. */
class ImportRollbackService
{
    public function canRollback(array $batch, ?int $accountId = null): array
    {
        $params = [':bid' => (int)$batch['id'], ':cid' => $this->companyId($batch)];
        $sql = $this->accountsSql();
        if ($accountId !== null) {
            $sql = "SELECT * FROM ($sql) a WHERE account_id = :aid";
            $params[':aid'] = $accountId;
        }
        $counts = Yii::$app->db->createCommand("SELECT
            COALESCE(SUM(live_entries), 0) AS live_entries, COALESCE(SUM(live_balances), 0) AS live_balances,
            COALESCE(SUM(matched), 0) AS matched, COALESCE(SUM(archived), 0) AS archived,
            COALESCE(SUM(unknown_archived_balances), 0) AS unknown_archived_balances
            FROM ($sql) a", $params)->queryOne();
        return $this->availability($batch, $counts);
    }

    /** Откатанный счёт остаётся в списке вместе с датой, пользователем и счётчиками. */
    public function accounts(array $batch, string $search = '', int $page = 1, int $pageSize = 25): array
    {
        $batch['automatch_running'] = (bool)Yii::$app->db->createCommand(
            "SELECT 1 FROM {{%automatch_jobs}} WHERE company_id = :cid AND status = 'running' LIMIT 1",
            [':cid' => $this->companyId($batch)]
        )->queryScalar();
        $pageSize = max(1, min(100, $pageSize));
        $params = [':bid' => (int)$batch['id'], ':cid' => $this->companyId($batch)];
        $sql = 'SELECT * FROM (' . $this->accountsSql() . ') a';
        if ($search !== '') {
            $sql .= ' WHERE POSITION(LOWER(:q) IN LOWER(account_name)) > 0';
            $params[':q'] = $search;
        }
        $db = Yii::$app->db;
        $total = (int)$db->createCommand("SELECT COUNT(*) FROM ($sql) f", $params)->queryScalar();
        $pageCount = max(1, (int)ceil($total / $pageSize));
        $page = max(1, min($page, $pageCount));
        $rows = $db->createCommand($sql . ' ORDER BY account_name, account_id LIMIT :lim OFFSET :off', $params + [
            ':lim' => $pageSize, ':off' => ($page - 1) * $pageSize,
        ])->queryAll();
        foreach ($rows as &$row) {
            foreach (['account_id', 'live_entries', 'live_balances', 'matched', 'archived',
                'unknown_archived_balances', 'deleted_entries', 'deleted_balances'] as $key) $row[$key] = (int)$row[$key];
            $check = $this->availability($batch, $row);
            $row['can_rollback'] = !$row['rolled_back_at'] && $check['ok'];
            $row['reason'] = $row['rolled_back_at'] ? 'Данные счёта уже откатаны' : $check['reason'];
        }
        unset($row);
        return ['data' => $rows, 'pagination' => [
            'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'page_count' => $pageCount,
        ]];
    }

    public function rollback(int $batchId, int $userId, ?int $companyId = null): array
    {
        return $this->rollbackScope($batchId, null, $userId, $companyId);
    }

    public function rollbackAccount(int $batchId, int $accountId, int $userId, int $companyId): array
    {
        return $this->rollbackScope($batchId, $accountId, $userId, $companyId);
    }

    private function rollbackScope(int $batchId, ?int $accountId, int $userId, ?int $companyId): array
    {
        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            ImportRollbackGuard::exclusive();
            $batch = $db->createCommand('SELECT * FROM {{%tds_status}} WHERE id = :id FOR UPDATE', [':id' => $batchId])->queryOne();
            if (!$batch || ($companyId !== null && $this->companyId($batch) !== $companyId)) throw new \DomainException('Пачка не найдена');
            $companyId = $this->companyId($batch);
            $params = [':bid' => $batchId, ':cid' => $companyId];
            $scope = 'batch_id = :bid AND company_id = :cid';
            if ($accountId !== null) {
                if ($accountId <= 0) throw new \DomainException('Не указан счёт');
                $scope .= ' AND account_id = :aid';
                $params[':aid'] = $accountId;
                if ($db->createCommand('SELECT 1 FROM {{%import_account_rollbacks}} WHERE ' . $scope, $params)->queryScalar()) {
                    throw new \DomainException('Данные счёта уже откатаны');
                }
            }
            // Пары автоквитования сохраняются между запросами; их нельзя оставлять без записей.
            if ($db->createCommand("SELECT 1 FROM {{%automatch_jobs}} WHERE company_id = :cid AND status = 'running' LIMIT 1", [':cid' => $companyId])->queryScalar()) {
                throw new \DomainException('Дождитесь завершения автоквитования компании');
            }
            foreach (['nostro_entries', 'nostro_balance'] as $table) {
                $db->createCommand('SELECT id FROM {{%' . $table . '}} WHERE ' . $scope . ' ORDER BY id FOR UPDATE', $params)->queryColumn();
            }
            // Повторная проверка под блокировками учитывает изменения после открытия окна.
            $check = $this->canRollback($batch, $accountId);
            if (!$check['ok']) throw new \DomainException('Откат недоступен: ' . $check['reason']);
            $sql = $this->accountsSql();
            if ($accountId !== null) $sql = "SELECT * FROM ($sql) a WHERE account_id = :aid";
            $accounts = $db->createCommand($sql, $params)->queryAll();
            $now = date('Y-m-d H:i:s');
            foreach ($accounts as $account) {
                if (!(int)$account['live_entries'] && !(int)$account['live_balances']) continue;
                $db->createCommand()->insert('{{%import_account_rollbacks}}', [
                    'company_id' => $companyId, 'batch_id' => $batchId, 'account_id' => (int)$account['account_id'],
                    'account_name' => $account['account_name'], 'deleted_entries' => (int)$account['live_entries'],
                    'deleted_balances' => (int)$account['live_balances'], 'rolled_back_by' => $userId, 'rolled_back_at' => $now,
                ])->execute();
            }
            $deleted = [];
            foreach (['nostro_entries' => ['nostro_entry_audit', 'entry_id'], 'nostro_balance' => ['nostro_balance_audit', 'balance_id']] as $table => $audit) {
                $db->createCommand('DELETE FROM {{%' . $audit[0] . '}} WHERE ' . $audit[1]
                    . ' IN (SELECT id FROM {{%' . $table . '}} WHERE ' . $scope . ')', $params)->execute();
                $deleted[$table] = $db->createCommand('DELETE FROM {{%' . $table . '}} WHERE ' . $scope, $params)->execute();
            }
            $remaining = $db->createCommand('SELECT 1 FROM (' . $this->accountsSql()
                . ') a WHERE live_entries + live_balances + archived + unknown_archived_balances > 0 LIMIT 1', [':bid' => $batchId, ':cid' => $companyId])->queryScalar();
            if (!$remaining) {
                $db->createCommand()->update('{{%tds_status}}', [
                    'is_rolled_back' => true, 'rolled_back_at' => $now, 'rolled_back_by' => $userId,
                ], ['id' => $batchId])->execute();
            }
            $tx->commit();
            return ['success' => true, 'message' => ($accountId === null ? 'Пачка откатана' : 'Данные счёта откатаны')
                . ': удалено записей ' . $deleted['nostro_entries'] . ', балансов ' . $deleted['nostro_balance'],
                'deleted_entries' => $deleted['nostro_entries'], 'deleted_balances' => $deleted['nostro_balance']];
        } catch (\Throwable $e) {
            $tx->rollBack();
            if ($e instanceof \DomainException) return ['success' => false, 'message' => $e->getMessage()];
            Yii::error('Ошибка отката импорта: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'message' => 'Ошибка отката. Данные не изменены.'];
        }
    }

    private function availability(array $batch, array $counts): array
    {
        $reason = '';
        if (!empty($batch['is_rolled_back'])) $reason = 'Пачка откатана';
        elseif (!empty($batch['is_processing'])) $reason = 'Пачка обрабатывается';
        elseif (!empty($batch['automatch_running'])) $reason = 'Дождитесь завершения автоквитования компании';
        elseif ((int)$counts['matched']) $reason = 'Есть сквитованные записи';
        elseif ((int)$counts['archived']) $reason = 'Часть данных уже в архиве';
        elseif ((int)$counts['unknown_archived_balances']) $reason = 'Есть архивные балансы счёта без связи с пачкой; безопасный откат недоступен';
        elseif (!(int)$counts['live_entries'] && !(int)$counts['live_balances']) $reason = 'Нет данных для отката';
        return ['ok' => $reason === '', 'reason' => $reason];
    }

    private function companyId(array $batch): int
    {
        return isset($batch['company_id']) ? (int)$batch['company_id']
            : (in_array($batch['type'], ['DWH', 'SUSPENSE_POSTING'], true) ? 2 : 1);
    }

    /** Все части запроса ограничены компанией и пачкой. */
    private function accountsSql(): string
    {
        return "WITH raw AS (
            SELECT account_id, COUNT(*) AS live_entries, 0::bigint AS live_balances,
                   COUNT(*) FILTER (WHERE match_id IS NOT NULL OR match_status IN ('M', 'I')) AS matched, 0::bigint AS archived
              FROM {{%nostro_entries}} WHERE company_id = :cid AND batch_id = :bid GROUP BY account_id
            UNION ALL SELECT account_id, 0, COUNT(*), 0, 0 FROM {{%nostro_balance}}
              WHERE company_id = :cid AND batch_id = :bid GROUP BY account_id
            UNION ALL SELECT account_id, 0, 0, 0, COUNT(*) FROM {{%nostro_entries_archive}}
              WHERE company_id = :cid AND batch_id = :bid GROUP BY account_id
            UNION ALL SELECT account_id, 0, 0, 0, COUNT(*) FROM {{%nostro_balance_archive}}
              WHERE company_id = :cid AND batch_id = :bid GROUP BY account_id
            UNION ALL SELECT account_id, 0, 0, 0, 0 FROM {{%import_account_rollbacks}}
              WHERE company_id = :cid AND batch_id = :bid
        ), stats AS (
            SELECT account_id, SUM(live_entries) AS live_entries, SUM(live_balances) AS live_balances,
                   SUM(matched) AS matched, SUM(archived) AS archived FROM raw GROUP BY account_id
        )
        SELECT s.*, COALESCE(r.account_name, a.name, '#' || s.account_id::text) AS account_name,
               r.rolled_back_at, r.rolled_back_by, COALESCE(u.username, r.rolled_back_by::text) AS rolled_back_user,
               r.deleted_entries, r.deleted_balances,
               (SELECT COUNT(*) FROM {{%nostro_balance_archive}} old
                 WHERE old.company_id = :cid AND old.account_id = s.account_id AND old.batch_id IS NULL) AS unknown_archived_balances
          FROM stats s
          LEFT JOIN {{%accounts}} a ON a.id = s.account_id AND a.company_id = :cid
          LEFT JOIN {{%import_account_rollbacks}} r ON r.account_id = s.account_id AND r.company_id = :cid AND r.batch_id = :bid
          LEFT JOIN {{%user}} u ON u.id = r.rolled_back_by AND u.company_id = :cid";
    }
}
