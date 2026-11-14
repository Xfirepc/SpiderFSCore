<?php

namespace FacturaScripts\Plugins\SpiderVet\Model;

/** Customer tabs expose navigation only; writes use the patient's own controller permissions. */
class SVClientePaciente extends SVPaciente
{
    public function modelClassName(): string
    {
        return 'SVPaciente';
    }

    public function save(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
