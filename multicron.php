<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Plugins/SpiderTools/vendor/autoload.php';
require_once __DIR__ . '/proxy.php';

const FS_FOLDER = __DIR__;
$configFile = getConfigFile();

if (file_exists($configFile)) {
    require_once $configFile;
}

@set_time_limit(0);
ignore_user_abort(true);

$lockDir = FS_FOLDER . '/MyFiles';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/.multicron.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "multicron: no se pudo abrir $lockFile\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "multicron: ya hay una ejecución en curso, se omite este ciclo\n";
    fclose($lock);
    exit(0);
}

register_shutdown_function(static function () use ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
});

$opt = getopt('', ['uri::']);

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => $opt['uri'],
    'timeout' => 45.0,
    'connect_timeout' => 5.0,
    'http_errors' => false,
]);

$started = microtime(true);
$ok = 0;
$failed = 0;
$skipped = 0;

$where = [new DataBaseWhere('active', true)];
$installations = (new \FacturaScripts\Dinamic\Model\SBInstallation())->all($where);
foreach ($installations as $installation) {
    $ruc = trim((string) $installation->cifnif);
    if ($ruc === '') {
        $skipped++;
        continue;
    }

    try {
        $response = $client->request('POST', '/cron', [
            'headers' => [
                'X-RUC' => $ruc,
            ],
        ]);
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($code >= 200 && $code < 300) {
            $ok++;
            echo "Cron ejecutado para {$ruc} {$installation->nombrecorto}\n";
            echo $body;
        } else {
            $failed++;
            echo "Error HTTP {$code} al ejecutar cron para {$ruc} {$installation->nombrecorto}\n";
            echo $body;
        }
    } catch (Exception $exception) {
        $failed++;
        echo 'Error al ejecutar cron para ' . $ruc . "\n";
        echo $exception->getMessage() . "\n";
    }
}

$elapsed = round(microtime(true) - $started, 1);
echo "\nmulticron: ok={$ok} fail={$failed} skip={$skipped} {$elapsed}s\n";
