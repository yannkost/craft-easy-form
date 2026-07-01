<?php

namespace yannkost\easyform\services;

use Craft;
use yii\base\Component;
use yannkost\easyform\EasyForm;

/**
 * Validation Service
 * 
 * Validates form field values against their type and settings
 */
class ValidationService extends Component
{
    /**
     * Validate all fields in a submission
     *
     * @param array $fieldData The submitted field data
     * @param array $fieldLayout The form's field layout
     * @return array Array of validation errors (empty if valid)
     */
    public function validateSubmission(array $fieldData, array $fieldLayout, ?array $visibleHandles = null, ?string $siteHandle = null): array
    {
        $errors = [];

        // Normalize layout to latest schema version
        $schemaService = EasyForm::getInstance()->formSchema;
        $normalized = $schemaService->normalize($fieldLayout);

        // Determine which fields are visible (condition-aware), unless provided.
        if ($visibleHandles === null) {
            $visibleHandles = EasyForm::getInstance()->conditionEvaluator
                ->getVisibleFieldHandles($normalized, $fieldData, $siteHandle);
        }

        // Iterate all pages → rows → fields, but only validate visible ones
        $pages = $normalized['pages'] ?? [];

        foreach ($pages as $page) {
            foreach ($page['rows'] ?? [] as $row) {
                foreach ($row['fields'] ?? [] as $field) {
                    $handle = $field['handle'] ?? '';

                    // Skip fields that are conditionally hidden
                    if (!in_array($handle, $visibleHandles)) {
                        continue;
                    }

                    $value = $fieldData[$handle] ?? '';

                    // Validate this field
                    $fieldErrors = $this->validateField($value, $field);

                    if (!empty($fieldErrors)) {
                        $errors[$handle] = $fieldErrors;
                    }
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate a single field value
     *
     * @param mixed $value The field value
     * @param array $field The field configuration
     * @return array Array of error messages
     */
    public function validateField($value, array $field): array
    {
        $errors = [];
        $fieldType = $field['type'] ?? 'text';
        $label = $field['label'] ?? 'This field';
        $handle = $field['handle'] ?? '';
        
        // Special handling for file uploads
        if ($fieldType === 'file') {
            return $this->validateFileField($handle, $field, $label);
        }

        // Agree is a consent checkbox: "required" means it must be checked, i.e.
        // the submitted value must equal the site's resolved "checked" value.
        // (An unchecked box still submits the "unchecked" value, so the generic
        // empty-check below wouldn't catch a non-consent.)
        if ($fieldType === 'agree') {
            return $this->validateAgreeField($value, $field);
        }

        // Required validation
        if (($field['required'] ?? false) && $this->isEmpty($value)) {
            $currentSiteHandle = trim(Craft::$app->sites->getCurrentSite()->handle);
            $customMessage = $field['siteRequiredMessages'][$currentSiteHandle] ?? null;
            $errors[] = $customMessage ?: Craft::t('easy-form', '{label} is required', ['label' => $label]);
            return $errors; // Don't validate further if required field is empty
        }
        
        // Skip further validation if field is empty and not required
        if ($this->isEmpty($value)) {
            return $errors;
        }
        
        // Type-specific validation
        switch ($fieldType) {
            case 'email':
                // Blocked-domain handling lives in SubmissionsController (silent
                // spam by default), so this only checks address format.
                if (!$this->validateEmail($value)) {
                    $errors[] = $this->siteMessage($field, 'siteInvalidMessages')
                        ?: Craft::t('easy-form', '{label} must be a valid email address', ['label' => $label]);
                }
                break;
                
            case 'tel':
            case 'phone':
                if (!$this->validatePhone($value)) {
                    $errors[] = Craft::t('easy-form', '{label} must be a valid phone number', ['label' => $label]);
                }
                // tel exposes Min/Max length in the builder, so enforce it here too
                // (the browser attributes alone are bypassable by a crafted POST).
                $errors = array_merge($errors, $this->validateLength((string) $value, $field, $label));
                break;

            case 'url':
                $requireScheme = (bool) ($field['requireScheme'] ?? false);
                $hasScheme = preg_match('~^https?://~i', (string) $value) === 1;
                // When a scheme isn't required, accept "example.com" by checking a
                // normalized copy; the stored value stays exactly as entered.
                $check = (!$requireScheme && !$hasScheme) ? 'https://' . $value : $value;
                if (!$this->validateUrl($check)) {
                    $errors[] = Craft::t('easy-form', '{label} must be a valid URL', ['label' => $label]);
                } elseif ($requireScheme && !$hasScheme) {
                    $errors[] = Craft::t('easy-form', '{label} must start with http:// or https://', ['label' => $label]);
                }
                // url also exposes Min/Max length in the builder — enforce server-side.
                $errors = array_merge($errors, $this->validateLength((string) $value, $field, $label));
                break;
                
            case 'number':
                $numberErrors = $this->validateNumber($value, $field, $label);
                $errors = array_merge($errors, $numberErrors);
                break;

            case 'date':
                // The date input posts strict YYYY-MM-DD; don't accept the many
                // loose formats strtotime() would.
                if (!$this->isValidIsoDate((string) $value)) {
                    $errors[] = Craft::t('easy-form', '{label} must be a valid date', ['label' => $label]);
                }
                break;

            case 'datetime':
                // <input type="datetime-local"> posts YYYY-MM-DDTHH:MM (seconds optional).
                if (!$this->matchesAnyFormat((string) $value, ['Y-m-d\TH:i', 'Y-m-d\TH:i:s'])) {
                    $errors[] = Craft::t('easy-form', '{label} must be a valid date and time', ['label' => $label]);
                }
                break;

            case 'time':
                // <input type="time"> posts HH:MM (seconds optional).
                if (!$this->matchesAnyFormat((string) $value, ['H:i', 'H:i:s'])) {
                    $errors[] = Craft::t('easy-form', '{label} must be a valid time', ['label' => $label]);
                }
                break;

            case 'text':
            case 'textarea':
                $lengthErrors = $this->validateLength($value, $field, $label);
                $errors = array_merge($errors, $lengthErrors);
                break;

            case 'select':
            case 'radio':
            case 'checkboxes':
                $errors = array_merge($errors, $this->validateOptions($value, $field, $label));
                break;
        }

        return $errors;
    }

    /**
     * Validate a consent (agree) checkbox. When required, the submitted value must
     * equal the site's resolved "checked" value — otherwise the box wasn't ticked.
     */
    private function validateAgreeField($value, array $field): array
    {
        if (!($field['required'] ?? false)) {
            return [];
        }
        $siteHandle = trim(Craft::$app->sites->getCurrentSite()->handle);
        if ((string) $value !== $this->resolveAgreeChecked($field, $siteHandle)) {
            $custom = $field['siteRequiredMessages'][$siteHandle] ?? null;
            return [$custom ?: Craft::t('easy-form', 'You must agree to the terms')];
        }
        return [];
    }

    /**
     * The "checked" value for an agree field on a site. Mirrors the render
     * fallback in _fields/agree.twig: this site → primary site → "Yes".
     */
    private function resolveAgreeChecked(array $field, string $siteHandle): string
    {
        $map = is_array($field['siteAgreeChecked'] ?? null) ? $field['siteAgreeChecked'] : [];
        $primary = trim(Craft::$app->sites->getPrimarySite()->handle);
        $candidates = [$map[$siteHandle] ?? '', $map[$primary] ?? '', 'Yes'];
        foreach ($candidates as $c) {
            if (trim((string) $c) !== '') {
                return trim((string) $c);
            }
        }
        return 'Yes';
    }

    /**
     * Resolve an admin-defined, per-site validation message for the current site.
     * Returns null when none is set, so callers can fall back to a default.
     *
     * @param array $field The field configuration
     * @param string $key The per-site message map key (e.g. 'siteMinMessages')
     * @return string|null
     */
    private function siteMessage(array $field, string $key): ?string
    {
        $siteHandle = trim(Craft::$app->sites->getCurrentSite()->handle);
        $message = $field[$key][$siteHandle] ?? null;
        return ($message !== null && trim((string) $message) !== '') ? (string) $message : null;
    }

    /**
     * Check if a value is empty
     *
     * @param mixed $value
     * @return bool
     */
    private function isEmpty($value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }
        
        return empty($value);
    }
    
    /**
     * Validate email format
     *
     * @param string $value
     * @return bool
     */
    private function validateEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number format
     *
     * @param string $value
     * @return bool
     */
    private function validatePhone(string $value): bool
    {
        // Phone numbers are wildly locale-specific, so we only sanity-check rather
        // than enforce a grammar (a strict pattern rejects many valid numbers). We
        // allow digits and the usual separators, and require 6–25 characters with
        // at least 6 digits. For strict/international validation, authors can wire a
        // library such as intl-tel-input to a custom frontend field.
        if (!preg_match('/^[+]?[0-9\s().\-]{6,25}$/', $value)) {
            return false;
        }
        return preg_match_all('/[0-9]/', $value) >= 6;
    }
    
    /**
     * Validate URL format
     *
     * @param string $value
     * @return bool
     */
    private function validateUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Validate number value and constraints
     *
     * @param mixed $value
     * @param array $field
     * @param string $label
     * @return array
     */
    private function validateNumber($value, array $field, string $label): array
    {
        $errors = [];
        
        // Check if it's a valid number
        if (!is_numeric($value)) {
            $errors[] = Craft::t('easy-form', '{label} must be a valid number', ['label' => $label]);
            return $errors;
        }
        
        $numValue = (float)$value;

        // Decimal-places constraint. Blank = unrestricted; 0 = whole numbers only.
        $decimals = $field['decimals'] ?? '';
        if ($decimals !== '' && $decimals !== null && is_numeric($decimals)) {
            $decimals = (int) $decimals;
            $actual = strpos((string) $value, '.') !== false
                ? strlen(substr(strrchr((string) $value, '.'), 1))
                : 0;
            if ($actual > $decimals) {
                $errors[] = $decimals === 0
                    ? Craft::t('easy-form', '{label} must be a whole number', ['label' => $label])
                    : Craft::t('easy-form', '{label} must have no more than {n} decimal place(s)', ['label' => $label, 'n' => $decimals]);
            }
        }

        // Min value validation — only when a real constraint is configured.
        // A blank/empty constraint ('' or null) must NOT behave as 0.
        $min = $field['min'] ?? null;
        if ($min !== null && $min !== '' && is_numeric($min) && $numValue < (float)$min) {
            $errors[] = $this->siteMessage($field, 'siteMinMessages')
                ?: Craft::t('easy-form', '{label} must be at least {min}', ['label' => $label, 'min' => $min]);
        }

        // Max value validation — same blank-handling as min.
        $max = $field['max'] ?? null;
        if ($max !== null && $max !== '' && is_numeric($max) && $numValue > (float)$max) {
            $errors[] = $this->siteMessage($field, 'siteMaxMessages')
                ?: Craft::t('easy-form', '{label} must be no more than {max}', ['label' => $label, 'max' => $max]);
        }

        return $errors;
    }

    /**
     * Strict YYYY-MM-DD date check (matches the rendered <input type="date">).
     */
    private function isValidIsoDate(string $value): bool
    {
        return $this->matchesAnyFormat($value, ['Y-m-d']);
    }

    /**
     * True when $value parses exactly under at least one of the given strict
     * DateTime formats (round-trips back to the same string), so loose formats
     * strtotime() would accept are rejected.
     *
     * @param string $value
     * @param string[] $formats
     */
    private function matchesAnyFormat(string $value, array $formats): bool
    {
        foreach ($formats as $format) {
            $d = \DateTime::createFromFormat($format, $value);
            if ($d !== false && $d->format($format) === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate option-based fields (select / radio / checkboxes) against their
     * configured options, so a tampered direct POST can't store arbitrary values.
     */
    private function validateOptions($value, array $field, string $label): array
    {
        $errors = [];

        $allowed = $this->allowedOptionValues($field);
        if (empty($allowed)) {
            // No options configured → nothing to enforce.
            return $errors;
        }

        $multiple = !empty($field['multiple']);

        if (is_array($value)) {
            if (!$multiple) {
                $errors[] = Craft::t('easy-form', '{label} is invalid', ['label' => $label]);
                return $errors;
            }
            foreach ($value as $item) {
                if (!in_array((string) $item, $allowed, true)) {
                    $errors[] = Craft::t('easy-form', '{label} contains an invalid selection', ['label' => $label]);
                    break;
                }
            }
        } else {
            if (!in_array((string) $value, $allowed, true)) {
                $errors[] = Craft::t('easy-form', '{label} is invalid', ['label' => $label]);
            }
        }

        return $errors;
    }

    /**
     * Resolve the allowed option values for the current site. Options are stored
     * as "value:label" (or just "value") lines, per site with a base fallback.
     */
    private function allowedOptionValues(array $field): array
    {
        // Prefer this site's options, then the primary site's (where the builder
        // stores the base list), then the field's `options` — matching the
        // select/checkboxes render fallback so validation never rejects values
        // an untranslated secondary site legitimately shows.
        $siteOptionsMap = is_array($field['siteOptions'] ?? null) ? $field['siteOptions'] : [];
        $siteHandle = trim(Craft::$app->sites->getCurrentSite()->handle);
        $raw = $siteOptionsMap[$siteHandle] ?? '';
        if (trim((string) $raw) === '') {
            $primaryHandle = trim(Craft::$app->sites->getPrimarySite()->handle);
            $raw = $siteOptionsMap[$primaryHandle] ?? '';
        }
        if (trim((string) $raw) === '') {
            $raw = $field['options'] ?? '';
        }

        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Split on the first UNescaped colon; "\:" is a literal colon in the value.
            $parts = preg_split('/(?<!\\\\):/', $line, 2);
            $optValue = str_replace('\\:', ':', trim($parts[0]));
            if ($optValue !== '') {
                $values[] = $optValue;
            }
        }

        return $values;
    }

    /**
     * Validate string length constraints
     *
     * @param string $value
     * @param array $field
     * @param string $label
     * @return array
     */
    private function validateLength(string $value, array $field, string $label): array
    {
        $errors = [];
        $length = mb_strlen($value);
        
        // Min length validation
        if (isset($field['minLength']) && $field['minLength'] > 0 && $length < (int)$field['minLength']) {
            $errors[] = $this->siteMessage($field, 'siteMinLengthMessages')
                ?: Craft::t('easy-form', '{label} must be at least {n} characters', ['label' => $label, 'n' => (int) $field['minLength']]);
        }

        // Max length validation
        if (isset($field['maxLength']) && $field['maxLength'] > 0 && $length > (int)$field['maxLength']) {
            $errors[] = $this->siteMessage($field, 'siteMaxLengthMessages')
                ?: Craft::t('easy-form', '{label} must be no more than {n} characters', ['label' => $label, 'n' => (int) $field['maxLength']]);
        }
        
        return $errors;
    }
    
    /**
     * Validate file upload field
     *
     * @param string $handle Field handle
     * @param array $field Field configuration
     * @param string $label Field label
     * @return array Error messages
     */
    private function validateFileField(string $handle, array $field, string $label): array
    {
        $errors = [];

        // Resolve uploaded files via Craft's normalized API (handles both
        // `fields[handle]` and `fields[handle][]` and avoids $_FILES parsing).
        $files = $this->getUploadedFiles($handle);
        $hasFiles = !empty($files);

        // Required validation
        if (($field['required'] ?? false) && !$hasFiles) {
            $currentSiteHandle = trim(Craft::$app->sites->getCurrentSite()->handle);
            $customMessage = $field['siteRequiredMessages'][$currentSiteHandle] ?? null;
            $errors[] = $customMessage ?: Craft::t('easy-form', '{label} is required', ['label' => $label]);
            return $errors;
        }

        if (!$hasFiles) {
            return $errors;
        }

        // Cap the number of uploaded files (matters when "Allow Multiple" is on).
        $maxFiles = $field['maxFiles'] ?? null;
        if (!empty($maxFiles) && is_numeric($maxFiles) && count($files) > (int) $maxFiles) {
            $errors[] = Craft::t('easy-form', '{label}: upload no more than {n} file(s)', ['label' => $label, 'n' => (int) $maxFiles]);
        }

        // Resolve max size (MB), falling back to the global setting.
        $maxFileSize = $field['maxFileSize'] ?? null;
        if (empty($maxFileSize) || !is_numeric($maxFileSize)) {
            $maxFileSize = EasyForm::getInstance()->getSettings()->maxFileSize;
        }
        $maxSizeBytes = (float) $maxFileSize * 1024 * 1024;

        // Resolve combined (total) max size (MB), falling back to the global default.
        $maxTotalSize = $field['maxTotalSize'] ?? null;
        if (empty($maxTotalSize) || !is_numeric($maxTotalSize)) {
            $maxTotalSize = EasyForm::getInstance()->getSettings()->maxTotalUploadSize;
        }
        if (!empty($maxTotalSize) && is_numeric($maxTotalSize) && (int) $maxTotalSize > 0) {
            $totalBytes = array_sum(array_map(fn($f) => $f->size, $files));
            if ($totalBytes > (float) $maxTotalSize * 1024 * 1024) {
                $errors[] = Craft::t('easy-form', '{label}: combined upload size exceeds the {size}MB limit', ['label' => $label, 'size' => (int) $maxTotalSize]);
            }
        }

        $allowedTypes = !empty($field['allowedFileTypes'])
            ? array_map('trim', explode(',', strtolower($field['allowedFileTypes'])))
            : [];

        foreach ($files as $file) {
            $fileName = $file->name;

            if ($file->size > $maxSizeBytes) {
                $errors[] = $this->siteMessage($field, 'siteFileSizeMessages')
                    ?: Craft::t('easy-form', 'File "{name}" exceeds maximum size of {size}MB', ['name' => $fileName, 'size' => $maxFileSize]);
            }

            if (!empty($allowedTypes)) {
                $extension = strtolower($file->getExtension() ?: pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($extension, $allowedTypes, true)) {
                    $errors[] = Craft::t('easy-form', 'File "{name}" has an invalid file type. Allowed types: {types}', ['name' => $fileName, 'types' => $field['allowedFileTypes']]);
                }
            }
        }

        return $errors;
    }

    /**
     * Returns normalized uploaded files for a field handle.
     *
     * @return \yii\web\UploadedFile[]
     */
    public function getUploadedFiles(string $handle): array
    {
        $files = \yii\web\UploadedFile::getInstancesByName("fields[{$handle}]");
        // Drop entries with upload errors / empty placeholders.
        return array_values(array_filter($files, fn($f) => $f && $f->name !== '' && $f->size > 0));
    }
}
