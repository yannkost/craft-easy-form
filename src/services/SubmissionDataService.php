<?php

namespace yannkost\easyform\services;

use yii\base\Component;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Form;
use yannkost\easyform\models\Submission;

/**
 * Submission Data Service
 *
 * Turns a raw submitted `fields` array into the canonical, operationally-safe
 * submission payload described in IMPLEMENTATION_PLAN.md:
 *
 *   - splits data into known / frontend / unknown
 *   - applies the form's extraFieldPolicy (strict | allowListed | open)
 *   - discards values for conditionally-hidden known fields
 *   - lightly validates/coerces frontend (allowlisted) field values
 *   - enforces global safety limits
 *   - extracts promoted metadata columns
 *   - builds a field snapshot for stable display/export
 *
 * Note: required/type validation of *known visible* fields is performed by
 * {@see ValidationService} before this service runs; here we only canonicalize.
 */
class SubmissionDataService extends Component
{
    /** Global safety limits (apply to frontend + unknown/open data). */
    public const MAX_FIELDS = 200;
    public const MAX_STRING_LENGTH = 50000;
    public const MAX_ARRAY_ITEMS = 1000;

    /**
     * Build the canonical payload for a submission.
     *
     * @param Form $form
     * @param array $rawFields Raw submitted fields (handle => value), including
     *                         resolved file upload values.
     * @param string[]|null $visibleHandles Visible known handles; computed if null.
     * @return array{
     *     data: array,
     *     fieldSnapshot: array,
     *     promoted: array{primaryEmail: ?string, searchCol1: ?string, searchCol2: ?string, searchCol3: ?string},
     *     frontendErrors: array<string, string[]>
     * }
     */
    public function build(Form $form, array $rawFields, ?array $visibleHandles = null, ?string $siteHandle = null): array
    {
        $schema = EasyForm::getInstance()->formSchema;
        $layout = $form->getNormalizedLayout();

        $knownHandles = $schema->getKnownHandles($layout);
        $frontendHandles = $schema->getFrontendHandles($layout);
        $policy = $schema->getPolicy($layout);

        if ($visibleHandles === null) {
            $visibleHandles = EasyForm::getInstance()->conditionEvaluator
                ->getVisibleFieldHandles($layout, $rawFields, $siteHandle);
        }

        $knownLookup = array_flip($knownHandles);
        $frontendLookup = array_flip($frontendHandles);
        $visibleLookup = array_flip($visibleHandles);

        $values = [];
        $frontendRaw = [];
        $unknown = [];

        foreach ($rawFields as $handle => $value) {
            if (!is_string($handle) && !is_int($handle)) {
                continue;
            }
            $handle = (string) $handle;

            if (isset($knownLookup[$handle])) {
                // Discard known-but-hidden fields (conditional or tampered).
                if (!isset($visibleLookup[$handle])) {
                    continue;
                }
                $values[$handle] = $this->normalizeValue($value);
            } elseif (isset($frontendLookup[$handle])) {
                $frontendRaw[$handle] = $value;
            } else {
                $unknown[$handle] = $value;
            }
        }

        // Apply the extra-field policy.
        $frontend = [];
        $frontendErrors = [];
        $acceptedUnknown = [];

        if ($policy !== FormSchemaService::POLICY_STRICT) {
            foreach ($frontendRaw as $handle => $value) {
                $def = $schema->getFrontendFieldByHandle($layout, $handle);
                [$coerced, $errors] = $this->coerceFrontendValue($value, $def ?? []);
                if (!empty($errors)) {
                    $frontendErrors[$handle] = $errors;
                }
                $frontend[$handle] = $coerced;
            }
        }

        if ($policy === FormSchemaService::POLICY_OPEN) {
            foreach ($unknown as $handle => $value) {
                $acceptedUnknown[$handle] = $this->applyLimits($this->normalizeValue($value));
            }
        }

        // Merge accepted unknown fields into the frontend bucket under "open".
        if (!empty($acceptedUnknown)) {
            $frontend = array_merge($frontend, $acceptedUnknown);
        }

        // Enforce the global field-count limit.
        if (count($values) + count($frontend) > self::MAX_FIELDS) {
            $frontend = array_slice($frontend, 0, max(0, self::MAX_FIELDS - count($values)), true);
        }

        $data = [
            'schemaVersion' => Submission::SCHEMA_VERSION,
            'values' => $values,
            'frontend' => $frontend,
            'meta' => [
                'formSchemaVersion' => $layout['schemaVersion'] ?? FormSchemaService::CURRENT_VERSION,
                'knownFieldHandles' => array_keys($values),
                'frontendFieldHandles' => array_keys($frontend),
                'visibleFieldHandles' => array_values($visibleHandles),
                'unknownFieldPolicy' => $policy,
            ],
        ];

        return [
            'data' => $data,
            'fieldSnapshot' => $this->buildSnapshot($form, $layout, $siteHandle),
            'promoted' => $this->extractPromoted($form, $layout, array_merge($values, $frontend)),
            'frontendErrors' => $frontendErrors,
        ];
    }

