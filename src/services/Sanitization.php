<?php

namespace yannkost\easyform\services;

/**
 * Stateless sanitization helpers used at the two trust boundaries:
 *  - on input (stored submission values): strip dangerous control characters;
 *  - on output (CSV export): neutralize spreadsheet formula injection.
 *
 * Pure functions with no Craft/Yii dependency so they are trivially testable.
 */
final class Sanitization
{
    /**
     * Characters that can start a spreadsheet formula. A cell beginning with one
     * of these is prefixed with a single quote so Excel / LibreOffice / Sheets
     * treat it as text instead of executing it.
     */
    private const CSV_INJECTION_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * Remove dangerous ASCII control characters (NULL etc.) while preserving
     * tab, line feed and carriage return so multi-line text survives. Operates
     * byte-wise on the C0 range + DEL, which never touches multibyte UTF-8.
     */
    public static function removeControlChars(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    /**
     * Neutralize CSV / spreadsheet formula injection. If the value starts with a
     * formula trigger, prefix it with a single quote.
     */
    public static function sanitizeForCsv(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (in_array($value[0], self::CSV_INJECTION_PREFIXES, true)) {
            return "'" . $value;
        }

        return $value;
    }
}
