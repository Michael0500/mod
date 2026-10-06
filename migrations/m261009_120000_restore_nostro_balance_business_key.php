<?php

use yii\db\Migration;

/**
 * Удаляет накопленные дубли балансов и восстанавливает уникальность бизнес-ключа.
 */
class m261009_120000_restore_nostro_balance_business_key extends Migration
{
    /**
     * Для каждого ключа сохраняется самая новая физическая строка баланса.
     * Аудит удаляемых строк переносится на сохранённую строку.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->execute(<<<'SQL'
WITH duplicates AS (
    SELECT id,
           FIRST_VALUE(id) OVER (
               PARTITION BY account_id, ls_type, currency, value_date, section, source
               ORDER BY id DESC
           ) AS keeper_id,
           ROW_NUMBER() OVER (
               PARTITION BY account_id, ls_type, currency, value_date, section, source
               ORDER BY id DESC
           ) AS row_number
    FROM {{%nostro_balance}}
), moved_audit AS (
    UPDATE {{%nostro_balance_audit}} audit
       SET balance_id = duplicates.keeper_id
      FROM duplicates
     WHERE duplicates.row_number > 1
       AND audit.balance_id = duplicates.id
    RETURNING audit.id
)
DELETE FROM {{%nostro_balance}} balance
 USING duplicates
 WHERE duplicates.row_number > 1
   AND balance.id = duplicates.id
SQL
        );

        $this->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS "uq_nbalance_entry" '
            . 'ON {{%nostro_balance}} '
            . '(account_id, ls_type, currency, value_date, section, source)'
        );
    }

    /**
     * Откат удаляет ограничение; восстановить удалённые дубли невозможно.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->execute('DROP INDEX IF EXISTS "uq_nbalance_entry"');
    }
}
