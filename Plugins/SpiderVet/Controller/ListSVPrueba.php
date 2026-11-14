<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetListController;

class ListSVPrueba extends VetListController
{
    public function modelName(): string
    {
        return 'SVPrueba';
    }
}
