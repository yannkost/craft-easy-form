<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds the per-form `rejectOnCaptchaFail` toggle. When off (default), a failed
 * CAPTCHA is filed silently as spam instead of hard-rejecting the submission.
 */
class m260703_000000_reject_on_captcha_fail extends Migration
{
    private const FORMS = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'rejectOnCaptchaFail')) {
            $this->addColumn(
                self::FORMS,
                'rejectOnCaptchaFail',
                $this->boolean()->defaultValue(false)->notNull()->after('captchaScoreThreshold')
            );
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'rejectOnCaptchaFail')) {
            $this->dropColumn(self::FORMS, 'rejectOnCaptchaFail');
        }
        return true;
    }
}
