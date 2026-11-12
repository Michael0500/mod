<?php

use yii\db\Migration;

/** Журнал откатов по счетам и сохранение связи архивного баланса с пачкой. */
class m261124_150000_add_import_account_rollbacks extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%import_account_rollbacks}}', [
            'id' => $this->primaryKey(),
            'company_id' => $this->integer()->notNull(),
            'batch_id' => $this->integer()->notNull(),
            'account_id' => $this->integer()->notNull(),
            'account_name' => $this->string(255)->notNull(),
            'deleted_entries' => $this->integer()->notNull(),
            'deleted_balances' => $this->integer()->notNull(),
            'rolled_back_by' => $this->integer()->notNull(),
            'rolled_back_at' => $this->timestamp()->notNull(),
        ]);
        $this->createIndex('ux_import_account_rollback_scope', '{{%import_account_rollbacks}}', ['company_id', 'batch_id', 'account_id'], true);
        $this->addForeignKey('fk_import_account_rollback_batch', '{{%import_account_rollbacks}}', 'batch_id', '{{%tds_status}}', 'id', 'RESTRICT');

        $this->addColumn('{{%nostro_balance_archive}}', 'batch_id', $this->integer());
        $this->createIndex('idx_balance_archive_batch_account', '{{%nostro_balance_archive}}', ['company_id', 'batch_id', 'account_id']);
        // Восстанавливаем связь только из достоверного снимка самого архивирования.
        // Старые снимки без batch_id оставляем неопределёнными.
        $this->execute("UPDATE {{%nostro_balance_archive}} a SET batch_id = s.batch_id
            FROM (
                SELECT DISTINCT ON (archived_id) archived_id, balance_id,
                       ((CASE WHEN old_values IS JSON OBJECT THEN old_values::jsonb ELSE '{}'::jsonb END)->>'batch_id')::integer AS batch_id,
                       (CASE WHEN old_values IS JSON OBJECT THEN old_values::jsonb ELSE '{}'::jsonb END)->>'company_id' AS company_id
                  FROM {{%nostro_balance_audit}}
                 WHERE action = 'archive' AND (CASE WHEN old_values IS JSON OBJECT THEN old_values::jsonb ELSE '{}'::jsonb END)->>'batch_id' ~ '^[0-9]{1,9}$'
                 ORDER BY archived_id, id DESC
            ) s WHERE a.id = s.archived_id AND a.original_id = s.balance_id AND a.company_id::text = s.company_id");
    }

    public function safeDown()
    {
        $this->dropIndex('idx_balance_archive_batch_account', '{{%nostro_balance_archive}}');
        $this->dropColumn('{{%nostro_balance_archive}}', 'batch_id');
        $this->dropTable('{{%import_account_rollbacks}}');
    }
}
