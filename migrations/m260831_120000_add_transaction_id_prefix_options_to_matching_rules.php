<?php

use yii\db\Migration;

class m260831_120000_add_transaction_id_prefix_options_to_matching_rules extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%matching_rules}}', 'id_prefix_match', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%matching_rules}}', 'id_prefix_length', $this->smallInteger()->null());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%matching_rules}}', 'id_prefix_length');
        $this->dropColumn('{{%matching_rules}}', 'id_prefix_match');
    }
}
