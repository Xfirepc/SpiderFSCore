<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SpiderVet\Model\SVCita;
use FacturaScripts\Plugins\SpiderVet\Model\SVConsulta;
use FacturaScripts\Plugins\SpiderVet\Model\SVPeluqueria;
use FacturaScripts\Plugins\SpiderVet\Model\SVRecepcion;

class Workflow
{
    public static function validateReception(VetRecord $record, string $service): bool
    {
        if (!$record->idrecepcion) {
            return true;
        }
        $reception = new SVRecepcion();
        if (!$reception->loadFromCode($record->idrecepcion)
            || (int) $record->idpaciente !== (int) $reception->idpaciente
            || !in_array($reception->servicio, [$service, 'ambos'], true)
            || $reception->estado === 'cancelada') {
            Tools::log()->warning('Seleccione una recepción del mismo paciente y servicio que esté vigente.');
            return false;
        }
        if ($record->count([new DataBaseWhere('idrecepcion', $record->idrecepcion),
            new DataBaseWhere('id', $record->id ?: -1, '!=')])) {
            Tools::log()->warning('Esta recepción ya tiene una atención de este servicio. Abra la existente.');
            return false;
        }
        return true;
    }

    public static function validateConsultation(VetRecord $record): bool
    {
        $consultation = new SVConsulta();
        if (!$consultation->loadFromCode($record->idconsulta)) {
            Tools::log()->warning('Seleccione una consulta existente.');
            return false;
        }
        $record->idempresa = $consultation->idempresa;
        $record->nick = $consultation->nick;
        return true;
    }

    public static function progress(int $receptionId, string $service): array
    {
        $where = [new DataBaseWhere('idrecepcion', $receptionId)];
        $clinical = new SVConsulta();
        $grooming = new SVPeluqueria();
        $hasClinical = $clinical->loadFromCode('', $where);
        $hasGrooming = $grooming->loadFromCode('', $where);
        return [
            'started' => $hasClinical || $hasGrooming,
            'completed' => ($service === 'peluqueria' || ($hasClinical && $clinical->estado === 'terminada'))
                && ($service === 'veterinaria' || ($hasGrooming && $grooming->estado === 'terminada')),
        ];
    }

    public static function syncReception(int $id): bool
    {
        $db = new DataBase();
        // Serialize the two services of a combined visit before calculating its state.
        if (!$db->select('SELECT id FROM sv_recepciones WHERE id = ' . $db->var2str($id) . ' FOR UPDATE')) {
            return false;
        }
        $reception = new SVRecepcion();
        if (!$reception->loadFromCode($id) || $reception->estado === 'cancelada') {
            return false;
        }
        $progress = self::progress($id, $reception->servicio);
        $reception->estado = $progress['completed'] ? 'completada' : ($progress['started'] ? 'en_atencion' : 'pendiente');
        return $reception->save();
    }

    public static function syncAppointment(SVRecepcion $reception): bool
    {
        if (!$reception->idcita) {
            return true;
        }
        $db = new DataBase();
        if (!$db->select('SELECT id FROM sv_citas WHERE id = '
            . $db->var2str($reception->idcita) . ' FOR UPDATE')) {
            return false;
        }
        $appointment = new SVCita();
        if (!$appointment->loadFromCode($reception->idcita)) {
            return false;
        }
        $appointment->estado = ['completada' => 'completada', 'cancelada' => 'cancelada'][$reception->estado] ?? 'recibida';
        return $appointment->save();
    }
}
