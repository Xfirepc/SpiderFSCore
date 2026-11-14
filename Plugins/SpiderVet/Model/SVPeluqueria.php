<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SpiderVet\Lib\Workflow;

class SVPeluqueria extends VetRecord
{
    use ModelTrait;

    public $idpaciente;
    public $idrecepcion;
    public $inicio;
    public $fin;
    public $estado;
    public $responsable;
    public $solicitado;
    public $indicacionesbano;
    public $observacionesingreso;
    public $realizado;
    public $incidencias;
    public $recomendaciones;

    public static function tableName(): string
    {
        return 'sv_peluquerias';
    }

    public function initializeForPatient(SVPaciente $patient): void
    {
        $this->idpaciente = $patient->id;
        $this->indicacionesbano = $patient->indicacionesbano;
    }

    public function test(): bool
    {
        if (!$this->validatePatient() || !parent::test() || !Workflow::validateReception($this, 'peluqueria')) {
            return false;
        }
        if ($this->estado === 'terminada' && empty($this->realizado)) {
            return $this->invalid('Describa el servicio realizado antes de terminar la peluquería.');
        }
        if ($this->estado === 'terminada' && !$this->fin) {
            $this->fin = Tools::dateTime();
        }
        if ($this->fin && strtotime($this->fin) < strtotime($this->inicio)) {
            return $this->invalid('El fin de la peluquería no puede ser anterior al inicio.');
        }
        return true;
    }

    protected function afterSave(): bool
    {
        return !$this->idrecepcion || Workflow::syncReception($this->idrecepcion);
    }
}
