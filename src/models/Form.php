<?php

namespace yannkost\easyform\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use yii\validators\EmailValidator;
use yannkost\easyform\EasyForm;

/**
 * Form model
 *
 * @property-read array $fieldLayoutArray
 * @property-read array $settingsArray
 * @property-read array $notificationSettingsArray
 * @property-read array $pages
 * @property-read array $fields
 */
class Form extends Model
{
    /**
     * @var int|null ID
     */
    public ?int $id = null;

    /**
     * @var string Form name
     */
    public string $name = '';

    /**
     * @var string Form handle (unique identifier)
     */
    public string $handle = '';

    /**
     * @var string|null Form description
     */
    public ?string $description = null;

    /**
     * @var string|array Form field layout configuration
     */
    public string|array $fieldLayout = [];

    /**
     * @var string|array|null Additional form settings
     */
    public string|array|null $settings = null;

    /**
     * @var string|array|null Email notification settings
     */
    public string|array|null $notificationSettings = null;

    /**
     * @var bool Whether the form is enabled
     */
    public bool $enabled = true;

    /**
     * @var string|null Success message shown after submission
     */
    public ?string $successMessage = null;

    /**
     * @var string|null URL to redirect to after submission
     */
    public ?string $redirectUrl = null;

    /**
     * @var bool Whether to hide the form after successful submission
     */
    public bool $hideFormOnSuccess = false;

    /**
     * @var bool Whether to keep the success message visible until the page is
     * reloaded. When false, the message auto-hides after successMessageDuration.
     */
    public bool $keepSuccessMessage = true;

    /**
     * @var int Seconds before the success message auto-hides when
     * keepSuccessMessage is false. Minimum 1.
     */
    public int $successMessageDuration = 5;

    /**
     * @var int|null Maximum submissions per user
     */
    public ?int $maxSubmissionsPerUser = null;

    /**
     * @var int Max submissions per IP within the rate-limit window (0 = off)
     */
    public int $rateLimit = 0;

    /**
     * @var int Rate-limit window in seconds
     */
    public int $rateLimitWindow = 60;

    /**
     * @var bool Whether to save submissions when honeypot is filled (spam submissions)
     */
    public bool $saveSpamSubmissions = false;

    /**
     * @var bool Whether new non-spam submissions are saved as 'approved' (skipping
     * the 'pending' moderation status). Default true — most forms are plain
     * submissions; set false to route a form through manual moderation.
     */
    public bool $autoApprove = true;

    /**
     * @var string|null Handle of the CAPTCHA provider this form uses (null = none)
     */
    public ?string $captchaProvider = null;

    /**
     * @var float|null Per-form reCAPTCHA v3 score threshold override (0–1).
     * Null falls back to the global setting.
     */
    public ?float $captchaScoreThreshold = null;

    /**
     * @var bool Hard-reject a submission when its CAPTCHA fails (showing an
     * error), instead of the default: silently filing it as spam.
     */
    public bool $rejectOnCaptchaFail = false;

    /**
     * @var bool Whether to pre-fill fields from URL query params on the front end
     */
    public bool $allowUrlPrefill = false;

    /**
     * @var bool Whether to show a step indicator on multi-page forms
     */
    public bool $showStepIndicator = false;

    /** @var bool Validate each step before advancing (multi-page, front-end only) */
    public bool $validateSteps = true;

    /**
     * @var string|null URL to POST each new submission to (null/empty = disabled)
     */
    public ?string $webhookUrl = null;

    /**
     * @var string Webhook payload mode: 'full' (wrapped) or 'data' (flat values)
     */
    public string $webhookPayload = 'full';

    /**
     * @var string|array|null Site-specific success messages
     */
    public string|array|null $siteSuccessMessages = [];

    /**
     * @var string|array|null Site-specific error messages
     */
    public string|array|null $siteErrorMessages = [];

