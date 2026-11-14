<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib\Controller;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\SpiderVet\Lib\Access;
use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;
use FacturaScripts\Plugins\SpiderVet\Lib\PatientSearch;
use Symfony\Component\HttpFoundation\Request;

class SelectOptions
{
    public static function autocomplete(Request $request, User $user, string $modelName): array
    {
        $source = $request->get('source', '');
        $allowed = [];
        foreach (FieldCatalog::get($modelName)['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (!empty($field['source'])) {
                    $allowed[$field['source']] = $field;
                }
            }
        }
        if (!isset($allowed[$source])) {
            return [];
        }
        $field = $allowed[$source];
        $query = (string) $request->get('term', '');
        if ($source === 'sv_pacientes') {
            $permissions = new ControllerPermissions($user, 'EditSVPaciente');
            if (!$permissions->allowAccess) {
                return [];
            }
            $models = PatientSearch::find($query, $user, $permissions);
        } elseif ($source === 'clientes') {
            $permissions = new ControllerPermissions($user, 'EditCliente');
            $where = [new DataBaseWhere('nombre|cifnif|codcliente', $query, 'LIKE')];
            if ($permissions->onlyOwnerData) {
                $where[] = new DataBaseWhere('codagente', $user->codagente ?: '-');
            }
            $models = Cliente::all($where);
        } elseif ($source === 'users') {
            $models = User::all([new DataBaseWhere('nick', $query, 'LIKE')]);
        } else {
            $map = ['sv_citas' => 'SVCita', 'sv_recepciones' => 'SVRecepcion', 'sv_consultas' => 'SVConsulta'];
            if (!isset($map[$source])) {
                return [];
            }
            $class = 'FacturaScripts\\Plugins\\SpiderVet\\Model\\' . $map[$source];
            $permissions = new ControllerPermissions($user, 'Edit' . $map[$source]);
            if (!$permissions->allowAccess) {
                return [];
            }
            $where = Access::where($user, $permissions);
            if ($request->get('idpaciente')) {
                $where[] = new DataBaseWhere('idpaciente', (int) $request->get('idpaciente'));
            }
            if ($query !== '') {
                $where[] = new DataBaseWhere('id|motivo', $query, 'LIKE');
            }
            $models = $class::all($where, ['id' => 'DESC']);
        }
        $results = [];
        foreach ($models as $model) {
            $value = $source === 'sv_pacientes'
                ? $model->historia . ' · ' . $model->nombre . ' · ' . $model->nombretutor
                : ($model->{$field['display']} ?: '#' . $model->{$field['code']});
            $results[] = ['key' => $model->{$field['code']},
                'value' => html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8')];
        }
        return $results;
    }
}
