<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;

class ClinicalContext
{
    public static function capture(SVPaciente $patient): array
    {
        $customer = new Cliente();
        $customer->loadFromCode($patient->codcliente);
        $address = [];
        $db = new DataBase();
        if (!empty($customer->idcontactofact) && $db->tableExists('contactos')) {
            $rows = $db->select('SELECT * FROM contactos WHERE idcontacto = '
                . $db->var2str($customer->idcontactofact));
            $address = $rows[0] ?? [];
        }
        return [
            'patient' => $patient->toArray(),
            'tutor' => [
                'nombre' => $customer->nombre, 'documento' => $customer->cifnif,
                'telefono' => trim(($customer->telefono1 ?? '') . ' ' . ($customer->telefono2 ?? '')),
                'correo' => $customer->email,
                'direccion' => $address['direccion'] ?? $customer->direccion,
                'ciudad' => trim(($address['ciudad'] ?? '') . ' ' . ($address['provincia'] ?? '')),
            ],
        ];
    }
}
