<?php

/** Concurrencia real en MariaDB desechable; no carga configuración de una instancia. */
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Lib\SchemaCheckLock;
use FacturaScripts\Core\Lib\TableSchemaCache;
use FacturaScripts\Core\Tools;

if (getenv('SCHEMA_DISPOSABLE_DB') !== '1') {
    throw new RuntimeException('Esta prueba requiere un contenedor MariaDB aislado.');
}

$mode = $argv[1] ?? 'suite';
$database = $argv[3] ?? 'schema_hotfix_a';
if (!preg_match('/^schema_hotfix_[a-z]+$/D', $database)) {
    throw new RuntimeException('Base ajena al fixture de prueba.');
}
define('FS_FOLDER', $argv[2] ?? '/tmp/schema-hotfix-' . getmypid());
define('FS_DB_NAME', $database);
define('FS_DB_HOST', '127.0.0.1');
define('FS_DB_PORT', 3306);
define('FS_DB_USER', 'root');
define('FS_DB_PASS', 'schema-tests-only');
define('FS_DB_TYPE', 'mysql');
define('FS_DB_TYPE_CHECK', true);
define('FS_LANG', 'es_EC');
define('FS_ITEM_LIMIT', 50);
define('FS_ROUTE', '');
define('FS_DEBUG', false);
define('FS_DB_FOREIGN_KEYS', false);
define('FS_MYSQL_CHARSET', 'utf8mb4');
define('FS_MYSQL_COLLATE', 'utf8mb4_general_ci');
require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_dir(FS_FOLDER . '/MyFiles')) {
    mkdir(FS_FOLDER . '/MyFiles', 0775, true);
}

if ($mode === 'baseline') {
    require '/baseline/MysqlEngine.php';
    require '/baseline/DbUpdater.php';
}

if (in_array($mode, ['worker', 'baseline'], true)) {
    file_put_contents(FS_FOLDER . '/ready-' . getmypid(), 'ready');
    while (!is_file(FS_FOLDER . '/go')) {
        usleep(1000);
    }
    $structure = ['columns' => [
        ['name' => 'id', 'type' => 'int', 'null' => 'YES', 'default' => ''],
        ['name' => 'numtickets', 'type' => 'int', 'null' => 'YES', 'default' => ''],
        ['name' => 'new_value', 'type' => 'int', 'null' => 'YES', 'default' => ''],
    ], 'constraints' => []];
    $start = microtime(true);
    DbUpdater::updateTable('probe', $structure);
    echo json_encode(['milliseconds' => (microtime(true) - $start) * 1000,
        'checked' => DbUpdater::isTableChecked('probe'),
        'errors' => count(MiniLog::read('database', ['error', 'critical'])),
        'peak_memory' => memory_get_peak_usage(true)]), PHP_EOL;
    exit;
}

if ($mode === 'cache-writer') {
    $generation = TableSchemaCache::generation();
    for ($index = 0; $index < 40; $index++) {
        TableSchemaCache::remember($database, $argv[4] . '-' . $index, $generation);
        usleep(1000);
    }
    echo json_encode(['saved' => 40]), PHP_EOL;
    exit;
}

if ($mode === 'hold-lock') {
    $lock = new SchemaCheckLock($database);
    $lock->acquire();
    file_put_contents(FS_FOLDER . '/locked', 'locked');
    while (!is_file(FS_FOLDER . '/release')) {
        usleep(1000);
    }
    $lock->release();
    echo '{}', PHP_EOL;
    exit;
}

function schemaCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function schemaWorker(string $mode, string $folder, string $database, string $key = ''): array
{
    $process = proc_open([PHP_BINARY, __FILE__, $mode, $folder, $database, $key],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    schemaCheck(is_resource($process), 'No se pudo crear un proceso de prueba.');
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes];
}

function schemaWait(array $workers, ?callable $observe = null): array
{
    $result = [];
    $deadline = microtime(true) + 45;
    while ($workers) {
        foreach ($workers as $index => $worker) {
            $state = proc_get_status($worker['process']);
            if ($state['running']) {
                continue;
            }
            $out = stream_get_contents($worker['pipes'][1]);
            $error = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
            schemaCheck($state['exitcode'] === 0, 'Falló un proceso de prueba: ' . $error);
            $data = json_decode(trim($out), true);
            schemaCheck(is_array($data), 'Respuesta inválida de un proceso: ' . $out . $error);
            $result[] = $data;
            unset($workers[$index]);
        }
        if ($observe) {
            $observe();
        }
        schemaCheck(microtime(true) < $deadline, 'Los procesos de prueba no finalizaron.');
        usleep(1000);
    }
    return $result;
}

