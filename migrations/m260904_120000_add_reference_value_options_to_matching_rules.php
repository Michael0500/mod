<?php

use yii\db\Migration;

class m260904_120000_add_reference_value_options_to_matching_rules extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%matching_rules}}', 'reference_value', $this->string(60)->null());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%matching_rules}}', 'reference_value');
    }
}
