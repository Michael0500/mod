<?php

namespace app\services;

use Yii;

/** Синхронизация отката с квитованием и переносом в архив. */
final class ImportRollbackGuard
{
    /** Общая блокировка допускает параллельное квитование и архивирование. */
    public static function shared(callable $operation)
    {
        $db = Yii::$app->db;
        $db->createCommand('SELECT pg_advisory_lock_shared(1397576269, 1)')->execute();
        try {
            return $operation();
        } finally {
            $db->createCommand('SELECT pg_advisory_unlock_shared(1397576269, 1)')->execute();
        }
    }

    /** Вызывается внутри транзакции отката; освобождается при её завершении. */
    public static function exclusive(): void
    {
        Yii::$app->db->createCommand('SELECT pg_advisory_xact_lock(1397576269, 1)')->execute();
    }
}
