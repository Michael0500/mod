<?php

namespace app\commands\concerns;

use Yii;

/**
 * Взаимоисключение обработки пачки `tds_status` между ручным и фоновым процессами.
 *
 * Используется merge-командами (`FccMergeController` / `TdsMergeController` /
 * `DwhMergeController`). Блокировка — построчная: захват атомарным
 * `UPDATE ... WHERE is_processing = FALSE`. Если строку уже захватил другой
 * процесс — обработка пропускается. Таймаута нет (по требованию): зависшую
 * блокировку снимают вручную.
 *
 * Захват/снятие выполняются вне транзакции merge, чтобы флаг был сразу виден
 * другим соединениям.
 *
 * Использующий класс должен иметь публичное свойство `bool $quiet` для
 * подавления консольного вывода при вызове из web-контекста.
 */
trait ImportProcessingLock
{
    /**
     * Пытается атомарно захватить строку tds_status под обработку.
     *
     * @param int $statusId ID строки tds_status.
     * @param string $owner Кто захватывает: 'manual' или 'background'.
     * @return bool true — захвачено; false — строка уже обрабатывается.
     */
    protected function acquireProcessingLock(int $statusId, string $owner): bool
    {
        $affected = Yii::$app->db->createCommand(
            "UPDATE {{%tds_status}}
                SET is_processing = TRUE,
                    processing_started_at = NOW(),
                    processing_owner = :owner
              WHERE id = :id
                AND is_processing = FALSE AND is_rolled_back = FALSE",
            [':id' => $statusId, ':owner' => $owner]
        )->execute();

        return $affected > 0;
    }

    /** @var array<int, bool> Исключённые счета текущего запуска. */
    private $rolledBackAccountIds = [];

    /** Исключения действуют только внутри текущей пачки и компании. */
    protected function loadRolledBackAccounts(int $batchId, int $companyId): void
    {
        $ids = Yii::$app->db->createCommand(
            'SELECT account_id FROM {{%import_account_rollbacks}} WHERE batch_id = :bid AND company_id = :cid',
            [':bid' => $batchId, ':cid' => $companyId]
        )->queryColumn();
        $this->rolledBackAccountIds = array_fill_keys($ids, true);
    }

    protected function isAccountRolledBack(int $accountId): bool
    {
        return isset($this->rolledBackAccountIds[$accountId]);
    }

    /** Снимает блокировку обработки строки tds_status. */
    protected function releaseProcessingLock(int $statusId): void
    {
        Yii::$app->db->createCommand(
            "UPDATE {{%tds_status}}
                SET is_processing = FALSE,
                    processing_owner = NULL
              WHERE id = :id",
            [':id' => $statusId]
        )->execute();
    }

    /**
     * Консольный вывод с учётом флага `$quiet` (тихий режим при вызове из web).
     *
     * @param string $string Текст.
     * @param mixed ...$args Цвета/форматы для Console::ansiFormat.
     * @return void
     */
    protected function out(string $string, ...$args): void
    {
        if (!empty($this->quiet)) {
            return;
        }
        $this->stdout($string, ...$args);
    }
}
