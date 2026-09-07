<?php

namespace FacturaScripts\Core\Lib\Accounting;

use FacturaScripts\Core\Tools;

/** Shared switch for accounting in the current installation. */
final class AccountingSettings
{
    public static function isEnabled(): bool
    {
        // Read Tools on each call: it owns the tenant settings and their cache.
        return filter_var(Tools::settings('facturacione', 'allow_accounting', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Explicit accounting commands must fail instead of reporting a no-op as posted. */
    public static function requireEnabled(): bool
    {
        if (self::isEnabled()) {
            return true;
        }

        Tools::log()->warning('accounting-disabled');
        return false;
    }
}
