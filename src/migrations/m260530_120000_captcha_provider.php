<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Replaces the unused boolean `captchaEnabled` flag with a per-form
 * `captchaProvider` handle, so each form can choose which configured CAPTCHA
 * provider to use (or none).
 */
class m260530_120000_captcha_provider extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'captchaProvider')) {
            $this->addColumn(self::FORMS, 'captchaProvider', $this->string(64)->after('captchaEnabled'));
        }
        if ($this->db->columnExists(self::FORMS, 'captchaEnabled')) {
            $this->dropColumn(self::FORMS, 'captchaEnabled');
        }
        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'captchaEnabled')) {
            $this->addColumn(self::FORMS, 'captchaEnabled', $this->boolean()->defaultValue(false)->notNull());
        }
        if ($this->db->columnExists(self::FORMS, 'captchaProvider')) {
            $this->dropColumn(self::FORMS, 'captchaProvider');
        }
        return true;
    }
}
