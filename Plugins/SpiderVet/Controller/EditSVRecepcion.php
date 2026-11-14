<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetEditController;

class EditSVRecepcion extends VetEditController
{
    public function getModelClassName(): string
    {
        return 'SVRecepcion';
    }
}
