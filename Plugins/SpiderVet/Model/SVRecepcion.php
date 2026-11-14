<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SpiderVet\Lib\Workflow;

class SVRecepcion extends VetRecord
{
    use ModelTrait;

    public $idpaciente;
    public $idcita;
    public $entrada;
    public $servicio;
    public $estado;
    public $motivo;
    public $observaciones;
    public $salida;

    public static function tableName(): string
    {
        return 'sv_recepciones';
    }

    public function test(): bool
    {
        if (!$this->validatePatient() || !parent::test()) {
            return false;
        }
        if ($this->idcita) {
            $cita = new SVCita();
            if (!$cita->loadFromCode($this->idcita) || (int) $cita->idpaciente !== (int) $this->idpaciente
                || $cita->servicio !== $this->servicio) {
                return $this->invalid('La cita debe corresponder al mismo paciente y servicio.');
            }
            if (!$this->exists() && in_array($cita->estado,
                ['cancelada', 'no_asistio', 'completada', 'recibida'], true)) {
                return $this->invalid('Esta cita no admite una nueva recepción.');
            }
            if ((new self())->count([new DataBaseWhere('idcita', $this->idcita),
                new DataBaseWhere('id', $this->id ?: -1, '!=')])) {
                return $this->invalid('La llegada de esta cita ya fue registrada.');
            }
        }
        if ($this->exists()) {
            $old = new self();
            $old->loadFromCode($this->id);
            if ($this->servicio !== $old->servicio) {
                return $this->invalid('Conserve el servicio de la recepción ya registrada.');
            }
        }
        $progress = Workflow::progress($this->id ?: -1, $this->servicio);
        if ($this->estado === 'completada' && !$progress['completed']) {
            return $this->invalid('Termine los servicios de la recepción antes de registrar la salida.');
        }
        if ($this->estado === 'cancelada' && $progress['started']) {
            return $this->invalid('Esta recepción ya tiene atenciones; conserve su historial.');
        }
        $this->salida = $this->estado === 'completada' ? ($this->salida ?: Tools::dateTime()) : null;
        return true;
    }

    protected function afterSave(): bool
    {
        return Workflow::syncAppointment($this);
    }

    public function delete(): bool
    {
        if (Workflow::progress($this->id, $this->servicio)['started'] || $this->idcita) {
            return $this->invalid('La recepción tiene registros vinculados y debe conservarse.');
        }
        return parent::delete();
    }
}
