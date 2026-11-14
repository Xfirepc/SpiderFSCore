<?php

/** Solo se ejecuta en el contenedor sin red externa del script adjunto. */

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Email\PasswordRecoveryMail;
use FacturaScripts\Core\Lib\Security\PasswordRecovery;
use FacturaScripts\Core\Lib\Security\RecoveryRateLimiter;
use FacturaScripts\Core\Model\PasswordReset;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

if (getenv('RECOVERY_ISOLATED') !== '1') {
    throw new RuntimeException('Usa run-recovery-integration.sh.');
}
define('FS_FOLDER', '/work/runtime');
foreach (
    ['DB_TYPE' => 'mysql','DB_HOST' => '127.0.0.1','DB_PORT' => 3306,'DB_USER' => 'root','DB_PASS' => 'recovery-test-only',
    'DB_FOREIGN_KEYS' => true,'DB_NAME' => (($argv[1] ?? '') === 'tenant' ? 'recovery_other' : 'recovery_test'),'MYSQL_CHARSET' => 'utf8mb4','MYSQL_COLLATE' => 'utf8mb4_general_ci','LANG' => 'es_EC','ITEM_LIMIT' => 50,'ROUTE' => '/erp',
    'NF0' => 2,'NF1' => '.','NF2' => ',','DEBUG' => false] as $key => $value
) {
    define('FS_' . $key, $value);
}
require '/source/vendor/autoload.php';
date_default_timezone_set('America/Guayaquil');
set_exception_handler(static function ($error) {
    fwrite(STDERR, (string)$error . '\n' . json_encode(\FacturaScripts\Core\Base\MiniLog::read(), JSON_PRETTY_PRINT) . '\n');
    exit(1);
});
function checkRecovery($value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', FS_DB_USER, FS_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mode = $argv[1] ?? 'suite';
if ($mode === 'setup') {
    $pdo->exec('CREATE DATABASE recovery_test CHARACTER SET utf8mb4');
    $pdo->exec('USE recovery_test');
    $schema = file_get_contents('/source/MyFiles/SpiderBuilder/main_db.sql');
    $schema = preg_replace('/^\/\*M!\d+\s+SET\b[^\r\n]*NOTE_VERBOSITY[^\r\n]*\*\/;\s*$/mi', '', $schema);
    $pdo->exec($schema);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("INSERT INTO empresas(idempresa,nombre,nombrecorto,cifnif) VALUES(1,'Pruebas','Pruebas','9900000000001')");
    $pdo->exec("INSERT INTO almacenes(codalmacen,nombre,idempresa) VALUES('1','Prueba',1)");
    $pdo->prepare('INSERT INTO settings(name,properties) VALUES(?,?)')->execute(['default',json_encode(['idempresa' => 1,'codalmacen' => '1','site_url' => 'https://example.test/erp'])]);
    $pdo->prepare('INSERT INTO settings(name,properties) VALUES(?,?)')->execute(['email',json_encode(['email' => 'sender@example.test','host' => '127.0.0.1','password' => 'test','mailer' => 'smtp','port' => 1025,'emailcc' => 'copy@example.test','emailbcc' => 'hidden@example.test'])]);
    foreach (['alice','shared1','shared2','disabled','noemail','raceuser'] as $nick) {
        $email = strpos($nick, 'shared') === 0 ? 'shared@example.test' : ($nick === 'noemail' ? '' : $nick . '@example.test');
        $pdo->prepare('INSERT INTO users(nick,password,email,enabled,admin,idempresa,codalmacen,langcode,logkey) VALUES(?,?,?,?,1,1,?, ?,?)')->execute([$nick,password_hash('Old password 123', PASSWORD_DEFAULT),$email,$nick === 'disabled' ? 0 : 1,'1','es_EC','old-session']);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    file_put_contents(FS_FOLDER . '/MyFiles/db-updater.json', json_encode([FS_DB_NAME => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)]));
    // Verifica la migración XML real del nuevo modelo, sin configurar una instancia.
    new PasswordReset();
    $pdo->exec('CREATE DATABASE recovery_other CHARACTER SET utf8mb4');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('CREATE TABLE recovery_other.`' . $table . '` LIKE recovery_test.`' . $table . '`');
    }
    $pdo->exec('INSERT INTO recovery_other.users SELECT * FROM recovery_test.users');
    $checked = json_decode(file_get_contents(FS_FOLDER . '/MyFiles/db-updater.json'), true);
    $checked['recovery_other'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    file_put_contents(FS_FOLDER . '/MyFiles/db-updater.json', json_encode($checked));
    echo "Esquema aislado preparado\n";
    exit;
}
$pdo->exec('USE ' . FS_DB_NAME);
if ($mode === 'tenant') {
    checkRecovery(!(new PasswordRecovery())->reset('raceuser', file_get_contents('/work/token'), 'Other tenant pass 123', 'Other tenant pass 123'), 'El token de otra empresa no autoriza cambios');
    echo "Aislamiento entre bases confirmado\n";
    exit;
}
if ($mode === 'race') {
    $ok = (new PasswordRecovery())->reset('raceuser', file_get_contents('/work/token'), 'Concurrent password 123', 'Concurrent password 123');
    echo $ok ? 'winner' : 'rejected';
    exit;
}
class CapturedRecoveryMail extends PasswordRecoveryMail
{
    public static $body;
    public static $recipients;
    public static $fail = false;
    public function send(): bool
    {
        self::$body = $this->text;
        self::$recipients = [$this->getToAddresses(), $this->getCCAddresses(), $this->getBCCAddresses()];
        return !self::$fail;
    }
}
class RecoveryProbe extends PasswordRecovery
{
    protected function createMailer(): PasswordRecoveryMail
    {
        return new CapturedRecoveryMail();
    }
}
function clearRates(): void
{
    foreach (glob(FS_FOLDER . '/MyFiles/PasswordRecovery/*') as $file) {
        unlink($file);
    }
}
function issue(string $identifier): string
{
    CapturedRecoveryMail::$body = null;
    (new RecoveryProbe())->request($identifier, '192.0.2.1', '9900000000001');
    preg_match('/#token=([a-f0-9]{64})/', CapturedRecoveryMail::$body ?? '', $match);
    return $match[1] ?? '';
}
$service = new RecoveryProbe();
$token = issue('alice');
checkRecovery(strlen($token) === 64, 'Emisión por usuario');
checkRecovery(CapturedRecoveryMail::$recipients === [['alice@example.test'],[],[]], 'Sin CC/BCC');
checkRecovery(strpos(CapturedRecoveryMail::$body, 'https://example.test/erp/login?') !== false, 'URL pública y subruta');
checkRecovery(strpos(CapturedRecoveryMail::$body, 'recovery_ruc=9900000000001') !== false, 'Empresa en enlace');
$row = $pdo->query("SELECT * FROM password_resets WHERE nick='alice'")->fetch(PDO::FETCH_ASSOC);
checkRecovery($row['token_hash'] === hash('sha256', $token), 'Solo hash persistido');
checkRecovery(abs($row['expires_at'] - time() - 1800) < 5, 'Vigencia 30 minutos');
$new = issue('ALICE@example.test');
checkRecovery($new !== '' && $new !== $token, 'Emisión por correo normalizado');
checkRecovery(!$service->reset('alice', $token, 'New password 123', 'New password 123'), 'Enlace anterior invalidado');
checkRecovery(!$service->reset('shared1', $new, 'New password 123', 'New password 123'), 'No cambiar otra cuenta');
checkRecovery(!$service->reset('alice', $new, 'short', 'short'), 'Rechazo contraseña corta');
checkRecovery(!$service->reset('alice', $new, 'New password 123', 'Other password 123'), 'Confirmación obligatoria');
checkRecovery(!PasswordRecovery::validPassword(str_repeat('á', 40), str_repeat('á', 40)), 'Límite bytes Unicode');
checkRecovery(!PasswordRecovery::validPassword("Password long\0x", "Password long\0x"), 'Rechazo nulos');
checkRecovery($service->reset('alice', $new, 'New password 123', 'New password 123'), 'Cambio efectivo');
$user = new User();
$user->loadFromCode('alice');
checkRecovery($user->verifyPassword('New password 123') && !$user->verifyPassword('Old password 123'), 'Contraseñas nueva y anterior');
checkRecovery($user->logkey !== 'old-session', 'Sesiones invalidadas');
checkRecovery(!$service->reset('alice', $new, 'Another password 123', 'Another password 123'), 'Consumo único');
clearRates();
foreach (['missing','disabled','noemail','shared@example.test'] as $identifier) {
    checkRecovery(issue($identifier) === '', 'Sin envío para ' . $identifier);
}
foreach (['expires_at','email','password'] as $field) {
    clearRates();
    $token = issue('alice');
    if ($field === 'expires_at') {
        $pdo->exec("UPDATE password_resets SET expires_at=1 WHERE nick='alice'");
    }
    if ($field === 'email') {
        $pdo->exec("UPDATE password_resets SET email='changed@example.test' WHERE nick='alice'");
    }
    if ($field === 'password') {
        $pdo->exec("UPDATE password_resets SET password_fingerprint='changed' WHERE nick='alice'");
    }
    checkRecovery(!$service->reset('alice', $token, 'Another password 123', 'Another password 123'), 'Rechazo estado modificado ' . $field);
}
clearRates();
for ($i = 0; $i < 3; $i++) {
    checkRecovery(issue('alice') !== '', 'Emisión permitida');
}
checkRecovery(issue('alice') === '', 'Límite cuenta');
clearRates();
$limiter = new RecoveryRateLimiter();
for ($i = 0; $i < 10; $i++) {
    checkRecovery($limiter->allow('ip:test', 10), 'Límite IP permite');
}
checkRecovery(!$limiter->allow('ip:test', 10), 'Límite IP rechaza');
clearRates();
$token = issue('alice');
$previous = $pdo->query("SELECT password FROM users WHERE nick='alice'")->fetchColumn();
$pdo->exec("CREATE TRIGGER reject_recovery_delete BEFORE DELETE ON password_resets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo de prueba'");
try {
    $service->reset('alice', $token, 'Rollback password 123', 'Rollback password 123');
    checkRecovery(false, 'El fallo de consumo debe abortar');
} catch (RuntimeException $error) {
    checkRecovery($pdo->query("SELECT password FROM users WHERE nick='alice'")->fetchColumn() === $previous, 'Rollback de contraseña al fallar el consumo');
    checkRecovery($pdo->query("SELECT COUNT(*) FROM password_resets WHERE nick='alice'")->fetchColumn() == 1, 'Token conservado tras rollback');
}
$pdo->exec('DROP TRIGGER reject_recovery_delete');
clearRates();
CapturedRecoveryMail::$fail = true;
try {
    issue('alice');
    checkRecovery(false, 'SMTP fallido debe fallar');
} catch (RuntimeException $error) {
    checkRecovery(true, 'SMTP fallido controlado');
}
CapturedRecoveryMail::$fail = false;
checkRecovery($pdo->query('SELECT COUNT(*) FROM emails_sent')->fetchColumn() == 0, 'No archivar enlaces');
file_put_contents('/work/token', issue('raceuser'));
// Selector multiempresa sin leer configuraciones ni conectar a las bases de las instancias.
require '/source/proxy.php';
$_SERVER['REQUEST_URI'] = '/erp/login?action=reset-password';
$_COOKIE['ruc'] = 'other';
$_GET = ['action' => 'reset-password','recovery_ruc' => '9900000000001'];
$_POST = [];
checkRecovery(getTenantSelection() === ['source' => 'recovery','value' => '9900000000001'], 'Enlace prevalece sobre cookie ajena');
$_GET['recovery_ruc'] = '';
checkRecovery(getTenantSelection() === ['source' => 'recovery','value' => ''], 'Enlace host sin RUC prevalece sobre cookie ajena');
$_GET['recovery_ruc'] = '9900000000001';
$_SERVER['REQUEST_URI'] = '/erp/ListUser';
checkRecovery(getTenantSelection()['value'] === 'other', 'No cambiar prioridad fuera del login');
$_SERVER['REQUEST_URI'] = '/erp/login';
$_GET['action'] = 'login';
checkRecovery(getTenantSelection()['value'] === 'other', 'Login habitual conserva prioridad');
// Envío real hacia Mailpit aislado; también verifica que no se archive.
clearRates();
(new PasswordRecovery())->request('shared1', '192.0.2.3', '9900000000001');
checkRecovery($pdo->query('SELECT COUNT(*) FROM emails_sent')->fetchColumn() == 0, 'SMTP real sin archivo');
// Controlador real con cookies antiguas y CSRF válido/inválido.
function renderRecovery(string $action, array $post = []): string
{
    $_SERVER['REQUEST_URI'] = '/erp/login';
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_GET = ['action' => $action];
    $_POST = $post;
    ob_start();
    (new \FacturaScripts\Core\Controller\Login('Login'))->run();
    return ob_get_clean();
}
$_COOKIE['fsNick'] = 'olduser';
$html = renderRecovery('recover-password');
checkRecovery(strpos($html, 'Contactar al administrador') !== false, 'Ayuda al administrador visible');
preg_match('/name="multireqtoken" value="([^"<]+)"/', $html, $csrf);
checkRecovery(!empty($csrf[1]), 'Token de formulario renderizado');
file_put_contents('/work/recovery.html', $html);
$html = renderRecovery('request-password-reset', ['action' => 'request-password-reset','identifier' => 'missing','multireqtoken' => $csrf[1]]);
checkRecovery(strpos($html, PasswordRecovery::MESSAGE) !== false, 'Respuesta pública genérica con cookie anterior');
$html = renderRecovery('request-password-reset', ['action' => 'request-password-reset','identifier' => 'missing','multireqtoken' => 'invalid']);
checkRecovery(strpos($html, 'La solicitud no es válida') !== false, 'CSRF inválido rechazado');
$html = renderRecovery('reset-password');
checkRecovery(strpos($html, 'autocomplete="new-password"') !== false, 'Formulario de nueva contraseña');
file_put_contents('/work/reset.html', $html);
$html = renderRecovery('change-password');
checkRecovery(strpos($html, 'fsDbPasswd') === false, 'Retirada recuperación pública con clave BD');
echo ($GLOBALS['checks'] ?? 0) . " comprobaciones correctas\n";
