<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\PatientSearch;
use FacturaScripts\Plugins\SpiderVet\Lib\Validation;
use FacturaScripts\Plugins\SpiderVet\Model\SVCita;
use FacturaScripts\Plugins\SpiderVet\Model\SVConsulta;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;
use FacturaScripts\Plugins\SpiderVet\Model\SVPeluqueria;
use FacturaScripts\Plugins\SpiderVet\Model\SVRecepcion;
use Throwable;

class SpiderVet extends Controller
{
    public $day;
    public $query = '';
    public $patients = [];
    public $appointments = [];
    public $receptions = [];
    public $totals = ['patients' => 0, 'appointments' => 0, 'waiting' => 0];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'spidervet';
        $data['title'] = 'Recepción y agenda';
        $data['icon'] = 'fas fa-clinic-medical';
        $data['ordernum'] = 1;
        return $data;
    }

    public function can(string $page, bool $update = false): bool
    {
        $permissions = new ControllerPermissions($this->user ?: null, $page);
        return $permissions->allowAccess && (!$update || $permissions->allowUpdate);
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->response->headers->set('Cache-Control', 'private, no-store');
        if ($this->request->request->get('action') === 'check-in') {
            $this->checkIn();
        }
        $date = Validation::date($this->request->query->get('day', date('Y-m-d')));
        $this->day = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
        $this->query = mb_substr((string) $this->request->query->get('q', ''), 0, 150);
        $patientPermissions = new ControllerPermissions($user, 'EditSVPaciente');
        if ($patientPermissions->allowAccess) {
            $this->totals['patients'] = (new SVPaciente())->count(Access::where($user, $patientPermissions));
            if ($this->query !== '') {
                $this->patients = PatientSearch::find($this->query, $user, $patientPermissions);
            }
        }
        if ($this->can('ListSVCita')) {
            $where = Access::where($user, new ControllerPermissions($user, 'ListSVCita'));
            if ($permissions->onlyOwnerData) {
                $where[] = new DataBaseWhere('nick', $user->nick);
            }
            $where[] = new DataBaseWhere('inicio', $this->day . ' 00:00:00', '>=');
            $where[] = new DataBaseWhere('inicio', $this->day . ' 23:59:59', '<=');
            $this->totals['appointments'] = (new SVCita())->count($where);
            foreach (SVCita::all($where, ['inicio' => 'ASC'], 0, 100) as $appointment) {
                $patient = $appointment->patient();
                if ($patient && Access::canRead($patient, $user, $patientPermissions)) {
                    $this->appointments[] = ['record' => $appointment, 'patient' => $patient];
                }
            }
        }
        if ($this->can('ListSVRecepcion')) {
            $where = Access::where($user, new ControllerPermissions($user, 'ListSVRecepcion'));
            if ($permissions->onlyOwnerData) {
                $where[] = new DataBaseWhere('nick', $user->nick);
            }
            $where[] = new DataBaseWhere('estado', ['pendiente', 'en_atencion'], 'IN');
            $this->totals['waiting'] = (new SVRecepcion())->count($where);
            foreach (SVRecepcion::all($where, ['entrada' => 'ASC'], 0, 100) as $reception) {
                $patient = $reception->patient();
                if (!$patient || !Access::canRead($patient, $user, $patientPermissions)) {
                    continue;
                }
                $clinical = new SVConsulta();
                $grooming = new SVPeluqueria();
                $clinical->loadFromCode('', [new DataBaseWhere('idrecepcion', $reception->id)]);
                $grooming->loadFromCode('', [new DataBaseWhere('idrecepcion', $reception->id)]);
                $this->receptions[] = ['record' => $reception, 'patient' => $patient,
                    'clinicalUrl' => $clinical->exists() ? $clinical->url('edit') : 'EditSVConsulta?idrecepcion=' . $reception->id,
                    'groomingUrl' => $grooming->exists() ? $grooming->url('edit') : 'EditSVPeluqueria?idrecepcion=' . $reception->id];
            }
        }
    }

    private function checkIn(): void
    {
        if (!$this->request->isMethod('POST') || !$this->permissions->allowUpdate
            || !$this->can('EditSVRecepcion', true) || !$this->can('EditSVCita', true)
            || !$this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }
        $id = (int) $this->request->request->get('idcita');
        $this->dataBase->beginTransaction();
        try {
            $this->dataBase->select('SELECT id FROM sv_citas WHERE id = ' . $this->dataBase->var2str($id) . ' FOR UPDATE');
            $appointment = new SVCita();
            $access = new ControllerPermissions($this->user, 'EditSVCita');
            if (!$appointment->loadFromCode($id) || !Access::canRead($appointment, $this->user, $access)
                || !Access::canRead($appointment, $this->user, $this->permissions)) {
                throw new \RuntimeException('No tiene acceso a la cita seleccionada.');
            }
            $reception = new SVRecepcion();
            if ($reception->loadFromCode('', [new DataBaseWhere('idcita', $id)])) {
                $read = new ControllerPermissions($this->user, 'EditSVRecepcion');
                if (!Access::canRead($reception, $this->user, $read)) {
                    throw new \RuntimeException('No tiene acceso a la recepción de la cita.');
                }
                $this->dataBase->rollback();
                $this->redirect($reception->url('edit'));
                return;
            }
            $reception->idcita = $appointment->id;
            $reception->idpaciente = $appointment->idpaciente;
            $reception->servicio = $appointment->servicio;
            $reception->motivo = $appointment->motivo;
            if (!$reception->save()) {
                throw new \RuntimeException('No se pudo registrar la llegada del paciente.');
            }
            $this->dataBase->commit();
            $this->redirect($reception->url('edit'));
        } catch (Throwable $e) {
            $this->dataBase->rollback();
            Tools::log()->warning($e->getMessage());
        }
    }
}
