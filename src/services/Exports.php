<?php

namespace yannkost\easyform\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use yannkost\easyform\records\ExportRecord;
use yii\db\ActiveQuery;

/**
 * Exports service.
 *
 * Owns the on-disk export files and their bookkeeping rows. Every export gets
 * an {@see ExportRecord}; the queue job flips it to ready/failed. Files live in
 * a plugin-controlled, non-web-accessible directory and are only ever served
 * back through the permission-checked download action.
 */
class Exports extends Component
{
    /** How long a non-kept export survives before garbage collection (seconds). */
    public const EPHEMERAL_TTL = 86400; // 24h

    /** Directory holding generated export files (persistent, not web-accessible). */
    public function storageDir(): string
    {
        return Craft::getAlias('@storage/easy-form/exports');
    }

    public function filePath(string $token): string
    {
        return $this->storageDir() . DIRECTORY_SEPARATOR . $token . '.csv';
    }

    public function getByToken(string $token): ?ExportRecord
    {
        return ExportRecord::findOne(['token' => $token]);
    }

    /**
     * Create the bookkeeping row for a queued export. `dateExpires` is null for
     * kept exports (they live until deleted) or a UTC timestamp otherwise.
     */
    public function createRecord(array $attributes): ExportRecord
    {
        $record = new ExportRecord($attributes);
        $record->save(false);
        return $record;
    }

    public function markReady(string $token, int $rowCount, int $fileSize): void
    {
        $record = $this->getByToken($token);
        if (!$record) {
            return;
        }
        $record->status = 'ready';
        $record->rowCount = $rowCount;
        $record->fileSize = $fileSize;
        $record->save(false);
    }

    public function markFailed(string $token, ?string $message = null): void
    {
        $record = $this->getByToken($token);
        if (!$record) {
            return;
        }
        $record->status = 'failed';
        $record->message = $message;
        $record->save(false);
    }

    /** Kept exports, newest first, with the creator eager-loaded for the list. */
    public function getSavedQuery(): ActiveQuery
    {
        return ExportRecord::find()
            ->where(['keep' => true])
            ->with('creator')
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /** Decode a record's stored filters snapshot to an array. */
    public function decodeFilters(ExportRecord $record): array
    {
        $filters = $record->filters;
        if (is_string($filters)) {
            $filters = Json::decodeIfJson($filters);
        }
        return is_array($filters) ? $filters : [];
    }

    /** Delete an export: its file first, then the row. */
    public function delete(ExportRecord $record): void
    {
        @unlink($this->filePath($record->token));
        $record->delete();
    }

    /**
     * Remove expired ephemeral exports (files + rows). Kept exports
     * (dateExpires = null) are never touched.
     */
    public function garbageCollect(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $expired = ExportRecord::find()
            ->where(['keep' => false])
            ->andWhere(['not', ['dateExpires' => null]])
            ->andWhere(['<', 'dateExpires', $now])
            ->all();
        foreach ($expired as $record) {
            $this->delete($record);
        }
    }
}
