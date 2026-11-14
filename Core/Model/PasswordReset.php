<?php
namespace FacturaScripts\Core\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

/** Un único enlace vigente por usuario, dentro de la base de su empresa. */
class PasswordReset extends ModelClass
{
    use ModelTrait;

    public $nick;
    public $token_hash;
    public $expires_at;
    public $email;
    public $password_fingerprint;

    public static function primaryColumn(): string
    {
        return 'nick';
    }

    public static function tableName(): string
    {
        return 'password_resets';
    }
}
