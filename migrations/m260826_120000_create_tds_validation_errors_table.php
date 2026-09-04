<?php

use yii\db\Migration;

/**
 * Журнал ошибок проверок TDS-выписок, которые не были загружены в баланс и выверку.
 */
class m260826_120000_create_tds_validation_errors_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%tds_validation_errors}}', [
            'id' => $this->primaryKey(),
            'company_id' => $this->integer()->notNull(),
            'batch_id' => $this->integer()->notNull()->comment('ID строки tds_status'),
            'statement_type' => $this->string(10)->notNull(),
            'error_code' => $this->string(50)->notNull(),
            'statement_date' => $this->date()->null(),
            'statement_time' => $this->time()->null(),
            'account_no' => $this->string(35)->notNull(),
            'statement_number' => $this->string(35)->null(),
            'error_message' => $this->text()->notNull(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx_tds_validation_errors_company_id', '{{%tds_validation_errors}}', 'company_id');
        $this->createIndex('idx_tds_validation_errors_batch_id', '{{%tds_validation_errors}}', 'batch_id');
        $this->createIndex('idx_tds_validation_errors_type', '{{%tds_validation_errors}}', 'statement_type');
        $this->createIndex('idx_tds_validation_errors_code', '{{%tds_validation_errors}}', 'error_code');
        $this->createIndex('idx_tds_validation_errors_stmt_date', '{{%tds_validation_errors}}', 'statement_date');
        $this->createIndex(
            'idx_tds_validation_errors_lookup',
            '{{%tds_validation_errors}}',
            ['company_id', 'batch_id', 'statement_type', 'account_no', 'statement_number']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('idx_tds_validation_errors_lookup', '{{%tds_validation_errors}}');
        $this->dropIndex('idx_tds_validation_errors_stmt_date', '{{%tds_validation_errors}}');
        $this->dropIndex('idx_tds_validation_errors_code', '{{%tds_validation_errors}}');
        $this->dropIndex('idx_tds_validation_errors_type', '{{%tds_validation_errors}}');
        $this->dropIndex('idx_tds_validation_errors_batch_id', '{{%tds_validation_errors}}');
        $this->dropIndex('idx_tds_validation_errors_company_id', '{{%tds_validation_errors}}');
        $this->dropTable('{{%tds_validation_errors}}');
    }
}
