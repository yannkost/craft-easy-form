<?php

namespace yannkost\easyform\jobs;

use Craft;
use craft\queue\BaseJob;
use yannkost\easyform\EasyForm;

/**
 * Recomputes the promoted/search columns (primaryEmail, searchCol1..3) for an
 * existing form's submissions. Queued by the "Re-index" settings button so the
 * pass runs off the request cycle regardless of submission count.
 *
 * @property-read string $description
 */
class ReindexSubmissions extends BaseJob
{
    public int $formId;

    protected function defaultDescription(): string
    {
        return Craft::t('easy-form', 'Re-indexing submissions for form #{id}', ['id' => $this->formId]);
    }

    public function execute($queue): void
    {
        try {
            $form = EasyForm::getInstance()->forms->getFormById($this->formId);
            if (!$form) {
                return;
            }

            EasyForm::getInstance()->submissions->reindexForm($form);
        } catch (\Throwable $e) {
            EasyForm::log('Re-index job failed for form #' . $this->formId . ': ' . $e->getMessage(), 'error');
        }
    }
}
