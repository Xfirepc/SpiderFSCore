<?php
/** Comprobación aislada: no carga configuración, aplicación ni bases de datos. */
define('FS_DB_NAME', 'migration_token_test');
define('FS_DB_PASS', 'new_database_password');
if (($argv[1] ?? '') === 'pinned') {
    define('FS_FILES_TOKEN_SECRET', 'previous_token_secret');
}
require dirname(__DIR__, 2) . '/Core/Base/MyFilesToken.php';

use FacturaScripts\Core\Base\MyFilesToken;

MyFilesToken::setCurrentDate('13-11-2026');
$secret = defined('FS_FILES_TOKEN_SECRET') ? FS_FILES_TOKEN_SECRET : FS_DB_PASS;
$path = 'MyFiles/archivo-prueba.xml';
$permanent = sha1(FS_DB_NAME . $secret . $path);
$daily = sha1(FS_DB_NAME . $secret . $path . '13-11-2026');
$expiration = '2030-01-01';
$expiring = sha1(FS_DB_NAME . $secret . $path . $expiration) . '|' . $expiration;
$checks = [
    MyFilesToken::get($path, true) === $permanent,
    MyFilesToken::get('/' . $path, true) === $permanent,
    MyFilesToken::validate($path, $permanent),
    MyFilesToken::validate($path, $daily),
    MyFilesToken::validate($path, $expiring),
    !MyFilesToken::validate($path, 'invalid-token'),
    !MyFilesToken::validate($path, sha1(FS_DB_NAME . $secret . $path . '2020-01-01') . '|2020-01-01'),
    !MyFilesToken::validate('MyFiles/otro-archivo.xml', $permanent),
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "FAIL: compatibilidad de tokens de archivos.\n");
    exit(1);
}
echo 'PASS: ' . count($checks) . ' comprobaciones; modo ' . ($argv[1] ?? 'fallback') . ".\n";
