<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Per-form toggle: whether each step of a multi-page form is validated before
 * the user may advance. Front-end only — the server always validates the whole
 * submission on final submit. Defaults to true (the prior behavior).
 */
class m260613_000000_form_validate_steps extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'validateSteps')) {
            $this->addColumn(self::FORMS, 'validateSteps', $this->boolean()->defaultValue(true)->notNull()->after('showStepIndicator'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'validateSteps')) {
            $this->dropColumn(self::FORMS, 'validateSteps');
        }
        return true;
    }
}
