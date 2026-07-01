<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds a per-form webhook: a destination URL and a payload mode
 * ('full' = wrapped {values,frontend,meta}, 'data' = flat field values).
 */
class m260601_010000_form_webhooks extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'webhookUrl')) {
            $this->addColumn(self::FORMS, 'webhookUrl', $this->string(500)->after('showStepIndicator'));
        }
        if (!$this->db->columnExists(self::FORMS, 'webhookPayload')) {
            $this->addColumn(self::FORMS, 'webhookPayload', $this->string(16)->defaultValue('full')->notNull()->after('webhookUrl'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'webhookPayload')) {
            $this->dropColumn(self::FORMS, 'webhookPayload');
        }
        if ($this->db->columnExists(self::FORMS, 'webhookUrl')) {
            $this->dropColumn(self::FORMS, 'webhookUrl');
        }
        return true;
    }
}
