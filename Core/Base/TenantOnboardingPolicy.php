<?php

namespace FacturaScripts\Core\Base;

use FacturaScripts\Core\Tools;

/** Las DEMO administradas confirman su preparación y pueden aplazar la consulta SRI. */
final class TenantOnboardingPolicy
{
    public static function required($user): bool
    {
        return $user && !empty($user->nick) && empty($user->sysadmin)
            && (bool)Tools::settings('main_system', 'onboarding_required', false)
            && (!Tools::settings('main_system', 'onboarding_completed_at', null)
                || (self::invoicingPending() && !Tools::settings('main_system', 'onboarding_invoicing_deferred_at', null)));
    }

    public static function invoicingPending(): bool
    {
        return (bool)Tools::settings('main_system', 'onboarding_required', false)
            && Tools::settings('main_system', 'onboarding_invoicing_status', '') === 'pending';
    }
}
