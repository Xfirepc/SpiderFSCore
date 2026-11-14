<?php
namespace FacturaScripts\Core\Lib\Security;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Email\PasswordRecoveryMail;
use FacturaScripts\Core\Model\PasswordReset;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use RuntimeException;
use Throwable;

class PasswordRecovery
{
    const MESSAGE = 'Si los datos corresponden a una cuenta habilitada con correo registrado, recibirás un enlace de recuperación. Si necesitas ayuda, contacta al administrador de tu empresa.';
    const INVALID_LINK = 'El enlace no es válido o ha caducado. Solicita uno nuevo.';

    public static function validPassword(string $password, string $confirmation): bool
    {
        return $password === $confirmation && mb_strlen($password, 'UTF-8') >= 12
            && strlen($password) <= 72 && strpos($password, "\0") === false;
    }

    public function request(string $identifier, string $ip, string $ruc): void
    {
        $limiter = new RecoveryRateLimiter();
        if (!$limiter->allow('ip:' . $ip, 10)) {
            return;
        }
        $identifier = trim($identifier);
        if ($identifier === '' || strlen($identifier) > 100) {
            return;
        }
        $user = new User();
        if (!$user->loadFromCode($identifier)) {
            $users = User::all([new DataBaseWhere('email', mb_strtolower($identifier, 'UTF-8'))], [], 0, 2);
            if (count($users) !== 1) {
                return;
            }
            $user = $users[0];
        }
        if (!$user->enabled || !filter_var($user->email, FILTER_VALIDATE_EMAIL)
            || !$limiter->allow('user:' . $user->nick, 3)) {
            return;
        }
        $baseUrl = rtrim((string)Tools::settings('default', 'site_url', ''), '/');
        $parts = parse_url($baseUrl);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Configura la URL pública de la instalación para recuperar contraseñas.');
        }
        $mail = $this->createMailer();
        if (!$mail->canSendMail()) {
            throw new RuntimeException('Configura el correo de la instalación para recuperar contraseñas.');
        }
        // Preparar el esquema antes de abrir la transacción: el DDL confirma implícitamente en MySQL.
        $reset = new PasswordReset();
        $token = bin2hex(random_bytes(32));
        $db = new DataBase();
        if ($db->inTransaction() || !$db->beginTransaction()) {
            throw new RuntimeException('No se pudo iniciar la recuperación.');
        }
        try {
            $rows = User::allForUpdate([new DataBaseWhere('nick', $user->nick)], [], 0, 1);
            if (!$rows || !$rows[0]->enabled || $rows[0]->email !== $user->email) {
                $db->rollback();
                return;
            }
            $user = $rows[0];
            $reset->loadFromCode($user->nick);
            $reset->nick = $user->nick;
            $reset->token_hash = hash('sha256', $token);
            $reset->expires_at = time() + 1800;
            $reset->email = $user->email;
            $reset->password_fingerprint = hash('sha256', $user->password);
            if (!$reset->save() || !$db->commit()) {
                throw new RuntimeException('No se pudo guardar la recuperación.');
            }
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
        // El fragmento nunca llega a los registros HTTP ni a encabezados Referer.
        $url = $baseUrl . '/login?action=reset-password&recovery_ruc=' . rawurlencode($ruc)
            . '&recovery_user=' . rawurlencode($user->nick) . '#token=' . $token;
        $mail->to($user->email);
        $mail->subject('Recupera tu contraseña');
        $mail->body('<p>Para elegir una nueva contraseña, abre este enlace:</p><p><a href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Restablecer contraseña</a></p>'
            . '<p>Caduca en 30 minutos y solo puede utilizarse una vez. Si no lo solicitaste, ignora este correo.</p>');
        if (!$mail->send()) {
            throw new RuntimeException('No se pudo enviar el correo de recuperación.');
        }
    }

    public function reset(string $nick, string $token, string $password, string $confirmation): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !self::validPassword($password, $confirmation)) {
            return false;
        }
        new User();
        new PasswordReset();
        $db = new DataBase();
        if ($db->inTransaction() || !$db->beginTransaction()) {
            return false;
        }
        try {
            // Siempre usuario primero: mismo orden que al emitir o renovar un enlace.
            $users = User::allForUpdate([new DataBaseWhere('nick', $nick)], [], 0, 1);
            $resets = PasswordReset::allForUpdate([new DataBaseWhere('nick', $nick)], [], 0, 1);
            if (!$users || !$resets || !$this->matches($users[0], $resets[0], $token)) {
                $db->rollback();
                return false;
            }
            $user = $users[0];
            $user->setPassword($password);
            $user->logkey = Tools::randomString(99);
            if (!$user->save() || !$resets[0]->delete() || !$db->commit()) {
                throw new RuntimeException('No se pudo confirmar el cambio de contraseña.');
            }
            return true;
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }

    protected function createMailer(): PasswordRecoveryMail
    {
        return new PasswordRecoveryMail();
    }

    protected function matches(User $user, PasswordReset $reset, string $token): bool
    {
        return $user->enabled && (int)$reset->expires_at > time()
            && $reset->email === $user->email
            && hash_equals($reset->token_hash, hash('sha256', $token))
            && hash_equals($reset->password_fingerprint, hash('sha256', $user->password));
    }
}
