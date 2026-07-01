<?php

namespace yannkost\easyform\jobs;

use Craft;
use craft\queue\BaseJob;
use yannkost\easyform\EasyForm;

/**
 * SendNotificationJob Job
 *
 * @property-read string $description
 */
class SendNotificationJob extends BaseJob
{
    public int $submissionId;
    public ?int $notificationIndex = null;
    public ?string $recipientOverride = null;

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        if ($this->notificationIndex !== null) {
            return Craft::t('easy-form', 'Sending notification #{index} for submission #{id}', [
                'index' => $this->notificationIndex + 1,
                'id' => $this->submissionId
            ]);
        }
        return Craft::t('easy-form', 'Sending notifications for submission #{id}', ['id' => $this->submissionId]);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        try {
            $submission = EasyForm::getInstance()->submissions->getSubmissionById($this->submissionId);

            if (!$submission) {
                EasyForm::log("Submission #{$this->submissionId} not found in queue job.", 'warning');
                return;
            }

            if ($this->notificationIndex !== null) {
                EasyForm::debug("Job processing single notification. Index: {$this->notificationIndex}, Override: " . ($this->recipientOverride ?: 'NULL'));
                $success = EasyForm::getInstance()->notifications->sendSingleNotification(
                    $submission,
                    $this->notificationIndex,
                    $this->recipientOverride
                );
            } else {
                $success = EasyForm::getInstance()->notifications->sendSubmissionNotification($submission);
            }

            if (!$success) {
                // Genuine send failure — log and rethrow so the queue retries.
                EasyForm::log("Failed to send notifications for submission #{$this->submissionId}", 'error');
                throw new \Exception("Failed to send notifications for submission #{$this->submissionId}");
            }
        } catch (\Throwable $e) {
            // Log unexpected errors to the plugin log, then rethrow for the queue.
            if (!str_contains($e->getMessage(), 'Failed to send notifications')) {
                EasyForm::log('Notification job error for submission #' . $this->submissionId . ': ' . $e->getMessage(), 'error');
                EasyForm::debug($e->getTraceAsString());
            }
            throw $e;
        }
    }
}
