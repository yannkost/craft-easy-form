<?php

namespace yannkost\easyform\helpers;

use Craft;
use yii\i18n\Formatter;

/**
 * Formats stored date/datetime/time field values in a given locale for display
 * in emails and exports. Falls back to the raw value if it can't be parsed.
 */
class DateFormatter
{
    /**
     * @param string $value  Stored value (Y-m-d, Y-m-d\TH:i, or H:i)
     * @param string $type   'date' | 'datetime' | 'time'
     * @param string $locale ICU locale id (e.g. 'fr-FR')
     */
    public static function localize(string $value, string $type, string $locale): string
    {
        if ($value === '') {
            return $value;
        }

        $timeZone = Craft::$app->getTimeZone();
        try {
            $dt = new \DateTime($value, new \DateTimeZone($timeZone));
        } catch (\Throwable) {
            return $value;
        }

        $formatter = new Formatter([
            'locale' => $locale,
            'timeZone' => $timeZone,
            'dateFormat' => 'long',
            'timeFormat' => 'short',
            'datetimeFormat' => 'medium',
        ]);

        try {
            return match ($type) {
                'date' => $formatter->asDate($dt),
                'time' => $formatter->asTime($dt),
                default => $formatter->asDatetime($dt),
            };
        } catch (\Throwable) {
            return $value;
        }
    }

    public static function isDateType(?string $type): bool
    {
        return in_array($type, ['date', 'datetime', 'time'], true);
    }
}
