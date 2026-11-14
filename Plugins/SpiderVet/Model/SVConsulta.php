<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SpiderVet\Lib\ClinicalContext;
use FacturaScripts\Plugins\SpiderVet\Lib\Workflow;

class SVConsulta extends VetRecord
{
    use ModelTrait;

    public $idpaciente;
    public $idrecepcion;
    public $estado;
    public $fecha;
    public $tipo;
    public $veterinario;
    public $motivo;
    public $historiaproblema;
    public $vacunacion;
    public $ultimavacuna;
    public $desparasitacion;
    public $ultimadesparasitacion;
    public $alimentacion;
    public $alergias;
    public $medicamentos;
    public $antecedentes;
    public $temperatura;
    public $frecuenciacardiaca;
    public $frecuenciarespiratoria;
    public $peso;
    public $mucosas;
    public $tllc;
    public $hidratacion;
    public $bcs;
    public $estadogeneral;
    public $pielpelaje;
    public $ojosoidosboca;
    public $respiratorio;
    public $cardiovascular;
    public $digestivo;
    public $urinariogenital;
    public $neurologico;
    public $problemas;
    public $diagnosticopresuntivo;
    public $diagnosticodefinitivo;
    public $cuidados;
    public $proximocontrol;
    public $ayuno;
    public $alarmas;
    public $llamadaseguimiento;
    public $fechaseguimiento;
    public $conformidad;
    public $notasconformidad;
    public $fichapaciente;
    public $fichatutor;

    public static function tableName(): string
    {
        return 'sv_consultas';
    }

    public function initializeForPatient(SVPaciente $patient): void
    {
        $this->idpaciente = $patient->id;
        foreach (['peso', 'vacunacion', 'ultimavacuna', 'desparasitacion', 'ultimadesparasitacion',
            'alimentacion', 'alergias', 'medicamentos', 'antecedentes'] as $field) {
            $this->{$field} = $patient->{$field};
        }
    }

    public function test(): bool
    {
        if (!$this->validatePatient() || !parent::test() || !Workflow::validateReception($this, 'veterinaria')) {
            return false;
        }
        if (!$this->exists()) {
            $context = ClinicalContext::capture($this->patient());
            $this->fichapaciente = json_encode($context['patient'], JSON_UNESCAPED_UNICODE);
            $this->fichatutor = json_encode($context['tutor'], JSON_UNESCAPED_UNICODE);
        } else {
            $old = new self();
            $old->loadFromCode($this->id);
            $this->fichapaciente = $old->fichapaciente;
            $this->fichatutor = $old->fichatutor;
        }
        return true;
    }

    public function tests(): array
    {
        return SVPrueba::all([new DataBaseWhere('idconsulta', $this->id)], ['fecha' => 'ASC', 'id' => 'ASC'], 0, 0);
    }

    public function treatments(): array
    {
        return SVTratamiento::all([new DataBaseWhere('idconsulta', $this->id)], ['id' => 'ASC'], 0, 0);
    }

    protected function afterSave(): bool
    {
        return !$this->idrecepcion || Workflow::syncReception($this->idrecepcion);
    }

    public function delete(): bool
    {
        if ((new SVPrueba())->count([new DataBaseWhere('idconsulta', $this->id)])
            || (new SVTratamiento())->count([new DataBaseWhere('idconsulta', $this->id)])) {
            return $this->invalid('La consulta tiene pruebas o tratamientos que deben conservarse.');
        }
        return parent::delete();
    }
}
