<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Per-form toggle: when enabled, new non-spam submissions are saved with the
 * 'approved' status instead of 'pending', for forms that don't need moderation.
 * Defaults to true — most forms are plain submissions, not a moderation queue.
 */
class m260627_000000_form_auto_approve extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'autoApprove')) {
            $this->addColumn(self::FORMS, 'autoApprove', $this->boolean()->defaultValue(true)->notNull()->after('saveSpamSubmissions'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'autoApprove')) {
            $this->dropColumn(self::FORMS, 'autoApprove');
        }
        return true;
    }
}
