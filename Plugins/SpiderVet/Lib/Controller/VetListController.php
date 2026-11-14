<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;

abstract class VetListController extends ListController
{
    abstract public function modelName(): string;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $definition = FieldCatalog::get($this->modelName());
        $data['menu'] = 'spidervet';
        $data['title'] = $definition['title'];
        $data['icon'] = $definition['icon'];
        $data['showonmenu'] = !in_array($this->modelName(), ['SVPrueba', 'SVTratamiento'], true);
        return $data;
    }

    protected function createViews()
    {
        $name = 'List' . $this->modelName();
        $definition = FieldCatalog::get($this->modelName());
        $view = $this->addView($name, $this->modelName(), $definition['title'], $definition['icon']);
        $view->setSettings('btnDelete', false)->setSettings('megasearch', false);
        $search = [];
        foreach ($definition['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (in_array($field['kind'], ['text', 'textarea'], true)) {
                    $search[] = $field['key'];
                }
                if (isset($field['options']) && in_array($field['key'], ['estado', 'especie', 'servicio'], true)) {
                    $values = [['code' => '', 'description' => 'Todos']];
                    foreach ($field['options'] as $code => $description) {
                        $values[] = ['code' => $code, 'description' => $description];
                    }
                    $this->addFilterSelect($name, $field['key'], $field['label'], $field['key'], $values);
                }
            }
        }
        if ($this->modelName() === 'SVPaciente') {
            $search[] = 'nombretutor';
            $this->addFilterAutocomplete($name, 'codcliente', 'Tutor / cliente', 'codcliente', 'clientes', 'codcliente', 'nombre');
            $this->addFilterSelect($name, 'activo', 'Estado del paciente', 'activo', [
                ['code' => '', 'description' => 'Todos'], ['code' => '1', 'description' => 'Activos'],
                ['code' => '0', 'description' => 'Inactivos'],
            ]);
        } else {
            $dates = ['SVCita' => 'inicio', 'SVRecepcion' => 'entrada', 'SVConsulta' => 'fecha',
                'SVPeluqueria' => 'inicio', 'SVPrueba' => 'fecha'];
            if (isset($dates[$this->modelName()])) {
                $this->addFilterPeriod($name, 'periodo', 'Fecha', $dates[$this->modelName()], true);
            }
        }
        $this->addSearchFields($name, $search);
        $this->addOrderBy($name, ['id'], 'Más recientes', 2);
        if ($this->modelName() === 'SVPaciente') {
            $this->addOrderBy($name, ['nombre'], 'Nombre');
        }
    }

    protected function loadData($viewName, $view)
    {
        $where = Access::where($this->user, $this->permissions);
        foreach (['idpaciente', 'idconsulta'] as $field) {
            if (property_exists($view->model, $field) && $this->request->query->get($field)) {
                $where[] = new DataBaseWhere($field, (int) $this->request->query->get($field));
            }
        }
        $view->loadData('', $where);
    }

    protected function autocompleteAction(): array
    {
        return SelectOptions::autocomplete($this->request, $this->user, $this->modelName());
    }

    protected function deleteAction()
    {
        return false;
    }
}
