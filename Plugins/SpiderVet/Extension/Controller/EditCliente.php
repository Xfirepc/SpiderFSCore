<?php

namespace FacturaScripts\Plugins\SpiderVet\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;

class EditCliente
{
    protected function createViews(): Closure
    {
        return function () {
            $permissions = new ControllerPermissions($this->user, 'ListSVPaciente');
            if (!$permissions->allowAccess) {
                return;
            }
            $view = $this->addListView('ListSVPaciente', 'SVClientePaciente', 'Mascotas', 'fas fa-paw');
            $view->addOrderBy(['nombre'], 'Nombre');
            $view->searchFields = ['historia', 'nombre', 'microchip', 'raza'];
            $view->setSettings('btnNew', false)->setSettings('btnDelete', false)
                ->setSettings('btnPrint', false)->setSettings('btnSave', false)->setSettings('checkBoxes', false);
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'ListSVPaciente') {
                return;
            }
            $customer = $this->views[$this->getMainViewName()]->model;
            $permissions = new ControllerPermissions($this->user, 'ListSVPaciente');
            if (!$customer->exists() || !$this->checkOwnerData($customer) || !$permissions->allowAccess) {
                $view->setSettings('active', false);
                return;
            }
            $where = Access::where($this->user, $permissions);
            $where[] = new DataBaseWhere('codcliente', $customer->codcliente);
            $view->loadData('', $where);
            $edit = new ControllerPermissions($this->user, 'EditSVPaciente');
            if ($edit->allowAccess && $edit->allowUpdate) {
                $this->addButton($viewName, ['type' => 'link', 'label' => 'Agregar mascota',
                    'icon' => 'fas fa-paw', 'action' => 'EditSVPaciente?codcliente=' . rawurlencode($customer->codcliente)]);
            }
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            // Pet writes must go through SpiderVet's controller and its record permissions.
            return $this->active !== 'ListSVPaciente' || !in_array($action, ['insert', 'edit', 'delete'], true);
        };
    }
}
