<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Plugins\SpiderVet\Lib\VetRecord;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Cliente;

class SVPaciente extends VetRecord
{
    use ModelTrait;

    public $historia;
    public $codcliente;
    public $activo;
    public $nombre;
    public $especie;
    public $otraespecie;
    public $raza;
    public $sexo;
    public $nacimiento;
    public $edadestimada;
    public $color;
    public $peso;
    public $reproductiva;
    public $microchip;
    public $habitat;
    public $vacunacion;
    public $ultimavacuna;
    public $desparasitacion;
    public $ultimadesparasitacion;
    public $alimentacion;
    public $alergias;
    public $medicamentos;
    public $antecedentes;
    public $indicacionesbano;
    public $observaciones;
    public $nombretutor;

    public static function tableName(): string
    {
        return 'sv_pacientes';
    }

    public function test(): bool
    {
        $customer = new Cliente();
        if (!$customer->loadFromCode($this->codcliente)) {
            return $this->invalid('Seleccione el cliente responsable de la mascota.');
        }
        $this->nombretutor = $customer->nombre;
        if (!$this->exists()) {
            $this->historia = 'SV-' . bin2hex(random_bytes(12));
        } else {
            $old = new self();
            $old->loadFromCode($this->id);
            $this->historia = $old->historia;
            $this->nick = $old->nick;
        }
        if (!parent::test()) {
            return false;
        }
        if ($this->especie === 'otro' && empty($this->otraespecie)) {
            return $this->invalid('Indique la especie de la mascota.');
        }
        if ($this->nacimiento && strtotime($this->nacimiento) > strtotime('today')) {
            return $this->invalid('La fecha de nacimiento no puede estar en el futuro.');
        }
        return true;
    }

    protected function saveInsert(array $values = []): bool
    {
        if (!parent::saveInsert($values)) {
            return false;
        }
        $this->historia = sprintf('SV-%06d', $this->id);
        return parent::saveUpdate();
    }

    public function delete(): bool
    {
        $db = new DataBase();
        foreach (['sv_citas', 'sv_recepciones', 'sv_consultas', 'sv_peluquerias'] as $table) {
            if ($db->tableExists($table) && $db->select('SELECT id FROM ' . $table
                . ' WHERE idpaciente = ' . $db->var2str($this->id) . ' LIMIT 1')) {
                return $this->invalid('El paciente tiene historial. Desactive su ficha para conservarlo.');
            }
        }
        return parent::delete();
    }
}
