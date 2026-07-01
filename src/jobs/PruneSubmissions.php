<?php

namespace yannkost\easyform\jobs;

use Craft;
use craft\queue\BaseJob;
use yannkost\easyform\EasyForm;

/**
 * Prunes submissions older than the configured (or given) retention period.
 * Queued by the "Prune now" settings button so large deletes run off-request.
 *
 * @property-read string $description
 */
class PruneSubmissions extends BaseJob
{
    /** @var int|null Override the configured retention period (in days). */
    public ?int $days = null;

    /** @var int|null Limit pruning to a single form id. */
    public ?int $formId = null;

    protected function defaultDescription(): string
    {
        return Craft::t('easy-form', 'Pruning old submissions');
    }

    public function execute($queue): void
    {
        try {
            $days = $this->days ?? EasyForm::getInstance()->getSettings()->submissionRetentionDays;
            if ($days === null || (int) $days < 1) {
                return;
            }

            EasyForm::getInstance()->submissions->pruneOldSubmissions((int) $days, $this->formId);
        } catch (\Throwable $e) {
            EasyForm::log('Prune job failed: ' . $e->getMessage(), 'error');
        }
    }
}
