<?php

namespace yannkost\easyform\console\controllers;

use craft\console\Controller;
use craft\helpers\Json;
use yannkost\easyform\EasyForm;
use yii\console\ExitCode;

/**
 * GDPR / privacy operations from the command line.
 */
class PrivacyController extends Controller
{
    /**
     * @var string|null Email address to act on.
     */
    public ?string $email = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['email']);
    }

    /**
     * Print a JSON export of all submissions for an email.
     *
     * Usage: php craft easy-form/privacy/export --email=person@example.com
     */
    public function actionExport(): int
    {
        if (empty($this->email)) {
            $this->stderr("Provide --email=<address>.\n");
            return ExitCode::USAGE;
        }

        $data = EasyForm::getInstance()->submissions->exportDataForEmail($this->email);
        $this->stdout(Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return ExitCode::OK;
    }

    /**
     * Erase all submissions for an email (right to be forgotten).
     *
     * Usage: php craft easy-form/privacy/forget --email=person@example.com
     */
    public function actionForget(): int
    {
        if (empty($this->email)) {
            $this->stderr("Provide --email=<address>.\n");
            return ExitCode::USAGE;
        }

        if (!$this->confirm("Permanently delete ALL submissions for {$this->email}?")) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }

        $count = EasyForm::getInstance()->submissions->deleteSubmissionsForEmail($this->email);
        $this->stdout("Erased {$count} submission(s).\n");
        return ExitCode::OK;
    }
}
