<?php

use yii\db\Migration;

/**
 * Добавляет валютную размерность дробной части и расширяет денежные поля до 5 знаков.
 */
class m261030_120000_add_fractional_digit_to_currencies extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%currencies}}', 'fractional_digit',
            $this->smallInteger()->notNull()->defaultValue(2)
                ->comment('Количество знаков дробной части суммы (0–5)'));
        $currenciesTable = $this->db->quoteTableName($this->db->tablePrefix . 'currencies');
        $this->execute("ALTER TABLE {$currenciesTable} ADD CONSTRAINT chk_currencies_fractional_digit CHECK (fractional_digit BETWEEN 0 AND 5)");
        $this->update('{{%currencies}}', ['fractional_digit' => 0], ['code' => 'JPY']);

        // Ёмкость БД: до 21 цифры целой части + 5 дробных знаков.
        // Прикладной лимит пользовательского ввода остаётся равен 18 цифрам целой части.
        $this->alterColumn('{{%nostro_entries}}', 'amount', $this->decimal(26, 5)->notNull()->comment('Сумма'));
        $this->alterColumn('{{%nostro_entries_archive}}', 'amount', $this->decimal(26, 5)->notNull());
        $this->alterColumn('{{%nostro_balance}}', 'opening_balance', $this->decimal(26, 5)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance}}', 'closing_balance', $this->decimal(26, 5)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance_archive}}', 'opening_balance', $this->decimal(26, 5)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance_archive}}', 'closing_balance', $this->decimal(26, 5)->notNull()->defaultValue(0));
    }

    public function safeDown()
    {
        $this->alterColumn('{{%nostro_balance_archive}}', 'closing_balance', $this->decimal(20, 2)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance_archive}}', 'opening_balance', $this->decimal(20, 2)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance}}', 'closing_balance', $this->decimal(20, 2)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance}}', 'opening_balance', $this->decimal(20, 2)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_entries_archive}}', 'amount', $this->decimal(20, 2)->notNull());
        $this->alterColumn('{{%nostro_entries}}', 'amount', $this->decimal(20, 2)->notNull()->comment('Сумма'));
        $currenciesTable = $this->db->quoteTableName($this->db->tablePrefix . 'currencies');
        $this->execute("ALTER TABLE {$currenciesTable} DROP CONSTRAINT chk_currencies_fractional_digit");
        $this->dropColumn('{{%currencies}}', 'fractional_digit');
    }
}
