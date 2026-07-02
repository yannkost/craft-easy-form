<?php

namespace yannkost\easyform\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * Submission record
 *
 * @property int $id
 * @property int|null $formId
 * @property string|null $formHandle
 * @property string|null $formName
 * @property int|null $siteId
 * @property string $data
 * @property string|null $fieldSnapshot
 * @property string|null $primaryEmail
 * @property string|null $searchCol1
 * @property string|null $searchCol2
 * @property string|null $searchCol3
 * @property int|null $userId
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property string $status
 * @property float|null $spamScore
 * @property float|null $captchaScore
 * @property string|null $honeypotValue
 * @property string|null $spamReason
 * @property bool $isTest
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string|null $dateDeleted
 * @property string $uid
 */
class SubmissionRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%easyform_submissions}}';
    }

    /**
     * Returns the submission's form
     *
     * @return ActiveQueryInterface
     */
    public function getForm(): ActiveQueryInterface
    {
        return $this->hasOne(FormRecord::class, ['id' => 'formId']);
    }

    /**
     * Returns the submission's user
     *
     * @return ActiveQueryInterface
     */
    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
