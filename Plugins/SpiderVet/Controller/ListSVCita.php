<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetListController;

class ListSVCita extends VetListController
{
    public function modelName(): string
    {
        return 'SVCita';
    }
}
