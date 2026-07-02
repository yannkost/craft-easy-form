<?php

namespace yannkost\easyform\services;

use Craft;
use craft\helpers\Json;
use yii\base\Component;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Submission;
use yannkost\easyform\records\SubmissionRecord;
use yannkost\easyform\events\SubmissionEvent;

/**
 * Submissions service
 */
class Submissions extends Component
{
    /**
     * @event SubmissionEvent The event that is triggered before a submission is saved
     */
    public const EVENT_BEFORE_SAVE_SUBMISSION = 'beforeSaveSubmission';

    /**
     * @event SubmissionEvent The event that is triggered after a submission is saved
     */
    public const EVENT_AFTER_SAVE_SUBMISSION = 'afterSaveSubmission';

    /**
     * Returns all submissions across all forms
     *
     * @param string|null $status Filter by status
     * @return Submission[]
     */
    public function getAllSubmissions(?string $status = null): array
    {
        $query = $this->getSubmissionsQuery(null, $status);

        $submissions = [];
        foreach ($query->all() as $record) {
            $submissions[] = $this->createSubmissionFromRecord($record);
        }

        return $submissions;
    }

    /**
     * Returns all submissions for a form
     *
     * @param int $formId
     * @param string|null $status Filter by status
     * @return Submission[]
     */
    public function getSubmissionsByFormId(int $formId, ?string $status = null): array
    {
        $query = $this->getSubmissionsQuery($formId, $status);

        $submissions = [];
        foreach ($query->all() as $record) {
            $submissions[] = $this->createSubmissionFromRecord($record);
        }

        return $submissions;
    }

    /**
     * Returns a submission by its ID
     *
     * @param int $id
     * @return Submission|null
     */
    public function getSubmissionById(int $id): ?Submission
    {
        $record = SubmissionRecord::findOne($id);

        if (!$record) {
            return null;
        }

        return $this->createSubmissionFromRecord($record);
    }

    /**
     * Saves a submission
     *
     * @param Submission $submission
     * @param bool $runValidation
     * @return bool
     */
    public function saveSubmission(Submission $submission, bool $runValidation = true): bool
    {
        if ($runValidation && !$submission->validate()) {
            EasyForm::debug('Submission not saved due to validation error: ' . Json::encode($submission->getErrors()));
            return false;
        }

        $isNew = !$submission->id;

        // Trigger beforeSaveSubmission event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_SUBMISSION)) {
            $event = new SubmissionEvent([
                'submission' => $submission,
                'isNew' => $isNew,
            ]);
            $this->trigger(self::EVENT_BEFORE_SAVE_SUBMISSION, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        if (!$isNew) {
            $record = SubmissionRecord::findOne($submission->id);
            if (!$record) {
                throw new \Exception('Invalid submission ID: ' . $submission->id);
            }
        } else {
            $record = new SubmissionRecord();
        }

        // Set attributes
        $record->formId = $submission->formId;
        $record->formHandle = $submission->formHandle;
        $record->formName = $submission->formName;
        $record->siteId = $submission->siteId;
        // Hand arrays to the JSON column directly — Craft/Yii encodes json
        // columns automatically, so pre-encoding here would double-encode.
        $record->data = is_string($submission->data)
            ? (Json::decodeIfJson($submission->data) ?: [])
            : $submission->data;
        $record->fieldSnapshot = is_string($submission->fieldSnapshot)
            ? Json::decodeIfJson($submission->fieldSnapshot)
            : $submission->fieldSnapshot;
        $record->primaryEmail = $submission->primaryEmail;
        $record->searchCol1 = $submission->searchCol1;
        $record->searchCol2 = $submission->searchCol2;
        $record->searchCol3 = $submission->searchCol3;
        $record->userId = $submission->userId;
        $record->ipAddress = $submission->ipAddress;
        $record->userAgent = $submission->userAgent;
        $record->status = $submission->status;
        $record->spamScore = $submission->spamScore;
        $record->captchaScore = $submission->captchaScore;
        $record->honeypotValue = $submission->honeypotValue;
        $record->spamReason = $submission->spamReason;
        $record->isTest = $submission->isTest;

        try {
            if (!$record->save(false)) {
                EasyForm::log('Could not save submission: ' . Json::encode($record->getErrors()), 'error');
                return false;
            }
        } catch (\Throwable $e) {
            EasyForm::log('Exception while saving submission: ' . $e->getMessage(), 'error');
            EasyForm::debug($e->getTraceAsString());
            return false;
        }

        // Update the model with the saved record's data
        $submission->id = $record->id;
        $submission->dateCreated = $record->dateCreated;
        $submission->dateUpdated = $record->dateUpdated;
        $submission->uid = $record->uid;

        // Trigger afterSaveSubmission event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_SUBMISSION)) {
            $event = new SubmissionEvent([
                'submission' => $submission,
                'isNew' => $isNew,
            ]);
            $this->trigger(self::EVENT_AFTER_SAVE_SUBMISSION, $event);
        }

        return true;
    }

