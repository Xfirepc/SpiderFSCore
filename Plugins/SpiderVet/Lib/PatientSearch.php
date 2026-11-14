<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;

class PatientSearch
{
    public static function find(string $query, User $user, ControllerPermissions $permissions): array
    {
        if (!$permissions->allowAccess) {
            return [];
        }
        $where = Access::where($user, $permissions);
        $where[] = new DataBaseWhere('historia|nombre|microchip|nombretutor|codcliente', mb_substr($query, 0, 150), 'LIKE');
        return SVPaciente::all($where, ['activo' => 'DESC', 'nombre' => 'ASC'], 0, 20);
    }
}