    /**
     * @var string|array|null Site-specific redirect URLs (blank = fall back to redirectUrl)
     */
    public string|array|null $siteRedirectUrls = [];

    /**
     * @var string|null Submit button label (blank = the default "Submit")
     */
    public ?string $submitButtonLabel = null;

    /**
     * @var string|array|null Site-specific submit button labels (blank = fall back to submitButtonLabel)
     */
    public string|array|null $siteSubmitButtonLabels = [];

    /**
     * @var string|null Date created
     */
    public ?string $dateCreated = null;

    /**
     * @var string|null Date updated
     */
    public ?string $dateUpdated = null;

    /**
     * @var string|null UID
     */
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('easy-form', 'Form Name'),
            'handle' => Craft::t('easy-form', 'Handle'),
            'description' => Craft::t('easy-form', 'Description'),
            'fieldLayout' => Craft::t('easy-form', 'Field Layout'),
            'settings' => Craft::t('easy-form', 'Settings'),
            'notificationSettings' => Craft::t('easy-form', 'Notification Settings'),
            'enabled' => Craft::t('easy-form', 'Enabled'),
            'successMessage' => Craft::t('easy-form', 'Success Message'),
            'redirectUrl' => Craft::t('easy-form', 'Redirect URL'),
            'hideFormOnSuccess' => Craft::t('easy-form', 'Hide Form on Success'),
            'keepSuccessMessage' => Craft::t('easy-form', 'Keep Success Message'),
            'successMessageDuration' => Craft::t('easy-form', 'Success Message Duration'),
            'maxSubmissionsPerUser' => Craft::t('easy-form', 'Max Submissions Per User'),
            'saveSpamSubmissions' => Craft::t('easy-form', 'Save Spam Submissions'),
            'captchaProvider' => Craft::t('easy-form', 'CAPTCHA Provider'),
            'captchaScoreThreshold' => Craft::t('easy-form', 'Score Threshold'),
            'rejectOnCaptchaFail' => Craft::t('easy-form', 'Reject submission on CAPTCHA failure'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'fieldLayout'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            ['handle', 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, and underscores.'],
            ['handle', 'validateUniqueHandle'],
            [['description', 'successMessage'], 'string'],
            [['siteSuccessMessages', 'siteErrorMessages', 'siteRedirectUrls'], 'safe'],
            ['redirectUrl', 'validateRedirectUrl'],
            // Require http/https but allow dotless internal hosts (localhost,
            // service names). Reachability/safety is enforced by the SSRF guard
            // at send time, not here.
            ['webhookUrl', 'url', 'defaultScheme' => null, 'validSchemes' => ['http', 'https'],
                'pattern' => '/^{schemes}:\/\/(([A-Z0-9][A-Z0-9_-]*)(\.[A-Z0-9][A-Z0-9_-]*)*)(?::\d{1,5})?(\/.*)?$/i'],
            ['maxSubmissionsPerUser', 'integer', 'min' => 1],
            ['successMessageDuration', 'integer', 'min' => 1],
            [['enabled', 'hideFormOnSuccess', 'keepSuccessMessage', 'saveSpamSubmissions', 'autoApprove'], 'boolean'],
            ['captchaProvider', 'string', 'max' => 64],
            ['captchaProvider', 'validateCaptchaProvider'],
            ['captchaScoreThreshold', 'number', 'min' => 0, 'max' => 1],
            ['rejectOnCaptchaFail', 'boolean'],
            ['fieldLayout', 'validateFieldLayout'],
            ['notificationSettings', 'validateNotificationSettings'],
        ];
    }

    /**
     * Validates that the handle is unique
     */
    public function validateUniqueHandle(string $attribute): void
    {
        if (!$this->hasErrors($attribute)) {
            $exists = (new \craft\db\Query())
                ->from('{{%easyform_forms}}')
                ->where(['handle' => $this->$attribute])
                ->andWhere(['not', ['id' => $this->id]])
                ->exists();

            if ($exists) {
                $this->addError($attribute, Craft::t('easy-form', 'Handle "{value}" is already in use.', [
                    'value' => $this->$attribute,
                ]));
            }
        }
    }

