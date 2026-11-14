<?php
namespace FacturaScripts\Core\Lib\Email;

/** Los enlaces de acceso no se copian ni archivan como correspondencia comercial. */
class PasswordRecoveryMail extends NewMail
{
    public function __construct()
    {
        parent::__construct();
        $this->mail->clearAllRecipients();
        $this->signature = '';
        $this->mail->Timeout = 10;
        $this->mail->SMTPDebug = 0;
    }

    protected function saveMailSent(): void
    {
        // El enlace secreto solo se entrega al buzón del titular.
    }
}
