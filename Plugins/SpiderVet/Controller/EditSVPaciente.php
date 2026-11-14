<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetEditController;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;

class EditSVPaciente extends VetEditController
{
    public function getModelClassName(): string
    {
        return 'SVPaciente';
    }

    protected function createViews()
    {
        parent::createViews();
        foreach (['SVConsulta', 'SVPeluqueria', 'SVCita', 'SVRecepcion'] as $class) {
            $permissions = new ControllerPermissions($this->user, 'List' . $class);
            if (!$permissions->allowAccess) {
                continue;
            }
            $definition = FieldCatalog::get($class);
            $view = $this->addListView('List' . $class, $class, $definition['title'], $definition['icon']);
            $view->addOrderBy(['id'], 'Más recientes', 2);
            $view->setSettings('btnNew', false)->setSettings('btnDelete', false)
                ->setSettings('checkBoxes', false)->setSettings('btnPrint', false);
        }
    }

    protected function loadData($viewName, $view)
    {
        if ($viewName === $this->getMainViewName()) {
            parent::loadData($viewName, $view);
            return;
        }
        $permissions = new ControllerPermissions($this->user, $viewName);
        $patient = $this->getModel();
        if (!$patient->exists() || !$this->checkOwnerData($patient) || !$permissions->allowAccess) {
            $this->setSettings($viewName, 'active', false);
            return;
        }
        $where = Access::where($this->user, $permissions);
        $where[] = new DataBaseWhere('idpaciente', $patient->id);
        $view->loadData('', $where);
    }
}
