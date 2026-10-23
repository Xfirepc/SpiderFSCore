<?php

/** Prueba aislada: nunca carga config.php ni el bootstrap de una instancia. */
if (getenv('INVENTORY_DISPOSABLE_DB') !== '1') {
    throw new RuntimeException('Ejecute esta prueba únicamente en MariaDB desechable y sin acceso a instancias.');
}
require '/app/vendor/autoload.php';

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Inventory\InventorySchemaMigration;
use FacturaScripts\Core\DbUpdater;

define('FS_FOLDER', '/tmp/inventory-schema-test-' . getmypid());
define('FS_DB_NAME', 'inventory_test');
define('FS_DB_HOST', '127.0.0.1');
define('FS_DB_PORT', 3306);
define('FS_DB_USER', 'root');
define('FS_DB_PASS', 'inventory-test-only');
define('FS_DB_TYPE', 'mysql');
define('FS_DB_TYPE_CHECK', true);
define('FS_LANG', 'es_EC');
define('FS_ITEM_LIMIT', 50);
define('FS_ROUTE', '');
define('FS_DEBUG', false);
define('FS_DB_FOREIGN_KEYS', true);
define('FS_MYSQL_CHARSET', 'utf8mb4');
define('FS_MYSQL_COLLATE', 'utf8mb4_general_ci');
mkdir(FS_FOLDER . '/MyFiles/Tmp/FileCache', 0777, true);

if (($argv[1] ?? '') === 'writer') {
    $link = new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, FS_DB_PORT);
    echo "READY\n";
    flush();
    $events = [];
    for ($i = 0; $i < 250; $i++) {
        $start = microtime(true);
        if (!$link->query("INSERT INTO stocks_movimientos (codalmacen,idproducto,referencia,cantidad,legado) VALUES ('A',1,'X',1,'concurrente')")) {
            throw new RuntimeException('Falló una escritura concurrente de prueba.');
        }
        $events[] = [$start, microtime(true)];
        usleep(2000);
    }
    echo json_encode($events) . "\n";
    exit;
}

$database = new DataBase();
if (!$database->connect()) {
    throw new RuntimeException('No se pudo conectar a MariaDB desechable.');
}
$checks = 0;
function checkSchema(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo 'PASS ' . $message . "\n";
}

function sqlSchema(string $sql): void
{
    global $database;
    if (!$database->exec($sql)) {
        throw new RuntimeException('Falló el fixture aislado: ' . $sql);
    }
}

function schemaAlterCount(): int
{
    global $database;
    return (int)$database->select("SHOW SESSION STATUS LIKE 'Com_alter_table'")[0]['Value'];
}

function seedSchema(int $rows): void
{
    sqlSchema('DROP TABLE IF EXISTS stocks_movimientos,productos_lotes');
    sqlSchema('DROP TABLE IF EXISTS variantes,productos,almacenes');
    sqlSchema('CREATE TABLE almacenes (codalmacen varchar(4) PRIMARY KEY) ENGINE=InnoDB');
    sqlSchema('CREATE TABLE productos (idproducto int PRIMARY KEY) ENGINE=InnoDB');
    sqlSchema('CREATE TABLE variantes (referencia varchar(30) PRIMARY KEY) ENGINE=InnoDB');
    sqlSchema("INSERT INTO almacenes VALUES ('A'),('B')");
    sqlSchema('INSERT INTO productos VALUES (1)');
    sqlSchema("INSERT INTO variantes VALUES ('X')");
    sqlSchema('CREATE TABLE stocks_movimientos (id int AUTO_INCREMENT PRIMARY KEY, codalmacen varchar(4) NOT NULL,'
        . ' idproducto int NOT NULL, referencia varchar(30) NOT NULL, cantidad decimal(20,3), legado varchar(40),'
        . ' CONSTRAINT ca_stocks_movimientos_almacenes FOREIGN KEY(codalmacen) REFERENCES almacenes(codalmacen) ON DELETE CASCADE ON UPDATE CASCADE,'
        . ' CONSTRAINT ca_stocks_movimientos_productos FOREIGN KEY(idproducto) REFERENCES productos(idproducto) ON DELETE CASCADE ON UPDATE CASCADE,'
        . ' CONSTRAINT ca_stocks_movimientos_variantes FOREIGN KEY(referencia) REFERENCES variantes(referencia) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB');
    sqlSchema('CREATE TABLE productos_lotes (idlote int AUTO_INCREMENT PRIMARY KEY, referencia varchar(30),numserie varchar(100),'
        . ' codalmacen varchar(4),cantidad decimal(20,3), UNIQUE KEY uniq_numserie_productos(referencia,numserie),'
        . ' CONSTRAINT ca_lotes_variantes FOREIGN KEY(referencia) REFERENCES variantes(referencia) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB');
    sqlSchema("INSERT INTO productos_lotes (referencia,numserie,codalmacen,cantidad) VALUES ('X','HISTORICO',NULL,1.235)");
    sqlSchema("INSERT INTO stocks_movimientos (codalmacen,idproducto,referencia,cantidad,legado) SELECT 'A',1,'X',seq/1000,CONCAT('histórico-',seq) FROM seq_1_to_" . $rows);
}