    /**
     * Normalize a raw value: trim scalars, reindex arrays, cap absurd sizes.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            // Strip dangerous control characters (keeps \t \n \r), then trim/cap.
            $value = Sanitization::removeControlChars($value);
            $value = trim($value);
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_STRING_LENGTH);
            }
            return $value;
        }

        if (is_array($value)) {
            $value = array_slice($value, 0, self::MAX_ARRAY_ITEMS);
            return array_map(fn($v) => $this->normalizeValue($v), $value);
        }

        return $value;
    }

    /**
     * Apply size limits to an already-normalized value (used for open fields).
     */
    private function applyLimits(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Lightly coerce/validate a frontend field value against its declared type.
     *
     * @return array{0: mixed, 1: string[]} [coercedValue, errors]
     */
    private function coerceFrontendValue(mixed $value, array $def): array
    {
        $type = $def['type'] ?? 'string';
        $multiple = (bool) ($def['multiple'] ?? false);
        $maxItems = $def['maxItems'] ?? null;
        $maxLength = $def['maxLength'] ?? null;
        $label = $def['label'] ?? ($def['handle'] ?? 'Field');
        $errors = [];

        // List values: explicit "array" type or any "multiple" field.
        $isListType = $multiple || $type === 'array';

        if ($isListType) {
            $items = is_array($value) ? array_values($value) : ($value === '' || $value === null ? [] : [$value]);
            if ($maxItems !== null && count($items) > $maxItems) {
                $errors[] = "{$label} accepts at most {$maxItems} items";
                $items = array_slice($items, 0, $maxItems);
            }
            if (count($items) > self::MAX_ARRAY_ITEMS) {
                $items = array_slice($items, 0, self::MAX_ARRAY_ITEMS);
            }
            $items = array_map(fn($v) => $this->normalizeValue($v), $items);
            return [$items, $errors];
        }

        switch ($type) {
            case 'number':
                if ($value !== '' && $value !== null && !is_numeric($value)) {
                    $errors[] = "{$label} must be a number";
                    return ['', $errors];
                }
                return [$value === '' || $value === null ? null : $value + 0, $errors];

            case 'boolean':
                return [filter_var($value, FILTER_VALIDATE_BOOLEAN), $errors];

            case 'string':
            default:
                // Scalars become strings; non-scalars (e.g. a nested map) are
                // JSON-encoded rather than lost, so structured data submitted to
                // a string field is preserved as JSON text.
                $str = is_array($value) ? (json_encode($value) ?: '') : (string) $value;
                $str = $this->normalizeValue($str);
                if ($maxLength !== null && mb_strlen($str) > $maxLength) {
                    $errors[] = "{$label} must be at most {$maxLength} characters";
                    $str = mb_substr($str, 0, $maxLength);
                }
                return [$str, $errors];
        }
    }

    /**
     * Build a snapshot of field labels/types/order for stable display & export.
     */
    public function buildSnapshot(Form $form, ?array $layout = null, ?string $siteHandle = null): array
    {
        $schema = EasyForm::getInstance()->formSchema;
        $layout = $layout ?? $form->getNormalizedLayout();

        // Resolve a field's label in the submission's site language.
        $label = static function (array $field) use ($siteHandle): string {
            if ($siteHandle && !empty($field['siteLabels'][$siteHandle])) {
                return $field['siteLabels'][$siteHandle];
            }
            return ($field['label'] ?? '') !== '' ? $field['label'] : ($field['handle'] ?? '');
        };

        $fields = [];
        // Value-bearing fields only — presentational ones carry no submitted
        // value, so they'd just be empty rows in the detail view / export.
        foreach ($schema->getValueFields($layout) as $field) {
            if (empty($field['handle'])) {
                continue;
            }
            $fields[] = [
                'handle' => $field['handle'],
                'label' => $label($field),
                'type' => $field['type'] ?? 'text',
                'source' => 'builder',
            ];
        }
        foreach ($schema->getFrontendFields($layout) as $field) {
            $fields[] = [
                'handle' => $field['handle'],
                'label' => $label($field),
                'type' => $field['type'] ?? 'string',
                'source' => 'frontend',
            ];
        }

        return [
            'formHandle' => $form->handle,
            'formName' => $form->name,
            'schemaVersion' => $layout['schemaVersion'] ?? FormSchemaService::CURRENT_VERSION,
            'fields' => $fields,
        ];
    }

    /**
     * Resolve promoted metadata column values from a layout's promoted-field map
     * and a flat [handle => value] payload. Public so the re-index backfill can
     * recompute these columns for existing submissions.
     *
     * @return array{primaryEmail: ?string, searchCol1: ?string, searchCol2: ?string, searchCol3: ?string}
     */
    public function promotedColumns(array $layout, array $flatValues): array
    {
        $promoted = ['primaryEmail' => null, 'searchCol1' => null, 'searchCol2' => null, 'searchCol3' => null];
        $map = EasyForm::getInstance()->formSchema->getPromotedFields($layout);

        foreach (['primaryEmail', 'searchCol1', 'searchCol2', 'searchCol3'] as $column) {
            $handle = $map[$column] ?? null;
            if ($handle && isset($flatValues[$handle])) {
                $value = $flatValues[$handle];
                if (is_array($value)) {
                    $value = reset($value);
                }
                $value = is_scalar($value) ? (string) $value : null;
                if ($value !== null) {
                    $promoted[$column] = mb_substr($value, 0, 255);
                }
            }
        }

        return $promoted;
    }

    /**
     * Resolve promoted metadata column values for a submission being saved.
     *
     * @return array{primaryEmail: ?string, searchCol1: ?string, searchCol2: ?string, searchCol3: ?string}
     */
    private function extractPromoted(Form $form, array $layout, array $flatValues): array
    {
        return $this->promotedColumns($layout, $flatValues);
    }
}
