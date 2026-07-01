<?php

namespace yannkost\easyform\console\controllers;

use craft\console\Controller;
use yannkost\easyform\EasyForm;
use yii\console\ExitCode;

/**
 * Manages Easy Form submissions from the command line.
 */
class SubmissionsController extends Controller
{
    /**
     * @var int|null Override the configured retention period (in days).
     */
    public ?int $days = null;

    /**
     * @var int|null Limit pruning to a single form id.
     */
    public ?int $formId = null;

    /**
     * @var bool Show what would be deleted without deleting.
     */
    public bool $dryRun = false;

    /**
     * @var string|null Form handle (alternative to --formId) for reindex.
     */
    public ?string $handle = null;

    /**
     * @var bool Reindex every form (reindex action).
     */
    public bool $all = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'prune') {
            $options[] = 'days';
            $options[] = 'formId';
            $options[] = 'dryRun';
        }
        if ($actionID === 'reindex') {
            $options[] = 'formId';
            $options[] = 'handle';
            $options[] = 'all';
        }
        return $options;
    }

    /**
     * Deletes submissions older than the configured retention period.
     *
     * Usage:
     *   php craft easy-form/submissions/prune
     *   php craft easy-form/submissions/prune --days=90
     *   php craft easy-form/submissions/prune --formId=3 --dry-run
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? EasyForm::getInstance()->getSettings()->submissionRetentionDays;

        if (empty($days) || $days < 1) {
            $this->stdout("No retention period configured (submissionRetentionDays). Nothing to do.\n");
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $cutoff = (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
            $query = EasyForm::getInstance()->submissions
                ->getSubmissionRowsQuery($this->formId)
                ->andWhere(['<', 'dateCreated', $cutoff->format('Y-m-d H:i:s')]);
            $count = (int) $query->count();
            $this->stdout("[dry run] {$count} submission(s) older than {$days} days would be deleted.\n");
            return ExitCode::OK;
        }

        $deleted = EasyForm::getInstance()->submissions->pruneOldSubmissions((int) $days, $this->formId);

        $this->stdout("Pruned {$deleted} submission(s) older than {$days} days.\n");
        EasyForm::log("Pruned {$deleted} submissions older than {$days} days", 'warning');

        return ExitCode::OK;
    }

    /**
     * Recomputes promoted/search columns (primaryEmail, searchCol1..3) for the
     * existing submissions of a form. Promotion otherwise only applies to new
     * submissions, so run this once after promoting a field.
     *
     * Usage:
     *   php craft easy-form/submissions/reindex --formId=3
     *   php craft easy-form/submissions/reindex --handle=contact
     *   php craft easy-form/submissions/reindex --all
     */
    public function actionReindex(): int
    {
        $ef = EasyForm::getInstance();

        if ($this->all) {
            $forms = $ef->forms->getAllForms();
        } elseif ($this->handle !== null) {
            $form = $ef->forms->getFormByHandle($this->handle);
            $forms = $form ? [$form] : [];
        } elseif ($this->formId !== null) {
            $form = $ef->forms->getFormById($this->formId);
            $forms = $form ? [$form] : [];
        } else {
            $this->stderr("Specify --formId, --handle, or --all.\n");
            return ExitCode::USAGE;
        }

        if (!$forms) {
            $this->stderr("No matching form found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $total = 0;
        foreach ($forms as $form) {
            $count = $ef->submissions->reindexForm($form);
            $total += $count;
            $this->stdout("Re-indexed {$count} submission(s) for form '{$form->handle}'.\n");
        }

        EasyForm::log("Re-indexed {$total} submissions across " . count($forms) . " form(s)", 'info');
        return ExitCode::OK;
    }
}