    /**
     * Validates the field layout structure, the frontend allowlist, condition
     * references and promoted field references.
     */
    public function validateFieldLayout(string $attribute): void
    {
        $schemaService = EasyForm::getInstance()->formSchema;
        $normalized = $schemaService->normalize($this->getFieldLayoutArray());
        $allFields = $schemaService->getAllFields($normalized);

        if (empty($allFields)) {
            $this->addError($attribute, Craft::t('easy-form', 'Field layout must contain at least one field.'));
            return;
        }

        $handlePattern = '/^[a-zA-Z][a-zA-Z0-9_]*$/';
        $seenHandles = [];

        // Validate each form-builder field.
        foreach ($allFields as $index => $field) {
            $handle = $field['handle'] ?? '';

            if (empty($field['id'])) {
                $this->addError($attribute, Craft::t('easy-form', 'Field at index {index} is missing an ID.', ['index' => $index]));
            }
            if (empty($field['type'])) {
                $this->addError($attribute, Craft::t('easy-form', 'Field at index {index} is missing a type.', ['index' => $index]));
            }
            if (empty($handle)) {
                $this->addError($attribute, Craft::t('easy-form', 'Field at index {index} is missing a handle.', ['index' => $index]));
                continue;
            }
            if (!preg_match($handlePattern, $handle)) {
                $this->addError($attribute, Craft::t('easy-form', 'Field handle "{handle}" is invalid.', ['handle' => $handle]));
            }
            if (isset($seenHandles[$handle])) {
                $this->addError($attribute, Craft::t('easy-form', 'Duplicate field handle "{handle}".', ['handle' => $handle]));
            }
            $seenHandles[$handle] = true;
        }

        // Validate frontend (allowlisted) field definitions.
        foreach ($schemaService->getFrontendFields($normalized) as $field) {
            $handle = $field['handle'] ?? '';
            if (empty($handle) || !preg_match($handlePattern, $handle)) {
                $this->addError($attribute, Craft::t('easy-form', 'Frontend field handle "{handle}" is invalid.', ['handle' => $handle]));
                continue;
            }
            if (isset($seenHandles[$handle])) {
                $this->addError($attribute, Craft::t('easy-form', 'Frontend field "{handle}" collides with an existing field handle.', ['handle' => $handle]));
            }
            $seenHandles[$handle] = true;

            if (!in_array($field['type'], \yannkost\easyform\services\FormSchemaService::FRONTEND_TYPES, true)) {
                $this->addError($attribute, Craft::t('easy-form', 'Frontend field "{handle}" has an unsupported type.', ['handle' => $handle]));
            }
        }

        // Validate condition references point at known handles.
        $knownHandles = $schemaService->getKnownHandles($normalized);
        foreach ($schemaService->getPages($normalized) as $page) {
            $this->validateConditionRefs($attribute, $page, $knownHandles);
            foreach ($page['rows'] ?? [] as $row) {
                $this->validateConditionRefs($attribute, $row, $knownHandles);
                foreach ($row['fields'] ?? [] as $field) {
                    $this->validateConditionRefs($attribute, $field, $knownHandles);
                }
            }
        }

        // Validate promoted field references resolve to accepted handles.
        $acceptedHandles = $schemaService->getAcceptedHandles($normalized);
        foreach ($schemaService->getPromotedFields($normalized) as $column => $sourceHandle) {
            if ($sourceHandle !== '' && !in_array($sourceHandle, $acceptedHandles, true)) {
                $this->addError($attribute, Craft::t('easy-form', 'Promoted field "{column}" references unknown handle "{handle}".', [
                    'column' => $column,
                    'handle' => $sourceHandle,
                ]));
            }
        }
    }

