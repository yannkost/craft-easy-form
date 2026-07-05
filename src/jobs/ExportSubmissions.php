<?php

namespace yannkost\easyform\jobs;

use Craft;
use craft\helpers\FileHelper;
use craft\queue\BaseJob;
use yannkost\easyform\EasyForm;

/**
 * ExportSubmissions Job
 *
 * Builds a submissions CSV off the request cycle and writes it to a token-keyed
 * file in the plugin's export dir. Bookkeeping (status, row count, size) lives
 * on the export's DB row, updated here as the job progresses. All exports are
 * queued (no synchronous path) so behavior is identical and memory-safe
 * regardless of dataset size.
 *
 * @property-read string $description
 */
class ExportSubmissions extends BaseJob
{
    public ?int $formId = null;
    /** Handle of a deleted form, for exporting its orphaned submissions. */
    public ?string $orphanedHandle = null;
    public ?string $status = null;
    /** @var int[]|null Restrict to these site ids (null/empty = all sites). */
    public ?array $siteIds = null;
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public ?string $search = null;
    /** @var string[]|null Selected export column keys (null = default set). */
    public ?array $columns = null;
    /** @var string How file-field cells are rendered: 'path' | 'filename' | 'id'. */
    public string $fileFormat = 'path';
    /** @var bool Format date/time field values in each row's site locale. */
    public bool $localizeDates = false;
    public string $token = '';

    protected function defaultDescription(): string
    {
        return $this->orphanedHandle !== null
            ? Craft::t('easy-form', 'Exporting orphaned submissions for “{handle}”', ['handle' => $this->orphanedHandle])
            : Craft::t('easy-form', 'Exporting submissions for form #{id}', ['id' => $this->formId]);
    }

    public function execute($queue): void
    {
        $exports = EasyForm::getInstance()->exports;
        $submissions = EasyForm::getInstance()->submissions;

        try {
            FileHelper::createDirectory($exports->storageDir());
            $exports->garbageCollect();

            $rowCount = 0;
            if ($this->orphanedHandle !== null && $this->orphanedHandle !== '') {
                $stream = $submissions->buildOrphanedCsvStream(
                    $this->orphanedHandle,
                    $this->status,
                    $this->siteIds,
                    $this->dateFrom,
                    $this->dateTo,
                    $this->search,
                    $this->columns,
                    $rowCount,
                    $this->fileFormat,
                    $this->localizeDates
                );
            } else {
                $form = EasyForm::getInstance()->forms->getFormById((int) $this->formId);
                if (!$form) {
                    $exports->markFailed($this->token, Craft::t('easy-form', 'Form not found.'));
                    return;
                }
                $stream = $submissions->buildCsvStream(
                    $form,
                    $this->status,
                    $this->siteIds,
                    $this->dateFrom,
                    $this->dateTo,
                    $this->search,
                    $this->columns,
                    $rowCount,
                    $this->fileFormat,
                    $this->localizeDates
                );
            }

            $path = $exports->filePath($this->token);
            $out = fopen($path, 'w');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            $exports->markReady($this->token, $rowCount, (int) @filesize($path));
        } catch (\Throwable $e) {
            // Don't rethrow: a retry would rebuild the same broken export. Record
            // the failure on the row so the polling UI can surface it.
            EasyForm::log('Export job failed for form #' . $this->formId . ': ' . $e->getMessage(), 'error');
            EasyForm::debug($e->getTraceAsString());
            $exports->markFailed($this->token, Craft::t('easy-form', 'Could not generate the export.'));
        }
    }
}
