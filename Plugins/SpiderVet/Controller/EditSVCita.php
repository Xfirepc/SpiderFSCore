<?php

namespace FacturaScripts\Plugins\SpiderVet\Controller;

use FacturaScripts\Plugins\SpiderVet\Lib\Controller\VetEditController;

class EditSVCita extends VetEditController
{
    public function getModelClassName(): string
    {
        return 'SVCita';
    }
}