$server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', FS_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$results = [];
foreach (['baseline', 'worker'] as $variant) {
    $db = $variant === 'baseline' ? 'schema_hotfix_baseline' : 'schema_hotfix_candidate';
    $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $server->exec('CREATE DATABASE `' . $db . '`');
    $server->exec('CREATE TABLE `' . $db . '`.probe (id int NULL, numtickets int NULL) ENGINE=InnoDB');
    $server->exec('INSERT INTO `' . $db . '`.probe VALUES (1,10),(2,20)');
    $folder = FS_FOLDER . '/' . $variant;
    mkdir($folder . '/MyFiles', 0775, true);
    $before = (int)$server->query("SHOW GLOBAL STATUS LIKE 'Com_alter_table'")->fetch(PDO::FETCH_NUM)[1];
    $workers = [];
    for ($index = 0; $index < 8; $index++) {
        $workers[] = schemaWorker($variant, $folder, $db);
    }
    $deadline = microtime(true) + 20;
    while (count(glob($folder . '/ready-*')) < 8) {
        schemaCheck(microtime(true) < $deadline, 'Los procesos no alcanzaron la barrera.');
        usleep(1000);
    }
    file_put_contents($folder . '/go', 'go');
    $responses = schemaWait($workers);
    $after = (int)$server->query("SHOW GLOBAL STATUS LIKE 'Com_alter_table'")->fetch(PDO::FETCH_NUM)[1];
    $rows = $server->query('SELECT id,numtickets FROM `' . $db . '`.probe ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    schemaCheck($rows == [[1, 10], [2, 20]], 'Una comprobación del esquema alteró los datos.');
    $results[$variant] = ['ddl' => $after - $before, 'workers' => $responses, 'rows_preserved' => true];
    if ($variant === 'worker') {
        schemaCheck($after - $before === 1, 'Debe ejecutarse un único ALTER para añadir la columna real.');
        schemaCheck(array_sum(array_column($responses, 'errors')) === 0, 'La concurrencia nueva produjo errores SQL.');
        schemaCheck(!in_array(false, array_column($responses, 'checked'), true), 'Quedó una tabla sin comprobar.');
    }
}

$cacheFolder = FS_FOLDER . '/cache';
mkdir($cacheFolder . '/MyFiles', 0775, true);
$workers = [];
for ($index = 0; $index < 12; $index++) {
    $workers[] = schemaWorker('cache-writer', $cacheFolder, $index % 2 ? 'schema_hotfix_a' : 'schema_hotfix_b', 'writer-' . $index);
}
$reads = 0;
schemaWait($workers, static function () use ($cacheFolder, &$reads): void {
    $path = $cacheFolder . '/MyFiles/db-updater.json';
    if (is_file($path)) {
        schemaCheck(is_array(json_decode(file_get_contents($path), true)), 'Se observó un JSON parcial.');
        $reads++;
    }
});
$map = json_decode(file_get_contents($cacheFolder . '/MyFiles/db-updater.json'), true);
schemaCheck(count($map['schema_hotfix_a']) === 240 && count($map['schema_hotfix_b']) === 240, 'Se perdieron comprobaciones concurrentes.');
$results['cache'] = ['writers' => 12, 'saved' => 480, 'consistent_reads' => $reads, 'lost' => 0];

$lockFolder = FS_FOLDER;
$holder = schemaWorker('hold-lock', $lockFolder, 'schema_hotfix_a');
$deadline = microtime(true) + 10;
while (!is_file($lockFolder . '/locked')) {
    schemaCheck(microtime(true) < $deadline, 'No se obtuvo el bloqueo de la primera base.');
    usleep(1000);
}
// El padre usa otra base y no debe esperar el bloqueo de la primera.
$start = microtime(true);
$other = new SchemaCheckLock('schema_hotfix_b');
$other->acquire();
$other->release();
schemaCheck(microtime(true) - $start < 0.5, 'El bloqueo de un tenant detuvo otro tenant.');
file_put_contents($lockFolder . '/release', 'release');
schemaWait([$holder]);
$results['different_databases_independent'] = true;
echo json_encode($results, JSON_PRETTY_PRINT), PHP_EOL;
