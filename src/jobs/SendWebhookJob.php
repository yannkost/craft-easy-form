<?php

namespace yannkost\easyform\jobs;

use Craft;
use craft\queue\BaseJob;
use yannkost\easyform\EasyForm;

/**
 * Posts a submission to its form's webhook URL (out of the request cycle so a
 * slow/failing endpoint never blocks or breaks the submission).
 */
class SendWebhookJob extends BaseJob
{
    public int $submissionId;

    protected function defaultDescription(): string
    {
        return Craft::t('easy-form', 'Sending webhook for submission #{id}', ['id' => $this->submissionId]);
    }

    public function execute($queue): void
    {
        $submission = EasyForm::getInstance()->submissions->getSubmissionById($this->submissionId);
        if (!$submission) {
            EasyForm::log("Webhook job: submission #{$this->submissionId} not found.", 'warning');
            return;
        }

        $form = EasyForm::getInstance()->forms->getFormById($submission->formId);
        if (!$form) {
            EasyForm::log("Webhook job: form #{$submission->formId} not found.", 'warning');
            return;
        }

        if (!EasyForm::getInstance()->webhooks->send($form, $submission)) {
            // Rethrow so the queue retries a genuine delivery failure.
            throw new \Exception("Webhook delivery failed for submission #{$this->submissionId}");
        }
    }
}
