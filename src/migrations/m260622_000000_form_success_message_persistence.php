<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds control over how long the success message stays after submission:
 * `keepSuccessMessage` keeps it until the page reloads (default), otherwise it
 * auto-hides after `successMessageDuration` seconds.
 */
class m260622_000000_form_success_message_persistence extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'keepSuccessMessage')) {
            $this->addColumn(self::FORMS, 'keepSuccessMessage', $this->boolean()->defaultValue(true)->notNull()->after('hideFormOnSuccess'));
        }
        if (!$this->db->columnExists(self::FORMS, 'successMessageDuration')) {
            $this->addColumn(self::FORMS, 'successMessageDuration', $this->integer()->defaultValue(5)->notNull()->after('keepSuccessMessage'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'successMessageDuration')) {
            $this->dropColumn(self::FORMS, 'successMessageDuration');
        }
        if ($this->db->columnExists(self::FORMS, 'keepSuccessMessage')) {
            $this->dropColumn(self::FORMS, 'keepSuccessMessage');
        }
        return true;
    }
}
