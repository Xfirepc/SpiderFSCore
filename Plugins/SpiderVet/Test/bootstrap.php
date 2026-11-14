<?php

// This fixture never loads the installation config or connects to tenant databases.
if (getenv('SPIDERVET_TEST') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Use Test/run-integration.sh in the isolated test container.');
}

define('FS_FOLDER', '/tmp/spidervet-test');
define('FS_DB_TYPE', 'mysql');
define('FS_DB_HOST', '127.0.0.1');
define('FS_DB_PORT', 3306);
define('FS_DB_NAME', 'spidervet_test');
define('FS_DB_USER', 'root');
define('FS_DB_PASS', 'spidervet-test-only');
define('FS_DB_FOREIGN_KEYS', true);
define('FS_DB_TYPE_CHECK', true);
define('FS_MYSQL_CHARSET', 'utf8mb4');
define('FS_MYSQL_COLLATE', 'utf8mb4_general_ci');
define('FS_LANG', 'es_EC');
define('FS_TIMEZONE', 'America/Guayaquil');
define('FS_ITEM_LIMIT', 50);
define('FS_DEBUG', true);
define('FS_ROUTE', '/vet-test');
define('SYS_VERSION', 'SpiderVet test');
define('GOOGLE_TAG_MANAGER', '');
define('FS_NF0', 2);
define('FS_NF1', '.');
define('FS_NF2', ',');
define('FS_COOKIES_EXPIRE', 3600);
date_default_timezone_set(FS_TIMEZONE);

foreach (['MyFiles', 'Dinamic/Controller', 'Dinamic/Table', 'Dinamic/XMLView', 'Dinamic/Translation', 'Dinamic/Assets/CSS',
    'Dinamic/Assets/JS', 'Dinamic/View', 'Plugins'] as $directory) {
    if (!is_dir(FS_FOLDER . '/' . $directory)) {
        mkdir(FS_FOLDER . '/' . $directory, 0700, true);
    }
}
if (!is_link(FS_FOLDER . '/Core')) {
    symlink('/app/Core', FS_FOLDER . '/Core');
    symlink('/app/node_modules', FS_FOLDER . '/node_modules');
    symlink('/app/Plugins/SpiderVet', FS_FOLDER . '/Plugins/SpiderVet');
}
foreach (['Table', 'XMLView', 'Assets/CSS', 'Assets/JS'] as $directory) {
    foreach (glob('/app/Plugins/SpiderVet/' . $directory . '/*') as $file) {
        $target = FS_FOLDER . '/Dinamic/' . $directory . '/' . basename($file);
        if (!file_exists($target)) {
            symlink($file, $target);
        }
    }
}
file_put_contents(FS_FOLDER . '/MyFiles/plugins.json', json_encode([
    ['name' => 'SpiderVet', 'enabled' => true, 'order' => 1],
]));

require '/app/vendor/autoload.php';
// Resolve Dinamic imports against native Core and the new plugin, without any installed tenant extensions.
$vetTestAutoload = function ($class) {
    $prefix = 'FacturaScripts\\Dinamic\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (strpos($relative, 'Controller\\') === 0) {
        $file = substr($relative, strlen('Controller\\')) . '.php';
        $plugin = is_file('/app/Plugins/SpiderVet/Controller/' . $file) ? 'SpiderVet' : '';
        if ($plugin || is_file('/app/Core/Controller/' . $file)) {
            $method = new ReflectionMethod(\FacturaScripts\Core\Base\PluginDeploy::class, 'linkPHPFile');
            $method->setAccessible(true);
            $method->invoke(new \FacturaScripts\Core\Base\PluginDeploy(), $file, 'Controller',
                $plugin ? 'Plugins' : 'Core', $plugin);
            require FS_FOLDER . '/Dinamic/Controller/' . $file;
            return;
        }
    }
    $target = 'FacturaScripts\\Plugins\\SpiderVet\\' . $relative;
    if (is_file('/app/Plugins/SpiderVet/' . str_replace('\\', '/', $relative) . '.php') && class_exists($target)) {
        class_alias($target, $class);
        return;
    }
    $target = 'FacturaScripts\\Core\\' . $relative;
    if (class_exists($target)) {
        class_alias($target, $class);
    }
};
spl_autoload_register($vetTestAutoload, true, true);

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;

$settings = new ReflectionProperty(Tools::class, 'settings');
$settings->setAccessible(true);
$settings->setValue(null, ['default' => ['idempresa' => 1, 'codpais' => 'ECU', 'coddivisa' => 'USD',
    'tipoidfiscal' => 'C.I.', 'homepage' => 'SpiderVet'], 'main_system' => ['menu_policy' => 'legacy']]);
$database = new DataBase();
if (!$database->connect()) {
    throw new RuntimeException('Cannot connect to the disposable SpiderVet database.');
}

if (!$database->tableExists('empresas')) {
    // Native catalog schemas, with unrelated fixture FKs omitted. SpiderVet FKs stay enabled.
    foreach (['empresas', 'clientes', 'users', 'pages', 'roles', 'roles_users', 'roles_access', 'settings'] as $table) {
        $structure = DbUpdater::readTableXml('/app/Core/Table/' . $table . '.xml');
        foreach ($structure['constraints'] as $key => $constraint) {
            if (strpos($constraint['constraint'], 'FOREIGN KEY') !== false) {
                unset($structure['constraints'][$key]);
            }
        }
        if (!DbUpdater::createTable($table, $structure)) {
            throw new RuntimeException('Cannot create fixture catalog ' . $table);
        }
    }
    $database->exec('ALTER TABLE clientes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    function vetSeed(string $table, array $values): void
    {
        $db = new DataBase();
        foreach ($db->getColumns($table) as $field) {
            if ($field['is_nullable'] === 'NO' && $field['default'] === null
                && !array_key_exists($field['name'], $values)) {
                $type = $field['type'];
                $values[$field['name']] = strpos($type, 'date') !== false || strpos($type, 'time') !== false
                    ? '2026-10-02 09:00:00' : (preg_match('/int|double|decimal|bool/', $type) ? 0 : '');
            }
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (';
        $sql .= implode(',', array_map([$db, 'var2str'], array_values($values))) . ')';
        if (!$db->exec($sql)) {
            throw new RuntimeException('Cannot seed fixture ' . $table . ': ' . implode('; ', array_column(
                (new \FacturaScripts\Core\Base\MiniLog('database'))->read(), 'message')));
        }
    }
    vetSeed('empresas', ['idempresa' => 1, 'nombre' => 'Clínica Veterinaria HOPE', 'nombrecorto' => 'HOPE',
        'direccion' => 'Av. de prueba 123', 'codpais' => 'ECU']);
    vetSeed('clientes', ['codcliente' => 'CLI001', 'nombre' => 'Elena García', 'cifnif' => 'TEST-001',
        'telefono1' => '0990000001', 'email' => 'elena@example.test', 'direccion' => 'Dirección del tutor']);
    vetSeed('clientes', ['codcliente' => 'CLI002', 'nombre' => 'Otro tutor', 'cifnif' => 'TEST-002']);
    foreach (['doctor', 'other', 'limited'] as $nick) {
        vetSeed('users', ['nick' => $nick, 'admin' => $nick === 'doctor', 'enabled' => true,
            'idempresa' => 1, 'langcode' => 'es_EC', 'level' => $nick === 'doctor' ? 99 : 1]);
    }
}

$user = new FacturaScripts\Dinamic\Model\User();
$user->loadFromCode('doctor');
Session::set('user', $user);
