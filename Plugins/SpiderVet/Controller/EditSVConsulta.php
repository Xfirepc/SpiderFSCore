<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetEditController;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;

class EditSVConsulta extends VetEditController
{
    public function getModelClassName(): string
    {
        return 'SVConsulta';
    }

    protected function createViews()
    {
        parent::createViews();
        $this->addHtmlView('SVConsultaContext', 'SVConsultaContext', 'SVConsulta', 'Tutor y paciente', 'fas fa-paw');
        foreach (['SVPrueba', 'SVTratamiento'] as $class) {
            if (!(new ControllerPermissions($this->user, 'Edit' . $class))->allowAccess) {
                continue;
            }
            $definition = FieldCatalog::get($class);
            $view = $this->addListView('List' . $class, $class, $definition['title'], $definition['icon']);
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
        $consultation = $this->getModel();
        if (!$consultation->exists() || !$this->checkOwnerData($consultation)) {
            $this->setSettings($viewName, 'active', false);
            return;
        }
        if ($viewName === 'SVConsultaContext') {
            $view->loadData($consultation->id);
            return;
        }
        $permissions = new ControllerPermissions($this->user, str_replace('List', 'Edit', $viewName));
        if (!$permissions->allowAccess) {
            $this->setSettings($viewName, 'active', false);
            return;
        }
        $where = Access::where($this->user, $permissions);
        $where[] = new DataBaseWhere('idconsulta', $consultation->id);
        $view->loadData('', $where);
        $class = substr($viewName, 4);
        $this->contextButton($viewName, $class, 'Agregar', 'idconsulta', $consultation->id);
    }

    public function clinicalContext(): array
    {
        return [
            'patient' => json_decode($this->getModel()->fichapaciente ?? '', true) ?: [],
            'tutor' => json_decode($this->getModel()->fichatutor ?? '', true) ?: [],
        ];
    }
}
