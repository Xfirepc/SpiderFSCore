<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Plugins\SpiderVet\Lib\Workflow;

class SVTratamiento extends VetRecord
{
    use ModelTrait;

    public $idconsulta;
    public $medicamento;
    public $dosis;
    public $viafrecuencia;
    public $duracion;

    public static function tableName(): string
    {
        return 'sv_tratamientos';
    }

    public function test(): bool
    {
        return Workflow::validateConsultation($this) && parent::test();
    }
}
