<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds a per-form anonymous rate limit: at most `rateLimit` submissions per IP
 * within `rateLimitWindow` seconds (0 = disabled).
 */
class m260602_000000_form_rate_limit extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'rateLimit')) {
            $this->addColumn(self::FORMS, 'rateLimit', $this->integer()->defaultValue(0)->notNull()->after('maxSubmissionsPerUser'));
        }
        if (!$this->db->columnExists(self::FORMS, 'rateLimitWindow')) {
            $this->addColumn(self::FORMS, 'rateLimitWindow', $this->integer()->defaultValue(60)->notNull()->after('rateLimit'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'rateLimitWindow')) {
            $this->dropColumn(self::FORMS, 'rateLimitWindow');
        }
        if ($this->db->columnExists(self::FORMS, 'rateLimit')) {
            $this->dropColumn(self::FORMS, 'rateLimit');
        }
        return true;
    }
}
