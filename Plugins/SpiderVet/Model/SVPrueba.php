<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Plugins\SpiderVet\Lib\Workflow;

class SVPrueba extends VetRecord
{
    use ModelTrait;

    public $idconsulta;
    public $fecha;
    public $examen;
    public $resultado;

    public static function tableName(): string
    {
        return 'sv_pruebas';
    }

    public function test(): bool
    {
        return Workflow::validateConsultation($this) && parent::test();
    }
}
