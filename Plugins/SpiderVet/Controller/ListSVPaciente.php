<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetListController;

class ListSVPaciente extends VetListController
{
    public function modelName(): string
    {
        return 'SVPaciente';
    }
}
