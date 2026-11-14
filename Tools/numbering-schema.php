<?php

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\DocumentNumberingSchema;

/** Herramienta de mantenimiento; no se carga desde solicitudes de la aplicación. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$options = getopt('', ['config:', 'root:', 'apply', 'rollback', 'help']);
if (isset($options['help']) || !isset($options['config'])) {
    echo "Uso: php Tools/numbering-schema.php --config=/ruta/tenant.php [--root=/ruta/html] [--apply|--rollback]\n";
    echo "Sin acción inspecciona el esquema. --apply y --rollback requieren mantenimiento de esa base.\n";
    exit(isset($options['help']) ? 0 : 2);
}
if (isset($options['apply'], $options['rollback'])) {
    fwrite(STDERR, "Elija una sola acción.\n");
    exit(2);
}

try {
    $configuration = realpath($options['config']);
    $folder = realpath($options['root'] ?? dirname(__DIR__));
    if (!$configuration || !is_file($configuration) || !$folder || !is_dir($folder)) {
        throw new RuntimeException('La configuración y la carpeta de la instalación deben existir.');
    }
    $header = file_get_contents($configuration, false, null, 0, 64);
    if (!is_string($header) || !preg_match('/^\s*<\?php\b/', $header)) {
        throw new RuntimeException('Especifique una configuración PHP válida.');
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    ob_start();
    try {
        require $configuration;
    } catch (Throwable $error) {
        throw new RuntimeException('No se pudo cargar la configuración PHP indicada.');
    } finally {
        ob_end_clean();
    }
    if (!defined('FS_FOLDER')) {
        define('FS_FOLDER', $folder);
    }
    $database = new DataBase();
    if (!$database->connect()) {
        throw new RuntimeException('No se pudo conectar con la base de la configuración indicada.');
    }
    $schema = new DocumentNumberingSchema($database);
    $result = isset($options['apply']) ? $schema->upgrade()
        : (isset($options['rollback']) ? $schema->rollback() : $schema->inspect());
    echo json_encode(['database' => FS_DB_NAME, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
