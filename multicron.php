<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Plugins/SpiderTools/vendor/autoload.php';
require_once __DIR__ . '/proxy.php';

// cargamos la configuración
const FS_FOLDER = __DIR__;
$configFile = getConfigFile();

if (file_exists($configFile)) {
    require_once $configFile;
}

// desactivamos el tiempo de ejecución y el aborto de la conexión
@set_time_limit(0);
ignore_user_abort(true);


// Usar cliente de guzzle para enviar una solicitud POST a una URL específica pasandole headers y parametros
// Ejecutar docker exec -it multispider-php-1 php multicron.php --uri=http://multispider.mm
$opt = getopt('', ['uri::']);

use GuzzleHttp\Client;
$client = new Client([
    'base_uri' => $opt['uri'],
    'timeout'  => 120.0,
]);


$installations = (new \FacturaScripts\Dinamic\Model\SBInstallation())->all();
foreach ($installations as $installation) {
    $ruc = $installation->cifnif;
    try {
        $response = $client->request('POST', '/cron', [
            'headers' => [
                'X-RUC' => $ruc,
            ],
        ]);
        echo "Cron ejecutado para {$ruc} {$installation->nombrecorto}";
        echo $response->getBody()->getContents();
    } catch (Exception $exception) {
        echo 'Error al ejecutar cron para ' . $ruc . "\n";
        echo $exception->getMessage();
    }
}
