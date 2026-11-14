<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

class SVCita extends VetRecord
{
    use ModelTrait;

    public $idpaciente;
    public $servicio;
    public $inicio;
    public $fin;
    public $responsable;
    public $estado;
    public $motivo;
    public $observaciones;

    public static function tableName(): string
    {
        return 'sv_citas';
    }

    public function test(): bool
    {
        if (!$this->validatePatient() || !parent::test()) {
            return false;
        }
        $this->responsable = $this->responsable ?: null;
        if (strtotime($this->fin) <= strtotime($this->inicio)) {
            return $this->invalid('El fin de la cita debe ser posterior al inicio.');
        }
        $reception = new SVRecepcion();
        if ($reception->loadFromCode('', [new DataBaseWhere('idcita', $this->id ?: -1)])) {
            $expected = ['completada' => 'completada', 'cancelada' => 'cancelada'][$reception->estado] ?? 'recibida';
            if ($this->estado !== $expected) {
                return $this->invalid('Actualice el estado de esta cita desde su recepción.');
            }
        } elseif (in_array($this->estado, ['recibida', 'completada'], true)) {
            return $this->invalid('Registre primero la llegada del paciente en recepción.');
        }
        return true;
    }

    public function delete(): bool
    {
        if ((new SVRecepcion())->count([new DataBaseWhere('idcita', $this->id)])) {
            return $this->invalid('La cita tiene una recepción asociada y debe conservarse.');
        }
        return parent::delete();
    }
}
