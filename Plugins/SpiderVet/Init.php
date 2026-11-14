<?php

namespace FacturaScripts\Plugins\SpiderVet;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\InitClass;
use FacturaScripts\Core\Model\Role;
use FacturaScripts\Core\Model\RoleAccess;

class Init extends InitClass
{
    public function init()
    {
        $this->loadExtension(new Extension\Controller\EditCliente());
        $this->loadExtension(new Extension\Model\Cliente());
    }

    public function update()
    {
        foreach (['SVPaciente', 'SVCita', 'SVRecepcion', 'SVConsulta', 'SVPrueba', 'SVTratamiento', 'SVPeluqueria'] as $name) {
            $class = 'FacturaScripts\\Plugins\\SpiderVet\\Model\\' . $name;
            new $class();
        }
        $this->createRole();
    }

    private function createRole(): void
    {
        $db = new DataBase();
        $db->beginTransaction();
        $role = new Role();
        if (!$role->loadFromCode('SpiderVet')) {
            $role->codrole = 'SpiderVet';
            $role->descripcion = 'Gestión veterinaria y peluquería';
            if (!$role->save()) {
                $db->rollback();
                return;
            }
        }
        $pages = ['SpiderVet', 'PrintSVFicha'];
        foreach (['SVPaciente', 'SVCita', 'SVRecepcion', 'SVConsulta', 'SVPrueba', 'SVTratamiento', 'SVPeluqueria'] as $name) {
            $pages[] = 'Edit' . $name;
            $pages[] = 'List' . $name;
        }
        foreach ($pages as $page) {
            $access = new RoleAccess();
            if ($access->loadFromCode('', [new DataBaseWhere('codrole', 'SpiderVet'), new DataBaseWhere('pagename', $page)])) {
                continue;
            }
            $access->codrole = 'SpiderVet';
            $access->pagename = $page;
            $access->allowupdate = true;
            $access->allowdelete = true;
            $access->allowexport = true;
            $access->allowimport = false;
            $access->onlyownerdata = false;
            if (!$access->save()) {
                $db->rollback();
                return;
            }
        }
        $db->commit();
    }
}
