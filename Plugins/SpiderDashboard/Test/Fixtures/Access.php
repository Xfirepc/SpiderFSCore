<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Test\Fixtures;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardAccess;
use ReflectionClass;

class Access extends DashboardAccess
{
    public $rules = [];
    public $defaultAccess = true;

    public function __construct(bool $owner = false)
    {
        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->nick = 'ana';
        $user->codagente = 'AG1';
        parent::__construct($user, $owner);
    }

    public function permission(string $page): ControllerPermissions
    {
        if (!isset($this->rules[$page])) {
            $permission = new ControllerPermissions();
            $permission->set($this->defaultAccess, 1, false, $this->defaultAccess);
            $this->rules[$page] = $permission;
        }
        return $this->rules[$page];
    }
}
