<?php

namespace yannkost\easyform\services;

use yii\base\Component;
use yannkost\easyform\EasyForm;

/**
 * Form Schema Service
 *
 * Handles versioning and migration of the fieldLayout JSON structure.
 * All schema shape knowledge is centralized here.
 *
 * v3 top-level shape:
 *
 *     {
 *       "schemaVersion": 3,
 *       "extraFieldPolicy": "allowListed",   // strict | allowListed | open
 *       "pages": [ ... ],
 *       "frontendFields": [ ... ],            // allowlisted, frontend-modeled fields
 *       "promotedFields": {                   // map field handle -> searchable column
 *         "primaryEmail": "email",            // email column (search + privacy lookups)
 *         "searchCol1": "fullName",           // three arbitrary, searchable columns
 *         "searchCol2": "utm_source",
 *         "searchCol3": "plan"
 *       }
 *     }
 */
class FormSchemaService extends Component
{
    /**
     * Current schema version
     */
    public const CURRENT_VERSION = 3;

    /**
     * Extra field policies
     */
    public const POLICY_STRICT = 'strict';
    public const POLICY_ALLOWLISTED = 'allowListed';
    public const POLICY_OPEN = 'open';

    public const POLICIES = [self::POLICY_STRICT, self::POLICY_ALLOWLISTED, self::POLICY_OPEN];

    public const DEFAULT_POLICY = self::POLICY_ALLOWLISTED;

    /**
     * Supported frontend field types — deliberately broad primitives only.
     *
     * We intentionally do NOT model element relations (entryId/assetId/…) or a
     * structured "object/json" type: the plugin can't guarantee referenced
     * elements exist, and structured data is trivially handled client-side with
     * JSON.stringify() into a `string`. A `string` field auto-encodes any
     * non-scalar value to JSON, so structured data is never silently lost.
     */
    public const FRONTEND_TYPES = [
        'string', 'number', 'boolean', 'array',
    ];

    /**
     * Canonical form-builder field type aliases (alias => canonical).
     */
    private const TYPE_ALIASES = [
        'phone' => 'tel',
    ];

    /**
     * Normalize a fieldLayout to the latest schema version.
     * Safe to call on any version — detects and upgrades as needed.
     *
     * @param array $layout The raw fieldLayout array
     * @return array The normalized layout at CURRENT_VERSION
     */
    public function normalize(array $layout): array
    {
        $version = $this->detectVersion($layout);

        while ($version < self::CURRENT_VERSION) {
            $method = 'migrateV' . $version . 'ToV' . ($version + 1);
            if (!method_exists($this, $method)) {
                EasyForm::debug("No migration method {$method} found, stopping at v{$version}", 'warning');
                break;
            }
            $layout = $this->$method($layout);
            $version++;
        }

        return $this->migratePromotedKeys($this->normalizeFieldTypes($layout));
    }

    /**
     * Migrate legacy promoted-field map keys to the generic search-column model:
     * `primaryName` → `searchCol1`, `source` → `searchCol2`. `primaryEmail` (the
     * email column, used for search + privacy lookups) is unchanged. Lets forms
     * saved before the rename keep working without being re-saved.
     */
    private function migratePromotedKeys(array $layout): array
    {
        $promoted = $layout['promotedFields'] ?? null;
        if (!is_array($promoted)) {
            return $layout;
        }
        foreach (['primaryName' => 'searchCol1', 'source' => 'searchCol2'] as $old => $new) {
            if (array_key_exists($old, $promoted)) {
                if (!array_key_exists($new, $promoted) || ($promoted[$new] ?? '') === '') {
                    $promoted[$new] = $promoted[$old];
                }
                unset($promoted[$old]);
            }
        }
        $layout['promotedFields'] = $promoted;
        return $layout;
    }

    /**
     * Detect the schema version of a fieldLayout array.
     */
    public function detectVersion(array $layout): int
    {
        if (isset($layout['schemaVersion'])) {
            return (int) $layout['schemaVersion'];
        }

        // v1: top-level 'rows' or 'fields', no 'pages'
        if (isset($layout['rows']) || isset($layout['fields'])) {
            return 1;
        }

        // Unknown/empty — treat as v2 so it gets upgraded to current.
        return 2;
    }

