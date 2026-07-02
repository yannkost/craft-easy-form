<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds reCAPTCHA v3 score support: a per-form score-threshold override on forms,
 * and the resolved score stored on each submission (for logging/inspection).
 */
class m260702_000000_captcha_score extends Migration
{
    private const FORMS = '{{%easyform_forms}}';
    private const SUBMISSIONS = '{{%easyform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'captchaScoreThreshold')) {
            $this->addColumn(self::FORMS, 'captchaScoreThreshold', $this->decimal(3, 2)->after('captchaProvider'));
        }
        if (!$this->db->columnExists(self::SUBMISSIONS, 'captchaScore')) {
            $this->addColumn(self::SUBMISSIONS, 'captchaScore', $this->decimal(3, 2)->after('spamScore'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS, 'captchaScoreThreshold')) {
            $this->dropColumn(self::FORMS, 'captchaScoreThreshold');
        }
        if ($this->db->columnExists(self::SUBMISSIONS, 'captchaScore')) {
            $this->dropColumn(self::SUBMISSIONS, 'captchaScore');
        }
        return true;
    }
}
