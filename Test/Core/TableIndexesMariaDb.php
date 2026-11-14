<?php

/** Esquema y datos desechables: nunca cargar config.php ni Test/bootstrap.php. */
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\PluginDeploy;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Lib\MysqlTableIndexes;

if (getenv('TABLE_INDEXES_DISPOSABLE') !== '1') {
    throw new RuntimeException('Se requiere MariaDB de prueba sin acceso a las instancias.');
}
define('FS_FOLDER', '/tmp/table-indexes-runtime');
define('FS_DB_NAME', 'table_indexes_test');
define('FS_DB_HOST', '127.0.0.1');
define('FS_DB_PORT', 3306);
define('FS_DB_USER', 'root');
define('FS_DB_PASS', 'table-indexes-tests-only');
define('FS_DB_TYPE', 'mysql');
define('FS_DB_TYPE_CHECK', true);
define('FS_DB_FOREIGN_KEYS', false);
define('FS_MYSQL_CHARSET', 'utf8mb4');
define('FS_MYSQL_COLLATE', 'utf8mb4_general_ci');
define('FS_DEBUG', false);
define('FS_LANG', 'es_EC');
define('FS_ITEM_LIMIT', 50);
require '/app/vendor/autoload.php';
mkdir(FS_FOLDER . '/MyFiles', 0775, true);
symlink('/app/Core', FS_FOLDER . '/Core');
symlink('/app/Plugins', FS_FOLDER . '/Plugins');
$raw = new PDO('mysql:host=127.0.0.1;dbname=' . FS_DB_NAME, 'root', FS_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = new DataBase();
$database->connect();
$schema = new MysqlTableIndexes($database);
$checks = 0;
function indexCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

// La combinación real conserva los índices del Core y las columnas de los plugins.
$deploy = new PluginDeploy();
foreach (['logs', 'facturascli'] as $table) {
    indexCheck($deploy->deployTable($table, ['SpiderFact', 'SpiderTPV']), 'No se combinó el XML ' . $table);
    $structure = DbUpdater::readTableXml(FS_FOLDER . '/Dinamic/Table/' . $table . '.xml');
    indexCheck((bool)$structure['indexes'], 'Falta el índice del XML combinado');
    indexCheck(DbUpdater::createTable($table, $structure), 'No se creó la tabla con sus índices');
    indexCheck(!MysqlTableIndexes::missing($structure['indexes'], $raw->query('SHOW INDEX FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC)),
        'La tabla nueva debe incluir los índices');
}
$raw->exec("INSERT INTO logs (channel, level, message, time) VALUES ('ventas','info','a',NOW()),"
    . "('ventas','info','b',NOW()),(NULL,'info','c',NOW()),(NULL,'info','d',NOW())");
$before = $raw->query('SELECT * FROM logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$raw->exec('ALTER TABLE logs DROP INDEX idx_fs_logs_channel');
DbUpdater::forgetDatabase(FS_DB_NAME);
indexCheck(DbUpdater::updateTable('logs'), 'Se debe añadir el índice a una tabla existente');
indexCheck($before === $raw->query('SELECT * FROM logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'Los índices no pueden cambiar filas ni imponer unicidad a canales repetidos o NULL');
indexCheck(DbUpdater::isTableChecked('logs'), 'El éxito debe guardarse en la caché de esquema');
$raw->exec('ALTER TABLE logs ADD INDEX custom_channel (channel,id), DROP INDEX idx_fs_logs_channel');
indexCheck(!$schema->ensure('logs', ['idx_fs_logs_channel' => ['channel']]), 'Un índice equivalente evita duplicados');
indexCheck(count($raw->query("SHOW INDEX FROM logs WHERE Key_name='idx_fs_logs_channel'")->fetchAll()) === 0,
    'No debe añadirse un segundo índice cubierto por otro');

$raw->exec('CREATE TABLE transaction_probe (id INT PRIMARY KEY, channel VARCHAR(40)) ENGINE=InnoDB');
$database->beginTransaction();
$database->exec("INSERT INTO transaction_probe VALUES (1,'pendiente')");
try {
    $schema->ensure('transaction_probe', ['idx_channel' => ['channel']]);
    throw new RuntimeException('No se rechazó el DDL dentro de una transacción comercial');
} catch (RuntimeException $error) {
    indexCheck(strpos($error->getMessage(), 'transacción') !== false, 'Debe aplazarse el índice dentro de la transacción');
}
indexCheck($database->inTransaction(), 'El intento de preparar índices no debe confirmar la transacción');
$database->rollback();
indexCheck((int)$raw->query('SELECT COUNT(*) FROM transaction_probe')->fetchColumn() === 0, 'La escritura conserva su rollback');
indexCheck($schema->ensure('transaction_probe', ['idx_channel' => ['channel']]), 'El índice puede prepararse fuera de la transacción');

$raw->exec('CREATE TABLE blocked (id INT PRIMARY KEY, channel VARCHAR(40)) ENGINE=InnoDB');
$raw->beginTransaction();
$raw->query('SELECT * FROM blocked')->fetchAll();
$start = microtime(true);
try {
    $schema->ensure('blocked', ['idx_channel' => ['channel']]);
    throw new RuntimeException('Debía agotarse la espera de metadatos');
} catch (RuntimeException $error) {
    indexCheck(strpos($error->getMessage(), 'online') !== false, 'El bloqueo debe devolver un fallo reintentable');
    indexCheck(microtime(true) - $start < 5, 'La espera de metadatos debe estar acotada');
} finally {
    $raw->rollBack();
}
indexCheck($schema->ensure('blocked', ['idx_channel' => ['channel']]), 'El reintento debe completar el índice');
$raw->exec('ALTER TABLE blocked DROP INDEX idx_channel, ADD INDEX idx_channel (id)');
try {
    $schema->ensure('blocked', ['idx_channel' => ['channel']]);
    throw new RuntimeException('Se sustituyó un índice con otra definición');
} catch (RuntimeException $error) {
    indexCheck(strpos($error->getMessage(), 'otra definición') !== false, 'La colisión exige revisión sin retirar el índice');
}
indexCheck($raw->query("SHOW INDEX FROM blocked WHERE Key_name='idx_channel'")->fetch(PDO::FETCH_ASSOC)['Column_name'] === 'id',
    'Conservar índices ajenos');

$raw->exec('CREATE TABLE parent_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
$raw->exec('CREATE TABLE child_probe (id INT PRIMARY KEY, parent_id INT, active BOOLEAN,'
    . ' CONSTRAINT ca_parent_probe FOREIGN KEY (parent_id) REFERENCES parent_probe (id)) ENGINE=InnoDB');
$raw->exec('INSERT INTO parent_probe VALUES (1)');
$raw->exec('INSERT INTO child_probe VALUES (1,1,1)');
$foreignKeys = $database->getConstraints('child_probe');
indexCheck($schema->ensure('child_probe', ['idx_parent_active' => ['parent_id', 'active']]),
    'El compuesto debe añadirse sin retirar la relación');
indexCheck($foreignKeys === $database->getConstraints('child_probe'), 'El motor debe conservar todas las restricciones');
try {
    $raw->exec('INSERT INTO child_probe VALUES (2,999,1)');
    throw new RuntimeException('Se perdió la protección de la relación');
} catch (PDOException $error) {
    indexCheck((int)$error->errorInfo[1] === 1452, 'La FK sigue rechazando referencias inexistentes');
}
// InnoDB puede sustituir su índice auxiliar automático por el compuesto.
$raw->exec('ALTER TABLE child_probe ADD INDEX ca_parent_probe (parent_id)');
$raw->exec('ALTER TABLE child_probe DROP INDEX idx_parent_active');
indexCheck($foreignKeys === $database->getConstraints('child_probe'), 'La reversión conserva la FK al reponer su índice auxiliar');
indexCheck((int)$raw->query('SELECT COUNT(*) FROM child_probe')->fetchColumn() === 1, 'La relación y los datos no cambian');

// Un paquete basado en SHOW CREATE TABLE debe transmitir los mismos índices.
$raw->exec('CREATE DATABASE table_indexes_child');
foreach (['logs', 'facturascli'] as $table) {
    $definition = $raw->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $raw->exec('USE table_indexes_child');
    $raw->exec($definition);
    $expected = DbUpdater::readTableXml(FS_FOLDER . '/Dinamic/Table/' . $table . '.xml')['indexes'];
    indexCheck(!MysqlTableIndexes::missing($expected, $raw->query('SHOW INDEX FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC)),
        'La instancia hija debe heredar los índices del esquema padre');
    $raw->exec('USE ' . FS_DB_NAME);
}
echo json_encode(['checks' => $checks, 'data_preserved' => true, 'child_indexes' => true]), PHP_EOL;
