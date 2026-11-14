<?php

namespace FacturaScripts\Plugins\SpiderVet\Extension\Model;

use Closure;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;

class Cliente
{
    protected function save(): Closure
    {
        return function () {
            $db = new DataBase();
            if ($db->tableExists('sv_pacientes')) {
                $db->exec('UPDATE sv_pacientes SET nombretutor = ' . $db->var2str($this->nombre)
                    . ' WHERE codcliente = ' . $db->var2str($this->codcliente));
                Cache::deleteMulti('table-sv_pacientes-');
            }
        };
    }
}
