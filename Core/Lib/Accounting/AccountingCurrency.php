<?php

namespace FacturaScripts\Core\Lib\Accounting;

use FacturaScripts\Core\Tools;

/** Conversión contable: una operación local en USD conserva su importe. */
final class AccountingCurrency
{
    public static function isUsd(): bool
    {
        return 'USD' === Tools::settings('default', 'coddivisa');
    }

    public static function rate(?string $currency, $storedRate): float
    {
        // El catálogo heredado expresa USD respecto de EUR (1.129).
        // Esa tasa no corresponde a una operación USD contabilizada en USD.
        if ('USD' === $currency && self::isUsd()) {
            return 1.0;
        }

        return (float)$storedRate > 0 ? (float)$storedRate : 1.0;
    }

    public static function amount($amount, ?string $currency, $storedRate): float
    {
        return round((float)$amount / self::rate($currency, $storedRate), FS_NF0);
    }
}
