<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetEditController;

class EditSVPrueba extends VetEditController
{
    public function getModelClassName(): string
    {
        return 'SVPrueba';
    }
}