    /**
     * Validates that a field/page's condition rules reference existing handles.
     */
    private function validateConditionRefs(string $attribute, array $item, array $knownHandles): void
    {
        $rules = $item['conditions']['rules'] ?? [];
        foreach ($rules as $rule) {
            $ref = $rule['field'] ?? '';
            if ($ref !== '' && !in_array($ref, $knownHandles, true)) {
                $this->addError($attribute, Craft::t('easy-form', 'Condition references unknown field "{handle}".', ['handle' => $ref]));
            }
        }
    }

    /**
     * Validates the selected CAPTCHA provider handle is one that exists.
     */
    public function validateCaptchaProvider(string $attribute): void
    {
        if (empty($this->$attribute)) {
            return;
        }
        $providers = EasyForm::getInstance()->captcha->getProviders();
        if (!isset($providers[$this->$attribute])) {
            $this->addError($attribute, Craft::t('easy-form', 'Unknown CAPTCHA provider "{handle}".', ['handle' => $this->$attribute]));
        }
    }

    /**
     * Validates the success redirect URL.
     *
     * Accepts an absolute http(s) URL, a root-relative path (e.g. "/thank-you"),
     * or a protocol-relative URL ("//host/path"). The plain Yii `url` validator
     * rejects relative paths, which silently dropped the most common input —
     * matching the field's own "/thank-you" placeholder.
     */
    public function validateRedirectUrl(string $attribute): void
    {
        $value = trim((string) ($this->$attribute ?? ''));
        if ($value === '') {
            return;
        }

        // Root-relative ("/path") and protocol-relative ("//host/path") are fine.
        if (str_starts_with($value, '/')) {
            return;
        }

        // Otherwise require an absolute http(s) URL; reject other schemes
        // (javascript:, data:, mailto:, …) so success can't run arbitrary code.
        $validator = new \yii\validators\UrlValidator(['validSchemes' => ['http', 'https']]);
        if (!$validator->validate($value)) {
            $this->addError($attribute, Craft::t('easy-form', 'Redirect URL must be an absolute http(s) URL or a relative path starting with "/".'));
        }
    }

    /**
     * Validates notification settings structure
     */
    public function validateNotificationSettings(string $attribute): void
    {
        if ($this->$attribute === null) {
            return;
        }

        $settings = $this->getNotificationSettingsArray();

        if (!empty($settings['recipients'])) {
            $validator = new EmailValidator();
            foreach ($settings['recipients'] as $email) {
                // Skip template variables
                if (str_contains($email, '{{')) {
                    continue;
                }
                if (!$validator->validate($email)) {
                    $this->addError($attribute, Craft::t('easy-form', 'Invalid email address: {email}', ['email' => $email]));
                }
            }
        }
    }

    /**
     * Returns the field layout as an array
     */
    public function getFieldLayoutArray(): array
    {
        if (is_string($this->fieldLayout)) {
            return Json::decodeIfJson($this->fieldLayout);
        }
        return $this->fieldLayout;
    }

    /**
     * Returns the settings as an array
     */
    public function getSettingsArray(): array
    {
        if ($this->settings === null) {
            return [];
        }
        if (is_string($this->settings)) {
            return Json::decodeIfJson($this->settings);
        }
        return $this->settings;
    }

    /**
     * The per-form file-handling override, stored under settings.fileOverride.
     * Returns null when the form follows the global configuration.
     */
    public function getFileOverride(): ?array
    {
        $override = $this->getSettingsArray()['fileOverride'] ?? null;
        return (is_array($override) && !empty($override['enabled'])) ? $override : null;
    }