function checksumSchema(string $table, string $order, int $cutoff): string
{
    $link = new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, FS_DB_PORT);
    $link->set_charset('utf8mb4');
    $rows = $link->query('SELECT * FROM ' . $table . ' WHERE ' . $order . '<=' . $cutoff . ' ORDER BY ' . $order, MYSQLI_USE_RESULT);
    $hash = hash_init('sha256');
    while ($row = $rows->fetch_assoc()) {
        hash_update($hash, json_encode($row) . "\n");
    }
    $rows->free();
    $link->close();
    return hash_final($hash);
}

function expectSchemaFailure(callable $callback, string $message): void
{
    $failed = false;
    try {
        $callback();
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    checkSchema($failed, $message);
}

seedSchema(83000);
$migration = new InventorySchemaMigration($database);
$historyBefore = checksumSchema('stocks_movimientos', 'id', 83000);
$lotsBefore = checksumSchema('productos_lotes', 'idlote', 1);
checkSchema(count($migration->inspect(['stocks_movimientos'])['stocks_movimientos']['clauses']) === 6, 'detecta las tres relaciones antiguas');
checkSchema($migration->inspect(['productos_lotes'])['productos_lotes']['columns'] === ['referencia', 'numserie'], 'detecta el índice antiguo de lotes');

$pipes = [];
$writer = proc_open([PHP_BINARY, __FILE__, 'writer'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($writer) || trim(fgets($pipes[1])) !== 'READY') {
    throw new RuntimeException('No pudo iniciar el escritor concurrente aislado.');
}
$start = microtime(true);
$result = $migration->upgrade(['stocks_movimientos', 'productos_lotes']);
$end = microtime(true);
$events = json_decode(trim(stream_get_contents($pipes[1])), true);
$writerError = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
checkSchema(proc_close($writer) === 0, 'las escrituras concurrentes terminan sin errores: ' . $writerError);
checkSchema($result['changed_tables'] === ['stocks_movimientos', 'productos_lotes'], 'actualiza solo las dos tablas previstas');
checkSchema(count($events) === 250, 'se confirman las 250 escrituras concurrentes');
$overlap = array_filter($events, static function (array $event) use ($start, $end): bool {
    return $event[0] <= $end && $event[1] >= $start;
});
checkSchema(count($overlap) > 0, 'hay escrituras concurrentes durante la migración');
checkSchema(checksumSchema('stocks_movimientos', 'id', 83000) === $historyBefore, 'los 83000 movimientos históricos conservan su SHA-256');
checkSchema(checksumSchema('productos_lotes', 'idlote', 1) === $lotsBefore, 'el lote histórico conserva cantidad y almacén NULL');
$rules = $database->select("SELECT UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='stocks_movimientos'");
checkSchema(count($rules) === 3 && count(array_filter($rules, static function (array $row): bool {
    return in_array($row['UPDATE_RULE'], ['RESTRICT', 'NO ACTION'], true)
        && in_array($row['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true);
})) === 3, 'las tres relaciones protegen el historial');

$database->beginTransaction();
checkSchema(!$database->exec("DELETE FROM variantes WHERE referencia='X'"), 'rechaza borrar una referencia con historial');
$database->rollback();
checkSchema(checksumSchema('stocks_movimientos', 'id', 83000) === $historyBefore, 'el intento rechazado no modifica el historial');
$database->beginTransaction();
checkSchema($database->exec("INSERT INTO productos_lotes (referencia,numserie,codalmacen,cantidad) VALUES ('X','ACTUAL','A',1),('X','ACTUAL','B',2)"), 'admite el mismo lote en almacenes diferentes');
checkSchema(!$database->exec("INSERT INTO productos_lotes (referencia,numserie,codalmacen,cantidad) VALUES ('X','ACTUAL','A',3)"), 'rechaza duplicados dentro del mismo almacén');
$database->rollback();
$alterCount = schemaAlterCount();
checkSchema($migration->upgrade(['stocks_movimientos', 'productos_lotes'])['changed_tables'] === [], 'un reintento es idempotente');
checkSchema(schemaAlterCount() === $alterCount, 'un esquema actualizado no ejecuta ALTER');
checkSchema((int)$database->select('SELECT @@SESSION.foreign_key_checks AS fk')[0]['fk'] === 1, 'las validaciones de la sesión quedan activadas');

// El actualizador normal debe conservar las relaciones físicas migradas y
// cualquier índice vigente cuando encuentre otra restricción obsoleta.
sqlSchema('CREATE UNIQUE INDEX history_keep ON stocks_movimientos(legado,id)');
sqlSchema('CREATE UNIQUE INDEX history_obsolete ON stocks_movimientos(cantidad,id)');
$structure = ['columns' => [], 'constraints' => [
    'primary' => ['name' => 'stocks_movimientos_pkey', 'constraint' => 'PRIMARY KEY(id)'],
    'keep' => ['name' => 'history_keep', 'constraint' => 'UNIQUE(legado,id)'],
]];
foreach ($database->getColumns('stocks_movimientos') as $column) {
    $structure['columns'][$column['name']] = ['name' => $column['name'], 'type' => $column['type'],
        'null' => $column['is_nullable'], 'default' => ''];
}
foreach (['almacenes' => ['codalmacen', 'almacenes', 'codalmacen'],
    'productos' => ['idproducto', 'productos', 'idproducto'],
    'variantes' => ['referencia', 'variantes', 'referencia']] as $suffix => $definition) {
    $name = 'ca_stocks_movimientos_' . $suffix;
    $structure['constraints'][$name] = ['name' => $name, 'constraint' => 'FOREIGN KEY (' . $definition[0]
        . ') REFERENCES ' . $definition[1] . ' (' . $definition[2] . ') ON DELETE RESTRICT ON UPDATE RESTRICT'];
}
DbUpdater::rebuild();
checkSchema(DbUpdater::updateTable('stocks_movimientos', $structure), 'el actualizador normal retira solo la restricción obsoleta');
$names = array_column($database->getConstraints('stocks_movimientos'), 'name');
checkSchema(in_array('history_keep', $names, true) && !in_array('history_obsolete', $names, true), 'conserva el índice vigente al retirar otro');
checkSchema(!$migration->inspect(['stocks_movimientos'])['stocks_movimientos']['clauses'], 'conserva las tres relaciones restrictivas después del rebuild normal');
checkSchema(checksumSchema('stocks_movimientos', 'id', 83000) === $historyBefore, 'el rebuild normal conserva el contenido de los movimientos');

seedSchema(10);
$blocker = new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, FS_DB_PORT);
$blocker->begin_transaction();
$blocker->query('SELECT * FROM stocks_movimientos LIMIT 1 FOR UPDATE');
$sessionBefore = $database->select('SELECT @@SESSION.lock_wait_timeout AS timeout')[0]['timeout'];
$waitStart = microtime(true);
expectSchemaFailure(static function () use ($migration): void {
    $migration->upgrade(['stocks_movimientos']);
}, 'un bloqueo de metadatos cancela el cambio sin esperar indefinidamente');
checkSchema(microtime(true) - $waitStart < 4, 'la espera de metadatos está acotada a dos segundos');
checkSchema(count($migration->inspect(['stocks_movimientos'])['stocks_movimientos']['clauses']) === 6, 'el ALTER fallido conserva las relaciones anteriores');
checkSchema((int)$database->select('SELECT @@SESSION.foreign_key_checks AS fk')[0]['fk'] === 1, 'restaura las validaciones después de un error');
checkSchema($database->select('SELECT @@SESSION.lock_wait_timeout AS timeout')[0]['timeout'] === $sessionBefore, 'restaura la espera original después de un error');
$blocker->rollback();
$blocker->close();
checkSchema($migration->upgrade(['stocks_movimientos'])['changed_tables'] === ['stocks_movimientos'], 'el cambio pendiente se puede reintentar tras liberar el bloqueo');

seedSchema(10);
sqlSchema('SET SESSION foreign_key_checks=0');
sqlSchema("INSERT INTO stocks_movimientos (codalmacen,idproducto,referencia) VALUES ('Z',1,'X')");
sqlSchema('SET SESSION foreign_key_checks=1');
$alterCount = schemaAlterCount();
expectSchemaFailure(static function () use ($migration): void {
    $migration->upgrade(['stocks_movimientos', 'productos_lotes']);
}, 'los datos huérfanos requieren revisión y nunca se reparan automáticamente');
checkSchema(schemaAlterCount() === $alterCount, 'un fallo de validación no inicia ninguna modificación de esquema');
$database->beginTransaction();
expectSchemaFailure(static function () use ($migration): void {
    $migration->upgrade(['productos_lotes']);
}, 'rechaza ejecutar DDL dentro de una transacción comercial');
checkSchema($database->inTransaction(), 'la transacción rechazada sigue abierta para su propietario');
$database->rollback();
echo json_encode(['checks' => $checks, 'rows_preserved' => 83000, 'concurrent_writes' => 250,
    'writes_during_migration' => count($overlap), 'migration_ms' => round(($end - $start) * 1000, 2)]) . "\n";
