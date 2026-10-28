<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Model\User;

/** Los permisos de Dashboard no conceden acceso a los módulos que resume. */
class DashboardAccess
{
    private $user;
    private $permissions = [];
    private $own;

    public function __construct(User $user, bool $onlyOwner = false)
    {
        $this->user = $user;
        $this->own = $onlyOwner;
    }

    public function permission(string $page): ControllerPermissions
    {
        if (!isset($this->permissions[$page])) {
            $this->permissions[$page] = new ControllerPermissions($this->user, $page);
        }
        return $this->permissions[$page];
    }

    public function can(string $page, bool $update = false): bool
    {
        $permissions = $this->permission($page);
        return $permissions->allowAccess && (!$update || $permissions->allowUpdate);
    }

    public function onlyOwner(string $page): bool
    {
        return $this->own || $this->permission($page)->onlyOwnerData;
    }

    public function owns(string $page, array $record): bool
    {
        if (!$this->onlyOwner($page)) {
            return true;
        }
        if (array_key_exists('nick', $record)) {
            if ($record['nick'] === null || $record['nick'] === $this->user->nick) {
                return true;
            }
        } elseif (!array_key_exists('codagente', $record)) {
            return true;
        } else {
            return $record['codagente'] === $this->user->codagente;
        }
        return !empty($this->user->codagente) && ($record['codagente'] ?? null) === $this->user->codagente;
    }

    public function identity(): array
    {
        return ['nick' => $this->user->nick, 'codagente' => $this->user->codagente];
    }
}