    /**
     * Resolve the file-upload settings that apply to this form. When the form
     * opts out of the global configuration, a clone of the global Settings is
     * returned with the upload-related values replaced by the per-form
     * overrides; otherwise the global Settings instance is returned as-is.
     */
    public function getEffectiveUploadSettings(): \yannkost\easyform\models\Settings
    {
        $global = EasyForm::getInstance()->getSettings();
        $override = $this->getFileOverride();

        if ($override === null) {
            return $global;
        }

        $effective = clone $global;
        $effective->uploadMode = ($override['uploadMode'] ?? '') === 'filesystem' ? 'filesystem' : 'asset';
        $effective->uploadVolumeUid = (string) ($override['uploadVolumeUid'] ?? '');
        $effective->uploadSubfolder = (string) ($override['uploadSubfolder'] ?? '');
        $effective->uploadFilesystemPath = ((string) ($override['uploadFilesystemPath'] ?? '')) ?: $global->uploadFilesystemPath;
        $effective->uploadBaseUrl = ((string) ($override['uploadBaseUrl'] ?? '')) ?: $global->uploadBaseUrl;
        $effective->uploadDateSubfolders = (bool) ($override['uploadDateSubfolders'] ?? true);
        if (!empty($override['maxFileSize']) && is_numeric($override['maxFileSize'])) {
            $effective->maxFileSize = (int) $override['maxFileSize'];
        }

        return $effective;
    }

    /**
     * Returns the notification settings as an array
     */
    public function getNotificationSettingsArray(): array
    {
        if ($this->notificationSettings === null) {
            return [];
        }
        if (is_string($this->notificationSettings)) {
            return Json::decodeIfJson($this->notificationSettings);
        }
        return $this->notificationSettings;
    }

    /**
     * Returns the site success messages as an array
     */
    public function getSiteSuccessMessagesArray(): array
    {
        if ($this->siteSuccessMessages === null) {
            return [];
        }
        if (is_string($this->siteSuccessMessages)) {
            return Json::decodeIfJson($this->siteSuccessMessages);
        }
        return $this->siteSuccessMessages;
    }

    /**
     * Returns the site error messages as an array
     */
    public function getSiteErrorMessagesArray(): array
    {
        if ($this->siteErrorMessages === null) {
            return [];
        }
        if (is_string($this->siteErrorMessages)) {
            return Json::decodeIfJson($this->siteErrorMessages);
        }
        return $this->siteErrorMessages;
    }

    /**
     * Returns the site redirect URLs as an array
     */
    public function getSiteRedirectUrlsArray(): array
    {
        if ($this->siteRedirectUrls === null) {
            return [];
        }
        if (is_string($this->siteRedirectUrls)) {
            return Json::decodeIfJson($this->siteRedirectUrls);
        }
        return $this->siteRedirectUrls;
    }

    /**
     * Resolves the redirect URL for a site handle, falling back to the default
     * (per-form) redirect URL when no per-site override is set.
     */
    public function getRedirectUrlForSite(?string $siteHandle): string
    {
        $map = $this->getSiteRedirectUrlsArray();
        $perSite = $siteHandle !== null ? trim((string) ($map[$siteHandle] ?? '')) : '';
        return $perSite !== '' ? $perSite : trim((string) ($this->redirectUrl ?? ''));
    }

    /**
     * Returns the site submit button labels as an array
     */
    public function getSiteSubmitButtonLabelsArray(): array
    {
        if ($this->siteSubmitButtonLabels === null) {
            return [];
        }
        if (is_string($this->siteSubmitButtonLabels)) {
            return Json::decodeIfJson($this->siteSubmitButtonLabels) ?: [];
        }
        return $this->siteSubmitButtonLabels;
    }

    /**
     * Resolves the submit button label for a site handle: the per-site label,
     * then the form's default label, then the generic "Submit".
     */
    public function getSubmitButtonLabelForSite(?string $siteHandle): string
    {
        $map = $this->getSiteSubmitButtonLabelsArray();
        $perSite = $siteHandle !== null ? trim((string) ($map[$siteHandle] ?? '')) : '';
        if ($perSite !== '') {
            return $perSite;
        }
        $default = trim((string) ($this->submitButtonLabel ?? ''));
        return $default !== '' ? $default : Craft::t('easy-form', 'Submit');
    }