    /**
     * Migrate v1 → v2
     */
    private function migrateV1ToV2(array $layout): array
    {
        if (isset($layout['fields']) && !isset($layout['rows'])) {
            $layout = [
                'rows' => [
                    [
                        'id' => 'row_1',
                        'stackOnMobile' => true,
                        'fields' => $layout['fields'],
                    ],
                ],
            ];
        }

        $rows = $layout['rows'] ?? [];

        return [
            'schemaVersion' => 2,
            'pages' => [
                [
                    'id' => 'page_1',
                    'label' => 'Page 1',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Migrate v2 → v3: introduce the frontend allowlist contract.
     */
    private function migrateV2ToV3(array $layout): array
    {
        $layout['schemaVersion'] = 3;
        $layout['extraFieldPolicy'] = $this->normalizePolicy($layout['extraFieldPolicy'] ?? null);
        $layout['frontendFields'] = $this->normalizeFrontendFields($layout['frontendFields'] ?? []);
        $layout['promotedFields'] = is_array($layout['promotedFields'] ?? null) ? $layout['promotedFields'] : [];
        $layout['pages'] = $layout['pages'] ?? [];

        return $layout;
    }

    /**
     * Create an empty layout at the current schema version.
     */
    public function createEmptyLayout(): array
    {
        return [
            'schemaVersion' => self::CURRENT_VERSION,
            'extraFieldPolicy' => self::DEFAULT_POLICY,
            'pages' => [
                [
                    'id' => 'page_1',
                    'label' => 'Page 1',
                    'rows' => [],
                ],
            ],
            'frontendFields' => [],
            'promotedFields' => [],
        ];
    }

    /**
     * Presentational (render-only) field types. They carry no submitted value,
     * so they're excluded from value-bearing contexts (export columns, the
     * submission snapshot/detail). Mirrors the PRESENTATIONAL list in the
     * builder's modules/field-manager.js.
     */
    public const PRESENTATIONAL_TYPES = ['heading', 'divider', 'callout', 'paragraph'];

    /**
     * Whether a field type is presentational (render-only, valueless).
     */
    public function isPresentationalType(string $type): bool
    {
        return in_array($type, self::PRESENTATIONAL_TYPES, true);
    }

    /**
     * Extract all form-builder fields from a normalized layout.
     */
    public function getAllFields(array $layout): array
    {
        $allFields = [];
        foreach ($layout['pages'] ?? [] as $page) {
            foreach ($page['rows'] ?? [] as $row) {
                foreach ($row['fields'] ?? [] as $field) {
                    $allFields[] = $field;
                }
            }
        }
        return $allFields;
    }

    /**
     * Form-builder fields that carry a submitted value — i.e. all fields minus
     * the presentational (heading/divider/callout/paragraph) ones. Use this for
     * export columns, the submission snapshot and the detail view, so those
     * surfaces aren't padded with empty columns/rows.
     */
    public function getValueFields(array $layout): array
    {
        return array_values(array_filter(
            $this->getAllFields($layout),
            fn($field) => !$this->isPresentationalType($field['type'] ?? 'text')
        ));
    }

    /**
     * Extract all pages from a normalized layout.
     */
    public function getPages(array $layout): array
    {
        return $layout['pages'] ?? [];
    }

    /**
     * The extra-field policy for a layout.
     */
    public function getPolicy(array $layout): string
    {
        return $this->normalizePolicy($layout['extraFieldPolicy'] ?? null);
    }

    /**
     * Declared frontend (allowlisted) field definitions.
     */
    public function getFrontendFields(array $layout): array
    {
        return $this->normalizeFrontendFields($layout['frontendFields'] ?? []);
    }

    /**
     * Promoted field map (metadata column => source handle).
     */
    public function getPromotedFields(array $layout): array
    {
        $promoted = $layout['promotedFields'] ?? [];
        return is_array($promoted) ? $promoted : [];
    }

    /**
     * Handles of all form-builder fields.
     *
     * @return string[]
     */
    public function getKnownHandles(array $layout): array
    {
        $handles = [];
        foreach ($this->getAllFields($layout) as $field) {
            if (!empty($field['handle'])) {
                $handles[] = $field['handle'];
            }
        }
        return $handles;
    }

    /**
     * Handles of declared frontend fields.
     *
     * @return string[]
     */
    public function getFrontendHandles(array $layout): array
    {
        $handles = [];
        foreach ($this->getFrontendFields($layout) as $field) {
            if (!empty($field['handle'])) {
                $handles[] = $field['handle'];
            }
        }
        return $handles;
    }

    /**
     * All handles accepted by the form (known + frontend).
     *
     * @return string[]
     */
    public function getAcceptedHandles(array $layout): array
    {
        return array_values(array_unique(array_merge(
            $this->getKnownHandles($layout),
            $this->getFrontendHandles($layout)
        )));
    }

    /**
     * Find a frontend field definition by handle.
     */
    public function getFrontendFieldByHandle(array $layout, string $handle): ?array
    {
        foreach ($this->getFrontendFields($layout) as $field) {
            if (($field['handle'] ?? '') === $handle) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Normalize a policy string, falling back to the default.
     */
    public function normalizePolicy(?string $policy): string
    {
        return in_array($policy, self::POLICIES, true) ? $policy : self::DEFAULT_POLICY;
    }

    /**
     * Canonicalize a single form-builder field type (e.g. phone => tel).
     */
    public function normalizeFieldType(string $type): string
    {
        return self::TYPE_ALIASES[$type] ?? $type;
    }

    /**
     * Walk a normalized layout and canonicalize field type aliases in place.
     */
    private function normalizeFieldTypes(array $layout): array
    {
        if (empty($layout['pages'])) {
            return $layout;
        }

        foreach ($layout['pages'] as &$page) {
            if (!isset($page['rows']) || !is_array($page['rows'])) {
                continue;
            }
            foreach ($page['rows'] as &$row) {
                if (!isset($row['fields']) || !is_array($row['fields'])) {
                    continue;
                }
                foreach ($row['fields'] as &$field) {
                    if (isset($field['type'])) {
                        $field['type'] = $this->normalizeFieldType($field['type']);
                    }
                }
                unset($field);
            }
            unset($row);
        }
        unset($page);

        return $layout;
    }

    /**
     * Normalize a list of frontend field definitions to a predictable shape.
     */
    private function normalizeFrontendFields($fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['handle'])) {
                continue;
            }

            // Unknown/removed types (object, json, entry/asset ids, …) fall back
            // to string, which auto-encodes non-scalar values to JSON.
            $type = $field['type'] ?? 'string';
            if (!in_array($type, self::FRONTEND_TYPES, true)) {
                $type = 'string';
            }

            $maxItems = isset($field['maxItems']) && (int) $field['maxItems'] > 0 ? (int) $field['maxItems'] : null;
            $maxLength = isset($field['maxLength']) && (int) $field['maxLength'] > 0 ? (int) $field['maxLength'] : null;

            $normalized[] = [
                'handle' => (string) $field['handle'],
                'label' => ($field['label'] ?? '') !== '' ? $field['label'] : $field['handle'],
                'siteLabels' => is_array($field['siteLabels'] ?? null) ? array_filter($field['siteLabels'], fn($v) => $v !== '' && $v !== null) : [],
                'type' => $type,
                'multiple' => (bool) ($field['multiple'] ?? false),
                'required' => (bool) ($field['required'] ?? false),
                'maxItems' => $maxItems,
                'maxLength' => $maxLength,
                'export' => (bool) ($field['export'] ?? true),
                'notifications' => (bool) ($field['notifications'] ?? true),
                'display' => is_array($field['display'] ?? null) ? $field['display'] : [],
                'constraints' => is_array($field['constraints'] ?? null) ? $field['constraints'] : [],
            ];
        }

        return $normalized;
    }
}
