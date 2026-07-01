<?php

namespace yannkost\easyform\services;

use yii\base\Component;
use yannkost\easyform\EasyForm;

/**
 * Condition Evaluator Service
 * 
 * Evaluates field and page visibility conditions against submitted data.
 * Used server-side by ValidationService to skip hidden fields,
 * and can be used anywhere conditions need to be resolved.
 * 
 * Condition structure:
 * [
 *     'action' => 'show' | 'hide',
 *     'logic'  => 'all' | 'any',
 *     'rules'  => [
 *         ['field' => 'handleName', 'operator' => 'equals', 'value' => 'someValue'],
 *     ]
 * ]
 */
class ConditionEvaluator extends Component
{
    /**
     * Supported operators
     */
    private const OPERATORS = [
        'equals',
        'notEquals',
        'contains',
        'notContains',
        'isEmpty',
        'isNotEmpty',
    ];

    /**
     * Determine which field handles are visible given the full layout and submitted data.
     *
     * @param array $layout Normalized (v2) fieldLayout
     * @param array $submissionData Flat [handle => value] submission data
     * @return array List of visible field handles
     */
    public function getVisibleFieldHandles(array $layout, array $submissionData, ?string $siteHandle = null): array
    {
        $visibleHandles = [];
        $pages = $layout['pages'] ?? [];

        foreach ($pages as $page) {
            // A page disabled on the current site removes all its rows/fields
            // (per-site structural localization), same as a condition-hidden page.
            if (!$this->isSiteEnabled($page, $siteHandle)) {
                continue;
            }

            // Check page-level conditions
            if (!$this->isVisible($page, $submissionData, $siteHandle)) {
                continue; // Entire page is hidden — skip all its fields
            }

            foreach ($page['rows'] ?? [] as $row) {
                // A row disabled on the current site hides all its fields, the
                // same as a condition-hidden row (per-site structural localization).
                if (!$this->isSiteEnabled($row, $siteHandle)) {
                    continue;
                }

                // Check row-level conditions — a hidden row hides all its fields.
                if (!$this->isVisible($row, $submissionData, $siteHandle)) {
                    continue;
                }

                foreach ($row['fields'] ?? [] as $field) {
                    $handle = $field['handle'] ?? '';
                    if (empty($handle)) {
                        continue;
                    }

                    // A field disabled on the current site is treated as not-visible,
                    // so it's excluded from validation, required checks and the stored
                    // payload exactly like a condition-hidden field.
                    if (!$this->isSiteEnabled($field, $siteHandle)) {
                        continue;
                    }

                    if ($this->isVisible($field, $submissionData, $siteHandle)) {
                        $visibleHandles[] = $handle;
                    }
                }
            }
        }

        return $visibleHandles;
    }

    /**
     * Whether a field or row is enabled on the given site.
     *
     * Mirrors the per-site `siteEnabled` map used by notification settings: an
     * absent map — or an absent/truthy entry for this site — means "enabled",
     * so forms saved before this feature default to enabled on every site. A
     * null $siteHandle (no site context) is always treated as enabled.
     *
     * @param array $item A field or row definition that may carry a 'siteEnabled' map
     * @param string|null $siteHandle The current site handle, or null for no site context
     * @return bool
     */
    public function isSiteEnabled(array $item, ?string $siteHandle): bool
    {
        if ($siteHandle === null) {
            return true;
        }

        return !empty($item['siteEnabled'][$siteHandle] ?? '1');
    }

    /**
     * Check whether a field or page is visible based on its conditions.
     * Items without conditions are always visible.
     *
     * @param array $item A field or page definition that may contain a 'conditions' key
     * @param array $submissionData Flat [handle => value] data
     * @return bool
     */
    public function isVisible(array $item, array $submissionData, ?string $siteHandle = null): bool
    {
        $conditions = $item['conditions'] ?? null;

        // No conditions = always visible
        if (empty($conditions) || empty($conditions['rules'])) {
            return true;
        }

        // Drop rules scoped to a different site. If none remain, the condition
        // imposes no constraint on this site → always visible (regardless of
        // show/hide), matching the front end which renders no rules at all.
        $rules = array_values(array_filter(
            $conditions['rules'],
            fn($rule) => $this->ruleAppliesToSite($rule, $siteHandle)
        ));
        if (empty($rules)) {
            return true;
        }

        $action = $conditions['action'] ?? 'show'; // 'show' or 'hide'
        $logic = $conditions['logic'] ?? 'all';     // 'all' (AND) or 'any' (OR)

        $rulesMatch = $this->evaluateRules($rules, $logic, $submissionData);

        // action=show: visible when rules match, hidden when they don't
        // action=hide: hidden when rules match, visible when they don't
        if ($action === 'show') {
            return $rulesMatch;
        }

        return !$rulesMatch;
    }

    /**
     * Whether a rule applies on the given site. A rule is scoped to 'all'
     * sites (the default) or one site handle. With no site context
     * ($siteHandle === null) scoping is ignored so every rule applies.
     */
    private function ruleAppliesToSite(array $rule, ?string $siteHandle): bool
    {
        if ($siteHandle === null) {
            return true;
        }
        $site = $rule['site'] ?? 'all';
        return $site === 'all' || $site === $siteHandle;
    }

    /**
     * Evaluate a set of rules against submission data.
     *
     * @param array $rules
     * @param string $logic 'all' or 'any'
     * @param array $submissionData
     * @return bool
     */
    private function evaluateRules(array $rules, string $logic, array $submissionData): bool
    {
        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $submissionData);

            if ($logic === 'any' && $result) {
                return true; // Short-circuit: one match is enough
            }

            if ($logic === 'all' && !$result) {
                return false; // Short-circuit: one failure is enough
            }
        }

        // 'all' logic: all passed → true. 'any' logic: none passed → false.
        return $logic === 'all';
    }

    /**
     * Evaluate a single rule against submission data.
     *
     * @param array $rule ['field' => string, 'operator' => string, 'value' => mixed]
     * @param array $submissionData
     * @return bool
     */
    private function evaluateRule(array $rule, array $submissionData): bool
    {
        $fieldHandle = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? 'equals';
        $expectedValue = $rule['value'] ?? '';

        $actualValue = $submissionData[$fieldHandle] ?? '';

        switch ($operator) {
            case 'equals':
                if (is_array($actualValue)) {
                    return in_array((string) $expectedValue, array_map('strval', $actualValue), true);
                }
                return (string) $actualValue === (string) $expectedValue;

            case 'notEquals':
                if (is_array($actualValue)) {
                    return !in_array((string) $expectedValue, array_map('strval', $actualValue), true);
                }
                return (string) $actualValue !== (string) $expectedValue;

            case 'contains':
                if (is_array($actualValue)) {
                    return in_array((string) $expectedValue, array_map('strval', $actualValue), true);
                }
                return str_contains((string) $actualValue, (string) $expectedValue);

            case 'notContains':
                if (is_array($actualValue)) {
                    return !in_array((string) $expectedValue, array_map('strval', $actualValue), true);
                }
                return !str_contains((string) $actualValue, (string) $expectedValue);

            case 'isEmpty':
                if (is_array($actualValue)) {
                    return empty($actualValue);
                }
                return trim((string) $actualValue) === '';

            case 'isNotEmpty':
                if (is_array($actualValue)) {
                    return !empty($actualValue);
                }
                return trim((string) $actualValue) !== '';

            default:
                EasyForm::log("Unknown condition operator: {$operator}", 'warning');
                return false;
        }
    }
}
