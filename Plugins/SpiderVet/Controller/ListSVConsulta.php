<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetListController;

class ListSVConsulta extends VetListController
{
    public function modelName(): string
    {
        return 'SVConsulta';
    }
}
