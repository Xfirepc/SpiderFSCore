<?php

namespace FacturaScripts\Core\Base;

use FacturaScripts\Core\Tools;

/** Solo las instalaciones nuevas que declaran onboarding requieren confirmación. */
final class TenantOnboardingPolicy
{
    public static function required($user): bool
    {
        return $user && !empty($user->nick) && empty($user->sysadmin)
            && (bool)Tools::settings('main_system', 'onboarding_required', false)
            && !Tools::settings('main_system', 'onboarding_completed_at', null);
    }
}