    /**
     * Returns all fields from the field layout (across all pages)
     *
     * @return array
     */
    public function getFields(): array
    {
        $layout = $this->getFieldLayoutArray();
        $schemaService = EasyForm::getInstance()->formSchema;
        $normalized = $schemaService->normalize($layout);

        return $schemaService->getAllFields($normalized);
    }

    /**
     * Returns all pages from the field layout
     *
     * @return array
     */
    public function getPages(): array
    {
        $layout = $this->getFieldLayoutArray();
        $schemaService = EasyForm::getInstance()->formSchema;
        $normalized = $schemaService->normalize($layout);

        return $schemaService->getPages($normalized);
    }

    /**
     * Returns the normalized field layout (always at the current schema version).
     */
    public function getNormalizedLayout(): array
    {
        return EasyForm::getInstance()->formSchema->normalize($this->getFieldLayoutArray());
    }

    /**
     * Returns the extra-field policy (strict | allowListed | open).
     */
    public function getExtraFieldPolicy(): string
    {
        return EasyForm::getInstance()->formSchema->getPolicy($this->getNormalizedLayout());
    }

    /**
     * Returns declared frontend (allowlisted) field definitions.
     */
    public function getFrontendFields(): array
    {
        return EasyForm::getInstance()->formSchema->getFrontendFields($this->getNormalizedLayout());
    }

    /**
     * Returns the promoted field map (metadata column => source handle).
     */
    public function getPromotedFields(): array
    {
        return EasyForm::getInstance()->formSchema->getPromotedFields($this->getNormalizedLayout());
    }

    /**
     * Returns only the field handles that are visible given the submitted data.
     * Evaluates page-level and field-level conditions.
     *
     * @param array $submissionData Flat [handle => value] data
     * @return array List of visible field handles
     */
    public function getVisibleFieldHandles(array $submissionData, ?string $siteHandle = null): array
    {
        $layout = $this->getFieldLayoutArray();
        $schemaService = EasyForm::getInstance()->formSchema;
        $normalized = $schemaService->normalize($layout);

        return EasyForm::getInstance()->conditionEvaluator->getVisibleFieldHandles($normalized, $submissionData, $siteHandle);
    }

    /**
     * Returns whether the form has multiple pages
     *
     * @return bool
     */
    public function isMultiPage(): bool
    {
        return count($this->getPages()) > 1;
    }

    /**
     * Returns a field by its handle
     *
     * @param string $handle
     * @return array|null
     */
    public function getFieldByHandle(string $handle): ?array
    {
        foreach ($this->getFields() as $field) {
            if (($field['handle'] ?? '') === $handle) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Returns whether the form has a specific field
     *
     * @param string $handle
     * @return bool
     */
    public function hasField(string $handle): bool
    {
        return $this->getFieldByHandle($handle) !== null;
    }

    /**
     * Returns a declared frontend (allowlisted) field definition by handle.
     */
    public function getFrontendFieldByHandle(string $handle): ?array
    {
        foreach ($this->getFrontendFields() as $field) {
            if (($field['handle'] ?? '') === $handle) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Returns a field definition (builder or frontend) by handle.
     */
    public function getAnyFieldByHandle(string $handle): ?array
    {
        return $this->getFieldByHandle($handle) ?? $this->getFrontendFieldByHandle($handle);
    }

    /**
     * Resolve the display label for a field handle (builder or frontend),
     * preferring the site-specific label, then the default, then the handle.
     */
    public function resolveFieldLabel(string $handle, ?string $siteHandle = null): string
    {
        $field = $this->getAnyFieldByHandle($handle);
        if (!$field) {
            return $handle;
        }
        if ($siteHandle && !empty($field['siteLabels'][$siteHandle])) {
            return $field['siteLabels'][$siteHandle];
        }
        return ($field['label'] ?? '') !== '' ? $field['label'] : $handle;
    }
}
