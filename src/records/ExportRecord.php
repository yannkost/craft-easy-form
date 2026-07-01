<?php

namespace yannkost\easyform\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * Export record — one row per queued CSV export.
 *
 * Kept exports (keep = true, dateExpires = null) live until the user deletes
 * them; ephemeral ones carry a dateExpires and are garbage-collected.
 *
 * @property int $id
 * @property string $token
 * @property int|null $formId
 * @property string|null $formName
 * @property array|string|null $filters
 * @property string $filename
 * @property int|null $rowCount
 * @property int|null $fileSize
 * @property string $status
 * @property string|null $message
 * @property bool $keep
 * @property string|null $dateExpires
 * @property int|null $createdBy
 */
class ExportRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%easyform_exports}}';
    }

    public function getForm(): ActiveQueryInterface
    {
        return $this->hasOne(FormRecord::class, ['id' => 'formId']);
    }

    public function getCreator(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'createdBy']);
    }
}
