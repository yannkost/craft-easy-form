<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds two per-form behavior flags:
 *  - allowUrlPrefill: pre-fill fields from URL query params on the front end.
 *  - showStepIndicator: render a step indicator on multi-page forms.
 */
class m260601_000000_form_behavior_flags extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'allowUrlPrefill')) {
            $this->addColumn(self::FORMS, 'allowUrlPrefill', $this->boolean()->defaultValue(false)->notNull()->after('saveSpamSubmissions'));
        }
        if (!$this->db->columnExists(self::FORMS, 'showStepIndicator')) {
            $this->addColumn(self::FORMS, 'showStepIndicator', $this->boolean()->defaultValue(false)->notNull()->after('allowUrlPrefill'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'showStepIndicator')) {
            $this->dropColumn(self::FORMS, 'showStepIndicator');
        }
        if ($this->db->columnExists(self::FORMS, 'allowUrlPrefill')) {
            $this->dropColumn(self::FORMS, 'allowUrlPrefill');
        }
        return true;
    }
}
