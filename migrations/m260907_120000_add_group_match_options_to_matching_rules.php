<?php

use yii\db\Migration;

class m260907_120000_add_group_match_options_to_matching_rules extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%matching_rules}}', 'group_match_enabled', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%matching_rules}}', 'group_ls_type', $this->string(2)->null());
        $this->execute('ALTER TABLE {{%automatch_pairs}} ADD COLUMN IF NOT EXISTS entry_ids integer[] NULL');
    }

    public function safeDown(): void
    {
        $this->execute('ALTER TABLE {{%automatch_pairs}} DROP COLUMN IF EXISTS entry_ids');
        $this->dropColumn('{{%matching_rules}}', 'group_ls_type');
        $this->dropColumn('{{%matching_rules}}', 'group_match_enabled');
    }
}
