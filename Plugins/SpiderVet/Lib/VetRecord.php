<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Base\ModelClass;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;
use Throwable;

/** Shared validation and transactional persistence for veterinary records. */
abstract class VetRecord extends ModelClass
{
    public $id;
    public $idempresa;
    public $nick;
    public $creado;
    public $actualizado;

    public function __construct(array $data = [])
    {
        if (!DbUpdater::isTableChecked(static::tableName())) {
            $db = new DataBase();
            $db->connect();
            if (!$db->tableExists('empresas')) {
                new Empresa();
            }
            if ($this->modelClassName() === 'SVPaciente') {
                if (!$db->tableExists('clientes')) {
                    new Cliente();
                }
                Schema::preparePatient($this);
            }
            $dependencies = [
                'SVCita' => ['SVPaciente'], 'SVRecepcion' => ['SVPaciente', 'SVCita'],
                'SVConsulta' => ['SVPaciente', 'SVRecepcion'],
                'SVPeluqueria' => ['SVPaciente', 'SVRecepcion'],
                'SVPrueba' => ['SVConsulta'], 'SVTratamiento' => ['SVConsulta'],
            ];
            foreach ($dependencies[$this->modelClassName()] ?? [] as $name) {
                $class = 'FacturaScripts\\Plugins\\SpiderVet\\Model\\' . $name;
                new $class();
            }
        }
        parent::__construct($data);
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        $fields = ['SVPaciente' => 'nombre', 'SVPrueba' => 'examen', 'SVTratamiento' => 'medicamento',
            'SVPeluqueria' => 'solicitado'];
        return $fields[$this->modelClassName()] ?? 'motivo';
    }

    public function clear()
    {
        parent::clear();
        $this->creado = $this->actualizado = Tools::dateTime();
        $defaults = [
            'SVPaciente' => ['activo' => true, 'especie' => 'canino', 'sexo' => 'desconocido',
                'reproductiva' => 'desconocida', 'habitat' => 'desconocido', 'vacunacion' => 'desconocida',
                'desparasitacion' => 'desconocida'],
            'SVCita' => ['estado' => 'programada', 'servicio' => 'veterinaria', 'inicio' => Tools::dateTime(),
                'fin' => date(Tools::DATETIME_STYLE, strtotime('+30 minutes'))],
            'SVRecepcion' => ['estado' => 'pendiente', 'servicio' => 'veterinaria', 'entrada' => Tools::dateTime()],
            'SVConsulta' => ['estado' => 'borrador', 'tipo' => 'primera', 'fecha' => Tools::dateTime(),
                'vacunacion' => 'desconocida', 'desparasitacion' => 'desconocida', 'ayuno' => 'no_definido',
                'llamadaseguimiento' => 'no_definido', 'conformidad' => 'pendiente'],
            'SVPrueba' => ['fecha' => Tools::date()],
            'SVPeluqueria' => ['estado' => 'borrador', 'inicio' => Tools::dateTime()],
        ];
        foreach ($defaults[$this->modelClassName()] ?? [] as $field => $value) {
            $this->{$field} = $value;
        }
    }

    public function save(): bool
    {
        $db = new DataBase();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction && !$db->beginTransaction()) {
            return false;
        }
        try {
            $ok = parent::save() && $this->afterSave();
            if ($ownTransaction) {
                if (!$ok) {
                    $db->rollback();
                } else {
                    $ok = $db->commit();
                }
            }
            return $ok;
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollback();
            }
            Tools::log()->error('No se pudo guardar el registro veterinario.');
            Tools::log('database')->error($e->getMessage());
            return false;
        }
    }

    public function test(): bool
    {
        $this->actualizado = Tools::dateTime();
        foreach (FieldCatalog::get($this->modelClassName())['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $key = $field['key'];
                $value = $this->{$key};
                if ($field['kind'] === 'checkbox') {
                    $this->{$key} = in_array($value, [true, 1, '1', 'true', 'on'], true);
                    continue;
                }
                if ($value === null || $value === '') {
                    $this->{$key} = null;
                    if (!empty($field['required'])) {
                        return $this->invalid('Complete el campo ' . $field['label'] . '.');
                    }
                    continue;
                }
                if (strpos($key, 'id') === 0) {
                    if (!ctype_digit((string) $value) || (int) $value <= 0) {
                        return $this->invalid('Seleccione un registro válido en ' . $field['label'] . '.');
                    }
                    $this->{$key} = (int) $value;
                } elseif ($field['kind'] === 'number') {
                    if (!Validation::number($value, $field['min'] ?? 0, $field['max'] ?? null)) {
                        return $this->invalid('El valor de ' . $field['label'] . ' no es válido.');
                    }
                    $this->{$key} = (float) $value;
                } elseif (in_array($field['kind'], ['date', 'datetime'], true)) {
                    $this->{$key} = Validation::date($value, $field['kind'] === 'datetime');
                    if ($this->{$key} === null) {
                        return $this->invalid('La fecha de ' . $field['label'] . ' no es válida.');
                    }
                } else {
                    // noHtml is the native widget contract; decode first to keep repeated edits stable.
                    $this->{$key} = Tools::noHtml(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
                    if (!empty($field['required']) && $this->{$key} === '') {
                        return $this->invalid('Complete el campo ' . $field['label'] . '.');
                    }
                    if (isset($field['options']) && !isset($field['options'][$this->{$key}])) {
                        return $this->invalid('La opción de ' . $field['label'] . ' no es válida.');
                    }
                    if (mb_strlen((string) $this->{$key}) > ($field['maxlength'] ??
                        ($field['kind'] === 'textarea' ? 100000 : 150))) {
                        return $this->invalid('El campo ' . $field['label'] . ' es demasiado largo.');
                    }
                }
            }
        }
        if ($this->exists()) {
            $old = new static();
            $old->loadFromCode($this->id);
            foreach (['idempresa', 'idpaciente', 'idconsulta', 'idrecepcion', 'idcita'] as $key) {
                if (property_exists($this, $key) && (string) $old->{$key} !== (string) $this->{$key}) {
                    return $this->invalid('No se puede cambiar la vinculación de una atención ya registrada.');
                }
            }
        }
        return parent::test();
    }

    public function patient(): ?SVPaciente
    {
        $patient = new SVPaciente();
        return property_exists($this, 'idpaciente') && $patient->loadFromCode($this->idpaciente) ? $patient : null;
    }

    protected function validatePatient(): bool
    {
        $patient = $this->patient();
        if (!$patient || (!$patient->activo && !$this->exists())) {
            return $this->invalid('Seleccione un paciente activo.');
        }
        $this->idempresa = $patient->idempresa;
        $this->nick = $patient->nick;
        return true;
    }

    protected function afterSave(): bool
    {
        return true;
    }

    protected function invalid(string $message): bool
    {
        Tools::log()->warning($message);
        return false;
    }

    public function delete(): bool
    {
        // Clinical history is retained; only an empty draft can be removed.
        if (property_exists($this, 'estado') && in_array($this->estado,
            ['terminada', 'completada', 'recibida', 'en_atencion'], true)) {
            return $this->invalid('Conserve la atención terminada en el historial.');
        }
        $db = new DataBase();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction && !$db->beginTransaction()) {
            return false;
        }
        $ok = parent::delete();
        if ($ok && property_exists($this, 'idrecepcion') && $this->idrecepcion) {
            $ok = Workflow::syncReception($this->idrecepcion);
        }
        if ($ownTransaction) {
            if ($ok) {
                return $db->commit();
            }
            $db->rollback();
        }
        return $ok;
    }
}
