<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\ClinicalContext;
use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;
use FacturaScripts\Plugins\SpiderVet\Model\SVConsulta;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;
use FacturaScripts\Plugins\SpiderVet\Model\SVPeluqueria;

class PrintSVFicha extends Controller
{
    public $patient;
    public $context;
    public $consultations = [];
    public $groomings = [];
    public $single = false;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'spidervet';
        $data['title'] = 'Ficha veterinaria';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->response->headers->set('Cache-Control', 'private, no-store');
        $this->patient = new SVPaciente();
        $patientPermissions = new ControllerPermissions($user, 'EditSVPaciente');
        $clinicalPermissions = new ControllerPermissions($user, 'EditSVConsulta');
        $consultationId = (int) $this->request->query->get('consulta');
        $patientId = (int) $this->request->query->get('code');
        if (!$permissions->allowExport || !$patientPermissions->allowExport) {
            $this->setTemplate('Error/AccessDenied');
            return;
        }
        if ($consultationId) {
            $clinical = new SVConsulta();
            if (!$clinicalPermissions->allowExport || !$clinical->loadFromCode($consultationId)
                || !Access::canRead($clinical, $user, $clinicalPermissions)) {
                $this->setTemplate('Error/AccessDenied');
                return;
            }
            $patientId = $clinical->idpaciente;
            $this->single = true;
            $this->consultations = [$clinical];
        }
        if (!$this->patient->loadFromCode($patientId) || !Access::canRead($this->patient, $user, $patientPermissions)
            || !Access::canRead($this->patient, $user, $permissions)) {
            $this->setTemplate('Error/AccessDenied');
            return;
        }
        $this->empresa->loadFromCode($this->patient->idempresa);
        $this->context = ClinicalContext::capture($this->patient);
        if (!$this->single) {
            if ($clinicalPermissions->allowAccess && $clinicalPermissions->allowExport) {
                $where = Access::where($user, $clinicalPermissions);
                $where[] = new DataBaseWhere('idpaciente', $patientId);
                $this->consultations = SVConsulta::all($where, ['fecha' => 'ASC', 'id' => 'ASC'], 0, 0);
            }
            $groomingPermissions = new ControllerPermissions($user, 'EditSVPeluqueria');
            if ($groomingPermissions->allowAccess && $groomingPermissions->allowExport) {
                $where = Access::where($user, $groomingPermissions);
                $where[] = new DataBaseWhere('idpaciente', $patientId);
                $this->groomings = SVPeluqueria::all($where, ['inicio' => 'ASC', 'id' => 'ASC'], 0, 0);
            }
        }
    }

    public function definition(string $model): array
    {
        return FieldCatalog::get($model);
    }

    public function formatField($value, array $field): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (isset($field['options'])) {
            return $field['options'][$value] ?? '—';
        }
        if ($field['kind'] === 'checkbox') {
            return $value ? 'Sí' : 'No';
        }
        return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function consultationContext(SVConsulta $record): array
    {
        return ['patient' => json_decode($record->fichapaciente ?? '', true) ?: [],
            'tutor' => json_decode($record->fichatutor ?? '', true) ?: []];
    }

    public function clinicalRows(SVConsulta $consultation, bool $treatments): array
    {
        $permissions = new ControllerPermissions($this->user, $treatments ? 'EditSVTratamiento' : 'EditSVPrueba');
        if (!$permissions->allowAccess || !$permissions->allowExport) {
            return [];
        }
        $rows = $treatments ? $consultation->treatments() : $consultation->tests();
        return array_filter($rows, function ($record) use ($permissions) {
            return Access::canRead($record, $this->user, $permissions);
        });
    }
}
