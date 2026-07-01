<?php

namespace yannkost\easyform\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;

/**
 * Submission model
 *
 * The `data` payload is stored as canonical JSON:
 *
 *     {
 *       "schemaVersion": 1,
 *       "values":   { handle: value, ... },   // form-builder fields
 *       "frontend": { handle: value, ... },   // allowlisted frontend fields
 *       "meta":     { ... }                    // diagnostic metadata
 *     }
 *
 * Legacy submissions were stored as a flat `{ handle: value }` map. The
 * accessors below transparently support both shapes so old submissions remain
 * readable after the upgrade.
 *
 * @property-read array $dataArray
 * @property-read array $values
 * @property-read array $frontendValues
 * @property-read array $flatValues
 * @property-read array $meta
 * @property-read array $fieldSnapshotArray
 */
class Submission extends Model
{
    public const SCHEMA_VERSION = 1;

    public ?int $id = null;
    public ?int $formId = null;
    public ?string $formHandle = null;
    public ?string $formName = null;
    public ?int $siteId = null;

    /** @var string|array Canonical submission payload */
    public string|array $data = [];

    /** @var string|array|null Field labels/types/order captured at submit time */
    public string|array|null $fieldSnapshot = null;

    public ?string $primaryEmail = null;
    public ?string $searchCol1 = null;
    public ?string $searchCol2 = null;
    public ?string $searchCol3 = null;

    public ?int $userId = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;

    public string $status = 'pending';
    public ?float $spamScore = null;
    public ?string $honeypotValue = null;
    public bool $isTest = false;

    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $dateDeleted = null;
    public ?string $uid = null;

    /**
     * @var Form|null Cached form instance
     */
    private ?Form $_form = null;

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'formId' => Craft::t('easy-form', 'Form'),
            'siteId' => Craft::t('easy-form', 'Site'),
            'data' => Craft::t('easy-form', 'Data'),
            'userId' => Craft::t('easy-form', 'User'),
            'ipAddress' => Craft::t('easy-form', 'IP Address'),
            'userAgent' => Craft::t('easy-form', 'User Agent'),
            'status' => Craft::t('easy-form', 'Status'),
            'spamScore' => Craft::t('easy-form', 'Spam Score'),
            'primaryEmail' => Craft::t('easy-form', 'Email'),
            'searchCol1' => Craft::t('easy-form', 'Search column 1'),
            'searchCol2' => Craft::t('easy-form', 'Search column 2'),
            'searchCol3' => Craft::t('easy-form', 'Search column 3'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['data'], 'required'],
            [['formId', 'siteId', 'userId'], 'integer'],
            [['primaryEmail', 'searchCol1', 'searchCol2', 'searchCol3', 'formHandle', 'formName'], 'string', 'max' => 255],
            ['ipAddress', 'string', 'max' => 45],
            ['userAgent', 'string'],
            ['status', 'in', 'range' => ['pending', 'approved', 'spam', 'archived']],
            ['spamScore', 'number', 'min' => 0, 'max' => 1],
            ['honeypotValue', 'string', 'max' => 255],
            ['isTest', 'boolean'],
        ];
    }

    /**
     * Returns the raw decoded submission payload (canonical or legacy flat).
     */
    public function getDataArray(): array
    {
        if (is_string($this->data)) {
            $decoded = Json::decodeIfJson($this->data);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($this->data) ? $this->data : [];
    }

    /**
     * Whether the payload uses the canonical wrapped shape.
     */
    public function isCanonical(): bool
    {
        $data = $this->getDataArray();
        return array_key_exists('values', $data) && array_key_exists('meta', $data);
    }

    /**
     * Form-builder field values.
     */
    public function getValues(): array
    {
        $data = $this->getDataArray();
        if ($this->isCanonical()) {
            return is_array($data['values'] ?? null) ? $data['values'] : [];
        }
        return $data;
    }

    /**
     * Allowlisted frontend field values.
     */
    public function getFrontendValues(): array
    {
        $data = $this->getDataArray();
        if ($this->isCanonical()) {
            return is_array($data['frontend'] ?? null) ? $data['frontend'] : [];
        }
        return [];
    }

    /**
     * Diagnostic metadata captured at submit time.
     */
    public function getMeta(): array
    {
        $data = $this->getDataArray();
        if ($this->isCanonical()) {
            return is_array($data['meta'] ?? null) ? $data['meta'] : [];
        }
        return [];
    }

    /**
     * A flat [handle => value] map across known and frontend values.
     * Convenient for display, export and notification placeholders.
     */
    public function getFlatValues(): array
    {
        return array_merge($this->getValues(), $this->getFrontendValues());
    }

    /**
     * Field snapshot (labels/types/order) captured at submit time.
     */
    public function getFieldSnapshotArray(): array
    {
        if ($this->fieldSnapshot === null) {
            return [];
        }
        if (is_string($this->fieldSnapshot)) {
            $decoded = Json::decodeIfJson($this->fieldSnapshot);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($this->fieldSnapshot) ? $this->fieldSnapshot : [];
    }

    /**
     * Gets a specific field value from the submission data.
     */
    public function getFieldValue(string $handle, mixed $default = null): mixed
    {
        $flat = $this->getFlatValues();
        return $flat[$handle] ?? $default;
    }

    /**
     * Sets a known field value, preserving the canonical shape when present.
     */
    public function setFieldValue(string $handle, mixed $value): void
    {
        $data = $this->getDataArray();
        if ($this->isCanonical()) {
            $data['values'][$handle] = $value;
        } else {
            $data[$handle] = $value;
        }
        $this->data = $data;
    }

    /**
     * Applies a batch of [handle => value] edits, updating each handle in the
     * bucket it already lives in (frontend vs values) so the canonical shape is
     * preserved. Only existing handles are updated — unknown handles and any
     * metadata are left untouched. Used by the CP submission editor.
     */
    public function applyFieldValueEdits(array $edits): void
    {
        $data = $this->getDataArray();
        if ($this->isCanonical()) {
            foreach ($edits as $handle => $value) {
                if (array_key_exists($handle, $data['frontend'] ?? [])) {
                    $data['frontend'][$handle] = $value;
                } else {
                    $data['values'][$handle] = $value;
                }
            }
        } else {
            foreach ($edits as $handle => $value) {
                $data[$handle] = $value;
            }
        }
        $this->data = $data;
    }

    /**
     * Returns the form this submission belongs to (null if deleted).
     */
    public function getForm(): ?Form
    {
        if ($this->_form === null && $this->formId) {
            $this->_form = \yannkost\easyform\EasyForm::getInstance()->forms->getFormById($this->formId);
        }
        return $this->_form;
    }

    /**
     * Sets the form.
     */
    public function setForm(Form $form): void
    {
        $this->_form = $form;
        $this->formId = $form->id;
    }

    /**
     * The form's display name, falling back to the stored snapshot when the
     * original form row no longer exists.
     */
    public function getDisplayFormName(): string
    {
        $form = $this->getForm();
        if ($form) {
            return $form->name;
        }
        return $this->formName ?? Craft::t('easy-form', 'Form deleted');
    }

    /**
     * Returns whether this submission is likely spam.
     */
    public function isSpam(): bool
    {
        return $this->status === 'spam' ||
               ($this->spamScore !== null && $this->spamScore > 0.5) ||
               !empty($this->honeypotValue);
    }

    public function markAsSpam(): void
    {
        $this->status = 'spam';
    }

    public function approve(): void
    {
        $this->status = 'approved';
    }

    public function archive(): void
    {
        $this->status = 'archived';
    }
}