    /**
     * Deletes a submission by its ID.
     *
     * Deletion is intentionally permanent (hard delete). The `dateDeleted`
     * column + its `IS NULL` query filters are reserved scaffolding for a
     * possible future soft-delete mode; they are never written today, so every
     * stored row is "live" and counts that omit the filter remain correct.
     *
     * @param int $id
     * @return bool
     */
    public function deleteSubmissionById(int $id): bool
    {
        $record = SubmissionRecord::findOne($id);

        if (!$record) {
            return false;
        }

        // Erase any uploaded files first, so a deleted submission never leaves
        // orphaned uploads on disk / in the asset volume (privacy / GDPR).
        $this->deleteFilesForSubmissionRow($record->data, $record->fieldSnapshot);

        try {
            return (bool) $record->delete();
        } catch (\Throwable $e) {
            EasyForm::log('Could not delete submission #' . $id . ': ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Deletes a submission
     *
     * @param Submission $submission
     * @return bool
     */
    public function deleteSubmission(Submission $submission): bool
    {
        if (!$submission->id) {
            return false;
        }

        return $this->deleteSubmissionById($submission->id);
    }

    /**
     * Deletes all submissions for a form
     *
     * @param int $formId
     * @return int Number of submissions deleted
     */
    public function deleteSubmissionsByFormId(int $formId): int
    {
        $this->cleanupFilesForCondition(['formId' => $formId]);
        return SubmissionRecord::deleteAll(['formId' => $formId]);
    }

    /**
     * Erases the uploaded files (filesystem-mode files and asset-mode Asset
     * elements) attached to a single stored file-field value.
     *
     * Two storage shapes are handled:
     *   - filesystem: `{ storage:'filesystem', files:[{ path, ... }] }` → unlink
     *   - asset:      an Asset id, or an array of ids → delete the Asset(s)
     *
     * Best-effort: failures are logged, never thrown, so file-cleanup trouble
     * never blocks the row deletion it accompanies.
     */
    public function deleteFileValue(mixed $value): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        // Filesystem-mode shape.
        if (is_array($value) && ($value['storage'] ?? '') === 'filesystem') {
            $base = EasyForm::getInstance()->getSettings()->getResolvedFilesystemPath();
            foreach ($value['files'] ?? [] as $f) {
                $rel = $f['path'] ?? '';
                if ($rel === '') {
                    continue;
                }
                $path = $base . '/' . $rel;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            return;
        }

        // Asset-mode: a single id or an array of ids.
        foreach ((is_array($value) ? $value : [$value]) as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            try {
                $asset = \craft\elements\Asset::find()->id((int) $id)->one();
                if ($asset) {
                    Craft::$app->elements->deleteElement($asset, true);
                }
            } catch (\Throwable $e) {
                EasyForm::log('Could not delete upload asset #' . $id . ': ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * Erases every uploaded file referenced by one submission's stored payload.
     *
     * @param array $data     Canonical submission data (`values`/`frontend`/…).
     * @param array $snapshot The submission's `fieldSnapshot`.
     */
    private function deleteFilesForSubmissionData(array $data, array $snapshot): void
    {
        // File-type builder handles, per the submission's own snapshot — not the
        // live form, which may have changed since the file was uploaded.
        $fileHandles = [];
        foreach ($snapshot['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'file' && !empty($field['handle'])) {
                $fileHandles[$field['handle']] = true;
            }
        }

        foreach ($data['values'] ?? [] as $handle => $value) {
            // Filesystem-mode values are self-describing, so clean them even when
            // the snapshot is missing/stale; asset-mode ids are only treated as
            // uploads when the snapshot confirms a file field (a bare number
            // could otherwise be a number field's value).
            $isFilesystem = is_array($value) && ($value['storage'] ?? '') === 'filesystem';
            if ($isFilesystem || isset($fileHandles[$handle])) {
                $this->deleteFileValue($value);
            }
        }
    }

    /**
     * Decodes a raw submission row's JSON columns and erases its uploaded files.
     */
    private function deleteFilesForSubmissionRow(mixed $data, mixed $snapshot): void
    {
        $data = is_string($data) ? Json::decodeIfJson($data) : $data;
        $snapshot = is_string($snapshot) ? Json::decodeIfJson($snapshot) : $snapshot;
        if (is_array($data) && is_array($snapshot)) {
            $this->deleteFilesForSubmissionData($data, $snapshot);
        }
    }

    /**
     * Erase the uploaded files for every stored submission. Used on uninstall
     * (when opted in) to clean up before the submissions table is dropped.
     * Best-effort and batched so a large dataset never loads at once.
     */
    public function deleteAllUploadedFiles(): void
    {
        try {
            $rows = SubmissionRecord::find()
                ->select(['data', 'fieldSnapshot'])
                ->asArray()
                ->each(200);

            foreach ($rows as $row) {
                $this->deleteFilesForSubmissionRow($row['data'] ?? null, $row['fieldSnapshot'] ?? null);
            }
        } catch (\Throwable $e) {
            EasyForm::log('Uninstall file cleanup failed: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Best-effort cleanup of uploaded files for every submission matching a
     * delete condition, run BEFORE the rows are removed. Streams in batches so a
     * large prune / erasure never loads every payload into memory at once.
     *
     * @param array|string $condition A Yii query condition.
     */
    private function cleanupFilesForCondition(array|string $condition): void
    {
        try {
            $rows = SubmissionRecord::find()
                ->select(['data', 'fieldSnapshot'])
                ->where($condition)
                ->asArray()
                ->each(200);

            foreach ($rows as $row) {
                $this->deleteFilesForSubmissionRow($row['data'] ?? null, $row['fieldSnapshot'] ?? null);
            }
        } catch (\Throwable $e) {
            EasyForm::log('Submission file cleanup failed: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Applies the shared export/delete filters (site + date range) to a query.
     */
    private function applyExportFilters(
        \yii\db\ActiveQuery $query,
        ?int $siteId,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $search = null
    ): \yii\db\ActiveQuery {
        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->andWhere(['>=', 'dateCreated', $dateFrom]);
        }
        if ($dateTo !== null && $dateTo !== '') {
            // Inclusive of the whole end day.
            $query->andWhere(['<', 'dateCreated', date('Y-m-d', strtotime($dateTo . ' +1 day'))]);
        }
        // Same match as the submissions list, so an export honors a search the
        // user has applied (matches the promoted name / email columns).
        if ($search !== null && $search !== '') {
            $query->andWhere([
                'or',
                ['like', 'primaryEmail', $search],
                ['like', 'searchCol1', $search],
                ['like', 'searchCol2', $search],
                ['like', 'searchCol3', $search],
            ]);
        }
        return $query;
    }

    /**
     * Counts submissions matching the export/delete filters.
     */
    public function countSubmissionsByFilters(
        int $formId,
        ?string $status = null,
        ?int $siteId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $query = $this->applyExportFilters($this->getSubmissionsQuery($formId, $status), $siteId, $dateFrom, $dateTo);
        return (int) $query->count();
    }

    /**
     * Permanently deletes submissions matching the export/delete filters.
     *
     * @return int Number of submissions deleted
     */
    public function deleteSubmissionsByFilters(
        int $formId,
        ?string $status = null,
        ?int $siteId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $query = $this->applyExportFilters($this->getSubmissionsQuery($formId, $status), $siteId, $dateFrom, $dateTo);
        $ids = $query->select(['id'])->column();
        if (!$ids) {
            return 0;
        }
        $this->cleanupFilesForCondition(['id' => $ids]);
        return SubmissionRecord::deleteAll(['id' => $ids]);
    }

    /**
     * Returns the total number of submissions for a form
     *
     * @param int $formId
     * @param string|null $status Filter by status
     * @return int
     */
    public function getTotalSubmissions(int $formId, ?string $status = null): int
    {
        $query = SubmissionRecord::find()->where(['formId' => $formId]);

        if ($status !== null) {
            $query->andWhere(['status' => $status]);
        }

        return $query->count();
    }

    /**
     * Submission counts keyed by form id, for a set of forms (one query).
     *
     * @param int[] $formIds
     * @return array<int,int> formId => count (forms with no submissions omitted)
     */
    public function getCountsByFormIds(array $formIds): array
    {
        if (!$formIds) {
            return [];
        }

        $rows = SubmissionRecord::find()
            ->select(['formId', 'cnt' => 'COUNT(*)'])
            ->where(['formId' => $formIds])
            ->groupBy('formId')
            ->asArray()
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['formId']] = (int) $row['cnt'];
        }

        return $counts;
    }


    /**
     * Updates the status of a submission
     *
     * @param int $id
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool
    {
        $submission = $this->getSubmissionById($id);
        if (!$submission) {
            return false;
        }

        $submission->status = $status;
        return $this->saveSubmission($submission, false);
    }

    /**
     * Returns submissions count by user
     *
     * @param int $formId
     * @param int $userId
     * @return int
     */
    public function getSubmissionCountByUser(int $formId, int $userId): int
    {
        return SubmissionRecord::find()
            ->where(['formId' => $formId, 'userId' => $userId])
            ->count();
    }

    /**
     * Whether a live submission of this form already stores $value for $handle —
     * used to enforce admin-marked "unique" fields (e.g. one entry per email).
     *
     * Uses the indexed promoted column when the handle is promoted; otherwise a
     * JSON lookup on the canonical `data.values.<handle>`. The handle is already
     * validated (`^[a-zA-Z][a-zA-Z0-9_]*$`) on save, so it's safe in the JSON
     * path; the value is always bound.
     *
     * @param array<string,string> $promotedMap column => handle (from the layout)
     */
    public function valueExistsForField(int $formId, string $handle, string $value, array $promotedMap = []): bool
    {
        $query = SubmissionRecord::find()->where(['formId' => $formId, 'dateDeleted' => null]);

        $column = array_search($handle, $promotedMap, true);
        if (in_array($column, ['primaryEmail', 'searchCol1', 'searchCol2', 'searchCol3'], true)) {
            $query->andWhere([$column => $value]);
        } else {
            $query->andWhere(new \yii\db\Expression(
                'JSON_UNQUOTE(JSON_EXTRACT([[data]], :path)) = :val',
                [':path' => '$.values.' . $handle, ':val' => $value]
            ));
        }

        return $query->exists();
    }

    /**
     * Query for all submissions associated with an email address — matching the
     * promoted primaryEmail column or the email appearing as a value in the JSON
     * payload. Used for GDPR data-subject export/erasure.
     */
    public function findSubmissionsForEmailQuery(string $email): \yii\db\ActiveQuery
    {
        $email = trim($email);
        return SubmissionRecord::find()
            ->where(['dateDeleted' => null])
            ->andWhere([
                'or',
                ['primaryEmail' => $email],
                ['like', 'data', '"' . $email . '"'],
            ])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /**
     * Returns a portable (JSON-friendly) export of all data held for an email.
     */
    public function exportDataForEmail(string $email): array
    {
        $rows = [];
        foreach ($this->findSubmissionsForEmailQuery($email)->asArray()->each(200) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'form' => $row['formName'] ?? ('#' . $row['formId']),
                'formHandle' => $row['formHandle'],
                'submittedAt' => $row['dateCreated'],
                'siteId' => $row['siteId'] !== null ? (int) $row['siteId'] : null,
                'ipAddress' => $row['ipAddress'],
                'status' => $row['status'],
                'data' => $this->flattenPayload($row['data'] ?? []),
            ];
        }

        return [
            'email' => $email,
            'generatedAt' => gmdate('c'),
            'submissionCount' => count($rows),
            'submissions' => $rows,
        ];
    }

    /**
     * Permanently deletes all submissions associated with an email (erasure).
     */
    public function deleteSubmissionsForEmail(string $email): int
    {
        try {
            $ids = $this->findSubmissionsForEmailQuery($email)->select(['id'])->column();
            if (empty($ids)) {
                return 0;
            }
            $this->cleanupFilesForCondition(['id' => $ids]);
            $deleted = SubmissionRecord::deleteAll(['id' => $ids]);
            EasyForm::log("GDPR erasure: removed {$deleted} submission(s) for an email address", 'warning');
            return $deleted;
        } catch (\Throwable $e) {
            EasyForm::log('GDPR erasure failed: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Deletes submissions older than the given number of days.
     *
     * Returns the number of rows removed. Only the count is logged — never
     * payload contents.
     *
     * @param int $days
     * @param int|null $formId Limit pruning to a single form.
     * @return int
     */
    public function pruneOldSubmissions(int $days, ?int $formId = null): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');

        $condition = ['and', ['<', 'dateCreated', $cutoff]];
        if ($formId !== null) {
            $condition[] = ['formId' => $formId];
        }

        try {
            $this->cleanupFilesForCondition($condition);
            return SubmissionRecord::deleteAll($condition);
        } catch (\Throwable $e) {
            EasyForm::log('Submission pruning failed: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Recompute the promoted/search columns (primaryEmail, searchCol1..3) for a
     * form's existing submissions from their stored payloads. Promotion otherwise
     * only applies to new submissions, so this backfills old rows after a field
     * is promoted (or the promotion map changes).
     *
     * @return int Number of submissions updated.
     */
    public function reindexForm(\yannkost\easyform\models\Form $form): int
    {
        $layout = $form->getNormalizedLayout();
        $dataService = \yannkost\easyform\EasyForm::getInstance()->submissionData;
        $table = SubmissionRecord::tableName();
        $db = Craft::$app->getDb();

        $updated = 0;
        $failed = 0;
        foreach ($this->getSubmissionRowsQuery($form->id)->batch(200) as $batch) {
            foreach ($batch as $row) {
                // Isolate each row so one bad payload can't abort the whole backfill.
                try {
                    $flat = $this->flattenPayload($row['data'] ?? []);
                    $promoted = $dataService->promotedColumns($layout, $flat);
                    $db->createCommand()->update($table, $promoted, ['id' => $row['id']])->execute();
                    $updated++;
                } catch (\Throwable $e) {
                    $failed++;
                    EasyForm::log('Re-index skipped submission #' . ($row['id'] ?? '?') . ' for form #' . $form->id . ': ' . $e->getMessage(), 'warning');
                }
            }
        }

        if ($failed > 0) {
            EasyForm::log("Re-index for form #{$form->id} finished with {$failed} skipped row(s)", 'warning');
        }

        return $updated;
    }

    /**
     * Ordered export column definitions for a form. Real form fields default to
     * ON; metadata columns (ID/Status/Date/IP/Extra) default to OFF so they're
     * opt-in. The promoted (denormalised) Email/Search copies are intentionally
     * not offered — they only duplicate real form fields.
     *
     * @return array<int,array{key:string,label:string,group:string,default:bool}>
     */
    public function exportColumns(\yannkost\easyform\models\Form $form): array
    {
        $schema = \yannkost\easyform\EasyForm::getInstance()->formSchema;
        $layout = $form->getNormalizedLayout();

        // Value-carrying fields in schema order, deduped by handle.
        $fieldLabels = [];
        foreach ($schema->getValueFields($layout) as $field) {
            if (!empty($field['handle'])) {
                $fieldLabels[$field['handle']] = $field['label'] ?? $field['handle'];
            }
        }
        foreach ($schema->getFrontendFields($layout) as $field) {
            if (!empty($field['handle'])) {
                $fieldLabels[$field['handle']] = $field['label'] ?? $field['handle'];
            }
        }

        return $this->exportColumnDefs($fieldLabels);
    }

    /**
     * Export column definitions for an orphaned (deleted-form) submission set,
     * derived from a stored field snapshot instead of a live form layout.
     *
     * @return array<int,array{key:string,label:string,group:string,default:bool}>
     */
    public function exportColumnsFromSnapshot(array $snapshot): array
    {
        $fieldLabels = [];
        foreach ($snapshot['fields'] ?? [] as $field) {
            if (!empty($field['handle'])) {
                $fieldLabels[$field['handle']] = $field['label'] ?? $field['handle'];
            }
        }
        return $this->exportColumnDefs($fieldLabels);
    }

    /**
     * Assemble ordered export column defs from a handle => label map, appending
     * the shared metadata columns (ID/Status/Date/IP/Extra, all opt-in).
     *
     * @param array<string,string> $fieldLabels
     * @return array<int,array{key:string,label:string,group:string,default:bool}>
     */
    private function exportColumnDefs(array $fieldLabels): array
    {
        $defs = [];
        foreach ($fieldLabels as $handle => $label) {
            $defs[] = ['key' => 'field:' . $handle, 'label' => $label, 'group' => 'fields', 'default' => true];
        }

        $defs[] = ['key' => 'id', 'label' => Craft::t('easy-form', 'ID'), 'group' => 'meta', 'default' => false];
        $defs[] = ['key' => 'status', 'label' => Craft::t('easy-form', 'Status'), 'group' => 'meta', 'default' => false];
        $defs[] = ['key' => 'dateCreated', 'label' => Craft::t('easy-form', 'Date created'), 'group' => 'meta', 'default' => false];
        $defs[] = ['key' => 'ip', 'label' => Craft::t('easy-form', 'IP address'), 'group' => 'meta', 'default' => false];
        $defs[] = ['key' => 'extra', 'label' => Craft::t('easy-form', 'Other stored data (JSON)'), 'group' => 'meta', 'default' => false];

        return $defs;
    }

    /**
     * Builds a CSV export as a stream resource (disk-backed past 8MB), suitable
     * for high-volume forms.
     *
     * Columns are chosen from exportColumns(): when $columns is null the default
     * set (real form fields) is used; otherwise it's the list of selected keys.
     * Database rows are read in batches as plain arrays — no model hydration.
     *
     * @param \yannkost\easyform\models\Form $form
     * @param string[]|null $columns Selected column keys, or null for defaults.
     * @return resource
     */
    public function buildCsvStream(
        \yannkost\easyform\models\Form $form,
        ?string $status = null,
        ?int $siteId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null,
        ?array $columns = null,
        ?int &$rowCount = null
    ) {
        $query = $this->applyExportFilters(
            $this->getSubmissionRowsQuery($form->id, $status),
            $siteId, $dateFrom, $dateTo, $search
        );
        return $this->streamCsv($this->exportColumns($form), $query, $columns, $rowCount);
    }

    /**
     * Like buildCsvStream, but for orphaned submissions of a single deleted
     * form (matched by handle). Columns come from a stored field snapshot,
     * since the form itself is gone.
     *
     * @return resource
     */
    public function buildOrphanedCsvStream(
        string $handle,
        ?string $status = null,
        ?int $siteId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null,
        ?array $columns = null,
        ?int &$rowCount = null
    ) {
        $snapshot = $this->getOrphanedFieldSnapshot($handle) ?? ['fields' => []];
        $query = $this->applyExportFilters(
            $this->getOrphanedRowsQuery($handle, $status),
            $siteId, $dateFrom, $dateTo, $search
        );
        return $this->streamCsv($this->exportColumnsFromSnapshot($snapshot), $query, $columns, $rowCount);
    }

    /**
     * Core CSV writer shared by the live-form and orphaned export paths.
     *
     * @param array $defs Column definitions (see exportColumns()).
     * @param \yii\db\ActiveQuery $query A filtered, ->asArray() submissions query.
     * @return resource A rewound php://temp stream.
     */
    private function streamCsv(array $defs, \yii\db\ActiveQuery $query, ?array $columns = null, ?int &$rowCount = null)
    {
        $rowCount = 0;

        // Resolve active columns in canonical order from the selection (or defaults).
        $selected = $columns !== null
            ? array_flip($columns)
            : array_flip(array_column(array_filter($defs, fn($d) => $d['default']), 'key'));
        $active = array_values(array_filter($defs, fn($d) => isset($selected[$d['key']])));
        if (!$active) {
            // Never emit a column-less file; fall back to the defaults.
            $active = array_values(array_filter($defs, fn($d) => $d['default']));
        }

        // Every real field handle, for the Extra (leftover) computation regardless
        // of what was selected.
        $knownHandles = [];
        foreach ($defs as $d) {
            if (strncmp($d['key'], 'field:', 6) === 0) {
                $knownHandles[] = substr($d['key'], 6);
            }
        }

        $out = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'r+');
        // Sanitize headers too — field labels are admin-controlled.
        $this->putCsvRow($out, array_column($active, 'label'));

        foreach ($query->batch(200) as $batch) {
            foreach ($batch as $row) {
                $flat = $this->flattenPayload($row['data'] ?? []);
                $meta = [
                    'id' => $row['id'] ?? '',
                    'status' => $row['status'] ?? '',
                    'dateCreated' => $row['dateCreated'] ?? '',
                    'ip' => $row['ipAddress'] ?? '',
                ];

                $line = [];
                foreach ($active as $d) {
                    $key = $d['key'];
                    if (strncmp($key, 'field:', 6) === 0) {
                        $value = $flat[substr($key, 6)] ?? '';
                        $line[] = is_array($value) ? Json::encode($value) : $value;
                    } elseif ($key === 'extra') {
                        $extra = array_diff_key($flat, array_flip($knownHandles));
                        $line[] = !empty($extra) ? Json::encode($extra) : '';
                    } else {
                        $line[] = $meta[$key] ?? '';
                    }
                }

                $this->putCsvRow($out, $line);
                $rowCount++;
            }
        }

        rewind($out);
        return $out;
    }

    /**
     * Write one CSV row, neutralizing spreadsheet formula injection in every
     * cell (a leading =,+,-,@,tab,CR,LF gets a single-quote prefix).
     *
     * @param resource $out
     * @param array $cells
     */
    private function putCsvRow($out, array $cells): void
    {
        fputcsv($out, array_map(
            static fn($cell) => Sanitization::sanitizeForCsv((string) $cell),
            $cells
        ));
    }

    /**
     * Flattens a canonical (values+frontend) or legacy-flat payload to a single
     * [handle => value] map.
     */
    private function flattenPayload($payload): array
    {
        // Decode strings, tolerating legacy double-encoded JSON.
        for ($i = 0; $i < 3 && is_string($payload); $i++) {
            $decoded = Json::decodeIfJson($payload);
            if ($decoded === $payload) {
                break;
            }
            $payload = $decoded;
        }
        if (!is_array($payload)) {
            return [];
        }
        if (array_key_exists('values', $payload) && array_key_exists('meta', $payload)) {
            return array_merge(
                is_array($payload['values'] ?? null) ? $payload['values'] : [],
                is_array($payload['frontend'] ?? null) ? $payload['frontend'] : []
            );
        }
        return $payload;
    }

    /**
     * Returns the submissions query
     *
     * @param int|null $formId
     * @param string|null $status
     * @return \yii\db\ActiveQuery
     */
    public function getSubmissionsQuery(?int $formId = null, ?string $status = null): \yii\db\ActiveQuery
    {
        $query = SubmissionRecord::find()
            ->where(['dateDeleted' => null])
            // Stable ordering with id as tie-breaker (matches compound indexes).
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($formId) {
            $query->andWhere(['formId' => $formId]);
        }

        if ($status !== null) {
            $query->andWhere(['status' => $status]);
        }

        return $query;
    }

    /**
     * Returns a lightweight (asArray) query for high-volume read paths such as
     * exports. Avoids ActiveRecord/model hydration.
     */
    public function getSubmissionRowsQuery(?int $formId = null, ?string $status = null): \yii\db\ActiveQuery
    {
        return $this->getSubmissionsQuery($formId, $status)->asArray();
    }

    /**
     * The distinct deleted forms that still have orphaned submissions, with a
     * count each — powers the "deleted form" filter on the submissions screen.
     *
     * @return array<int,array{formHandle:string,formName:string,n:int}>
     */
    public function getOrphanedForms(): array
    {
        return SubmissionRecord::find()
            ->select(['formHandle', 'formName', 'n' => 'COUNT(*)'])
            ->where(['dateDeleted' => null, 'formId' => null])
            ->andWhere(['not', ['formHandle' => null]])
            ->andWhere(['not', ['formHandle' => '']])
            ->groupBy(['formHandle', 'formName'])
            ->orderBy(['formName' => SORT_ASC])
            ->asArray()
            ->all();
    }

    /** Base query for orphaned submissions of one deleted form (by handle). */
    public function getOrphanedSubmissionsQuery(string $handle, ?string $status = null): \yii\db\ActiveQuery
    {
        $query = SubmissionRecord::find()
            ->where(['dateDeleted' => null, 'formId' => null, 'formHandle' => $handle])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
        if ($status !== null) {
            $query->andWhere(['status' => $status]);
        }
        return $query;
    }

    public function getOrphanedRowsQuery(string $handle, ?string $status = null): \yii\db\ActiveQuery
    {
        return $this->getOrphanedSubmissionsQuery($handle, $status)->asArray();
    }

    /**
     * A representative field snapshot for a deleted form, taken from its most
     * recent orphaned submission (used to reconstruct export columns).
     */
    public function getOrphanedFieldSnapshot(string $handle): ?array
    {
        $snapshot = SubmissionRecord::find()
            ->select(['fieldSnapshot'])
            ->where(['dateDeleted' => null, 'formId' => null, 'formHandle' => $handle])
            ->andWhere(['not', ['fieldSnapshot' => null]])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->scalar();
        if (!$snapshot) {
            return null;
        }
        $decoded = is_string($snapshot) ? Json::decodeIfJson($snapshot) : $snapshot;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Creates a Submission model from a SubmissionRecord
     *
     * @param SubmissionRecord $record
     * @return Submission
     */
    public function createSubmissionFromRecord(SubmissionRecord $record): Submission
    {
        $submission = new Submission();
        $submission->id = $record->id;
        $submission->formId = $record->formId;
        $submission->formHandle = $record->formHandle;
        $submission->formName = $record->formName;
        $submission->siteId = $record->siteId;
        $submission->data = $record->data;
        $submission->fieldSnapshot = $record->fieldSnapshot;
        $submission->primaryEmail = $record->primaryEmail;
        $submission->searchCol1 = $record->searchCol1;
        $submission->searchCol2 = $record->searchCol2;
        $submission->searchCol3 = $record->searchCol3;
        $submission->userId = $record->userId;
        $submission->ipAddress = $record->ipAddress;
        $submission->userAgent = $record->userAgent;
        $submission->status = $record->status;
        $submission->spamScore = $record->spamScore;
        $submission->captchaScore = $record->captchaScore !== null ? (float) $record->captchaScore : null;
        $submission->honeypotValue = $record->honeypotValue;
        $submission->spamReason = $record->spamReason;
        $submission->isTest = (bool) $record->isTest;
        $submission->dateCreated = $record->dateCreated;
        $submission->dateUpdated = $record->dateUpdated;
        $submission->dateDeleted = $record->dateDeleted;
        $submission->uid = $record->uid;

        return $submission;
    }
}
