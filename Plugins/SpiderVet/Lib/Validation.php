<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use DateTime;
use FacturaScripts\Core\Tools;

class Validation
{
    /** Accept native model dates and HTML inputs, rejecting rolled-over dates. */
    public static function date($value, bool $withTime = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $formats = $withTime
            ? ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i']
            : ['Y-m-d', 'd-m-Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat('!' . $format, (string) $value);
            $errors = DateTime::getLastErrors();
            if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                return $date->format($withTime ? Tools::DATETIME_STYLE : Tools::DATE_STYLE);
            }
        }
        return null;
    }

    public static function number($value, $minimum = 0, $maximum = null): bool
    {
        return is_numeric($value) && is_finite((float) $value)
            && (float) $value >= $minimum
            && ($maximum === null || (float) $value <= $maximum);
    }
}
