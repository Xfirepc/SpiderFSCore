<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Model\User;

class Access
{
    public static function canRead($model, User $user, ControllerPermissions $permissions): bool
    {
        return $permissions->allowAccess && (!$permissions->onlyOwnerData ||
            (string) $model->nick === (string) $user->nick);
    }

    public static function where(User $user, ControllerPermissions $permissions): array
    {
        return $permissions->onlyOwnerData ? [new DataBaseWhere('nick', $user->nick)] : [];
    }

    public static function forPage(string $page): ControllerPermissions
    {
        return new ControllerPermissions(Session::user() ?: null, $page);
    }
}
