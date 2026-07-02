<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Stores why a submission was flagged as spam (honeypot, blocked email/keyword,
 * CAPTCHA) so the reason can be shown in the control panel.
 */
class m260704_000000_submission_spam_reason extends Migration
{
    private const SUBMISSIONS = '{{%easyform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::SUBMISSIONS, 'spamReason')) {
            $this->addColumn(self::SUBMISSIONS, 'spamReason', $this->string(64)->after('honeypotValue'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::SUBMISSIONS, 'spamReason')) {
            $this->dropColumn(self::SUBMISSIONS, 'spamReason');
        }
        return true;
    }
}
