<?php

namespace yannkost\easyform\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Form record
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string $fieldLayout
 * @property string|null $settings
 * @property string|null $notificationSettings
 * @property bool $enabled
 * @property string|null $successMessage
 * @property string|null $redirectUrl
 * @property bool $hideFormOnSuccess
 * @property bool $keepSuccessMessage
 * @property int $successMessageDuration
 * @property int|null $maxSubmissionsPerUser
 * @property int $rateLimit
 * @property int $rateLimitWindow
 * @property bool $saveSpamSubmissions
 * @property bool $autoApprove
 * @property string|null $captchaProvider
 * @property float|null $captchaScoreThreshold
 * @property bool $rejectOnCaptchaFail
 * @property bool $allowUrlPrefill
 * @property bool $showStepIndicator
 * @property bool $validateSteps
 * @property string|null $webhookUrl
 * @property string $webhookPayload
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class FormRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%easyform_forms}}';
    }

    /**
     * Returns the form's submissions
     *
     * @return ActiveQueryInterface
     */
    public function getSubmissions(): ActiveQueryInterface
    {
        return $this->hasMany(SubmissionRecord::class, ['formId' => 'id']);
    }
}
