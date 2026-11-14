<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib\Controller;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;

abstract class VetEditController extends EditController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $definition = FieldCatalog::get($this->getModelClassName());
        $data['menu'] = 'spidervet';
        $data['title'] = $definition['title'];
        $data['icon'] = $definition['icon'];
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        // The complete clinical print uses the dedicated permission-checked controller.
        $this->setSettings($this->getMainViewName(), 'btnPrint', false);
    }

    protected function checkOwnerData($model): bool
    {
        return !$model->exists() || Access::canRead($model, $this->user, $this->permissions);
    }

    protected function insertAction()
    {
        return $this->saveRecord(false);
    }

    protected function editAction()
    {
        return $this->saveRecord(true);
    }

    private function saveRecord(bool $editing): bool
    {
        if ($this->active !== $this->getMainViewName() || !$this->permissions->allowUpdate
            || !$this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return false;
        }
        $view = $this->views[$this->active];
        $model = $view->model;
        if ($editing && (!$model->loadFromCode($this->request->request->get('code', ''))
            || !$this->checkOwnerData($model))) {
            Tools::log()->warning('record-not-found');
            return false;
        }
        if (!$editing) {
            $model->clear();
        }
        $id = $model->id;
        $created = $model->creado;
        $owner = $editing ? $model->nick : $this->user->nick;
        $company = $editing ? $model->idempresa : $this->user->idempresa;
        $view->processFormData($this->request, 'edit');
        $model->id = $id;
        $model->creado = $created;
        $model->nick = $owner;
        $model->idempresa = $company;
        // The standard number widget casts a blank input to zero. Preserve unknown values.
        foreach (FieldCatalog::get($model->modelClassName())['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (in_array($field['kind'], ['number', 'date', 'datetime'], true)
                    && empty($field['readonly'])) {
                    $raw = $this->request->request->get($field['key']);
                    $model->{$field['key']} = $raw === '' || $raw === null ? null : $raw;
                }
            }
        }
        if (!$this->referencesAllowed($model) || !$model->save()) {
            Tools::log()->warning('record-save-error');
            return false;
        }
        $view->newCode = $model->id;
        Tools::log()->notice('record-updated-correctly');
        if (!$editing) {
            $this->redirect($model->url('edit') . '&action=save-ok');
        }
        return true;
    }

    protected function referencesAllowed($model): bool
    {
        $references = ['idpaciente' => 'SVPaciente', 'idcita' => 'SVCita',
            'idrecepcion' => 'SVRecepcion', 'idconsulta' => 'SVConsulta'];
        foreach ($references as $field => $className) {
            if (!property_exists($model, $field) || !$model->{$field}) {
                continue;
            }
            $class = 'FacturaScripts\\Plugins\\SpiderVet\\Model\\' . $className;
            $related = new $class();
            $permissions = new ControllerPermissions($this->user, 'Edit' . $className);
            if (!$related->loadFromCode($model->{$field})
                || !Access::canRead($related, $this->user, $permissions)) {
                Tools::log()->warning('No tiene acceso al registro vinculado.');
                return false;
            }
        }
        return true;
    }

    protected function deleteAction()
    {
        if ($this->active !== $this->getMainViewName()) {
            return false;
        }
        $model = $this->getModel();
        $code = $this->request->request->get('code', '');
        if (is_array($code) || !$model->loadFromCode($code) || !$this->checkOwnerData($model)) {
            Tools::log()->warning('record-not-found');
            return false;
        }
        return parent::deleteAction();
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);
        if ($viewName !== $this->getMainViewName()) {
            return;
        }
        $model = $view->model;
        if (!$model->exists() && !$this->request->request->get('action')) {
            $model->clear();
            // Only contextual links may prefill a new record; identity and ownership are server data.
            foreach (['codcliente', 'idpaciente', 'idrecepcion', 'idcita', 'idconsulta', 'inicio', 'fin'] as $field) {
                if (property_exists($model, $field) && $this->request->query->has($field)) {
                    $model->{$field} = $this->request->query->get($field);
                }
            }
            $model->idempresa = $this->user->idempresa;
            $model->nick = $this->user->nick;
            if (!$this->referencesAllowed($model)) {
                $this->setTemplate('Error/AccessDenied');
                return;
            }
            $this->initializeContext($model);
        }
        if ($model->exists() && $this->checkOwnerData($model)) {
            $this->recordButtons($viewName, $model);
        }
    }

    private function initializeContext($model): void
    {
        if (property_exists($model, 'idcita') && $model->idcita) {
            $cita = new \FacturaScripts\Plugins\SpiderVet\Model\SVCita();
            if ($cita->loadFromCode($model->idcita)) {
                $model->idpaciente = $cita->idpaciente;
                $model->servicio = $cita->servicio;
                $model->motivo = $cita->motivo;
            }
        }
        if (property_exists($model, 'idrecepcion') && $model->idrecepcion) {
            $reception = new \FacturaScripts\Plugins\SpiderVet\Model\SVRecepcion();
            if ($reception->loadFromCode($model->idrecepcion)) {
                $model->idpaciente = $reception->idpaciente;
                if (property_exists($model, 'motivo')) {
                    $model->motivo = $reception->motivo;
                }
                if (property_exists($model, 'solicitado')) {
                    $model->solicitado = $reception->motivo;
                    $model->observacionesingreso = $reception->observaciones;
                }
            }
        }
        if (property_exists($model, 'idpaciente') && $model->idpaciente) {
            $patient = new SVPaciente();
            if ($patient->loadFromCode($model->idpaciente) && method_exists($model, 'initializeForPatient')) {
                $model->initializeForPatient($patient);
            }
        }
        if ($model->modelClassName() === 'SVConsulta') {
            $model->veterinario = $this->user->nick;
        }
        if ($model->modelClassName() === 'SVPeluqueria') {
            $model->responsable = $this->user->nick;
        }
    }

    protected function recordButtons(string $viewName, $model): void
    {
        if (property_exists($model, 'estado') && in_array($model->estado,
            ['terminada', 'completada', 'recibida', 'en_atencion'], true)) {
            $this->setSettings($viewName, 'btnDelete', false);
        }
        $patientId = $model->modelClassName() === 'SVPaciente' ? $model->id : ($model->idpaciente ?? null);
        $print = new ControllerPermissions($this->user, 'PrintSVFicha');
        if ($patientId && $print->allowAccess && $print->allowExport && $this->permissions->allowExport) {
            $this->addButton($viewName, ['type' => 'link', 'label' => 'Imprimir ficha completa',
                'icon' => 'fas fa-print', 'target' => '_blank', 'action' => 'PrintSVFicha?code=' . (int) $patientId]);
        }
        if ($model->modelClassName() === 'SVConsulta' && $print->allowAccess && $print->allowExport
            && $this->permissions->allowExport) {
            $this->addButton($viewName, ['type' => 'link', 'label' => 'Imprimir consulta',
                'icon' => 'fas fa-file-medical', 'target' => '_blank', 'action' => 'PrintSVFicha?consulta=' . (int) $model->id]);
        }
        if ($patientId && $model->modelClassName() !== 'SVPaciente') {
            $this->addButton($viewName, ['type' => 'link', 'label' => 'Ficha del paciente',
                'icon' => 'fas fa-paw', 'action' => 'EditSVPaciente?code=' . (int) $patientId]);
        }
        if ($model->modelClassName() === 'SVPaciente' && $model->activo) {
            foreach (['SVCita' => 'Agendar cita', 'SVRecepcion' => 'Registrar llegada',
                'SVConsulta' => 'Nueva consulta', 'SVPeluqueria' => 'Nueva peluquería'] as $class => $label) {
                $this->contextButton($viewName, $class, $label, 'idpaciente', $patientId);
            }
        }
        if ($model->modelClassName() === 'SVConsulta') {
            $this->contextButton($viewName, 'SVPrueba', 'Agregar prueba', 'idconsulta', $model->id);
            $this->contextButton($viewName, 'SVTratamiento', 'Agregar tratamiento', 'idconsulta', $model->id);
        }
        if ($model->modelClassName() === 'SVRecepcion' && !in_array($model->estado, ['completada', 'cancelada'], true)) {
            foreach (['veterinaria' => 'SVConsulta', 'peluqueria' => 'SVPeluqueria'] as $service => $class) {
                if (!in_array($model->servicio, [$service, 'ambos'], true)) {
                    continue;
                }
                $modelClass = 'FacturaScripts\\Plugins\\SpiderVet\\Model\\' . $class;
                $attention = new $modelClass();
                if ($attention->loadFromCode('', [new DataBaseWhere('idrecepcion', $model->id)])) {
                    $this->addButton($viewName, ['type' => 'link', 'label' => 'Abrir ' . $service,
                        'icon' => FieldCatalog::get($class)['icon'], 'action' => $attention->url('edit')]);
                } else {
                    $this->contextButton($viewName, $class, 'Iniciar ' . $service, 'idrecepcion', $model->id);
                }
            }
        }
    }

    protected function contextButton(string $view, string $class, string $label, string $field, int $id): void
    {
        $permissions = new ControllerPermissions($this->user, 'Edit' . $class);
        if ($permissions->allowAccess && $permissions->allowUpdate) {
            $this->addButton($view, ['type' => 'link', 'label' => $label,
                'icon' => FieldCatalog::get($class)['icon'], 'action' => 'Edit' . $class . '?' . $field . '=' . $id]);
        }
    }

    protected function autocompleteAction(): array
    {
        return SelectOptions::autocomplete($this->request, $this->user, $this->getModelClassName());
    }

    protected function datalistAction(): array
    {
        return $this->autocompleteAction();
    }

    protected function selectAction(): array
    {
        $rows = $this->autocompleteAction();
        foreach ($rows as &$row) {
            // Native dependent selects construct option HTML from these strings.
            $row['value'] = Tools::noHtml($row['value']);
        }
        return $rows;
    }
}
