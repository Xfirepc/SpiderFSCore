<?php

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\MysqlEngine;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Lib\BusinessDocumentCode;
use FacturaScripts\Core\Lib\DocumentNumberingSchema;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelOnChangeClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\SecuenciaDocumento;

/** Pruebas reales de numeración; solo MariaDB desechable y sin config.php. */
if (getenv('NUMBERING_DISPOSABLE_DB') !== '1' || is_file(dirname(__DIR__, 2) . '/config.php')) {
    throw new RuntimeException('Esta prueba requiere un contenedor MariaDB desechable sin red externa.');
}

define('FS_FOLDER', $argv[2] ?? '/tmp/document-numbering-' . getmypid());
define('FS_DB_NAME', 'numbering_test');
define('FS_DB_HOST', '127.0.0.1');
define('FS_DB_PORT', 3306);
define('FS_DB_USER', 'root');
define('FS_DB_PASS', 'numbering-tests-only');
define('FS_DB_TYPE', 'mysql');
define('FS_DB_TYPE_CHECK', true);
define('FS_LANG', 'es_EC');
define('FS_ITEM_LIMIT', 50);
define('FS_ROUTE', '');
define('FS_DEBUG', false);
define('FS_DB_FOREIGN_KEYS', true);
define('FS_MYSQL_CHARSET', 'utf8mb4');
define('FS_MYSQL_COLLATE', 'utf8mb4_general_ci');

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('NUMBERING_BASELINE') === '1') {
    // La copia conserva los archivos reales anteriores y todos los plugins del
    // mismo workspace siguen disponibles mediante el autoloader de Composer.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'FacturaScripts\\Core\\';
        if (strpos($class, $prefix) === 0) {
            $path = '/baseline/Core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    }, true, true);
}


class NumberingInvoiceFixture extends FacturaCliente
{
    use ModelTrait;

    public static $failInsert = false;

    public function clear()
    {
        ModelClass::clear();
        $this->idempresa = 1;
        $this->codserie = 'NV';
        $this->codejercicio = '2026';
        $this->fecha = '11-11-2026';
        $this->hora = '10:00:00';
        $this->codigo = '';
    }

    public static function tableName(): string
    {
        return 'facturascli';
    }

    public static function primaryColumn(): string
    {
        return 'idfactura';
    }

    public function modelClassName(): string
    {
        return 'FacturaCliente';
    }

    public function test(): bool
    {
        return ModelClass::test();
    }

    // Este fixture aísla la numeración: no crea saldos ni líneas comerciales.
    protected function onInsert()
    {
        ModelOnChangeClass::onInsert();
    }

    protected function onUpdate()
    {
        ModelOnChangeClass::onUpdate();
    }

    protected function saveInsert(array $values = []): bool
    {
        return !self::$failInsert && parent::saveInsert($values);
    }
}

class NumberingSequenceFixture extends SecuenciaDocumento
{
    public static $failSave = false;

    public function clear()
    {
        ModelClass::clear();
        $this->inicio = 1;
        $this->longnumero = 6;
        $this->numero = 1;
        $this->usarhuecos = true;
    }

    public function test(): bool
    {
        return ModelClass::test();
    }

    public function save(): bool
    {
        return !self::$failSave && parent::save();
    }
}

class NumberingReaderFixture extends BusinessDocumentCode
{
    public static function previous(SecuenciaDocumento $sequence, BusinessDocument $document): array
    {
        return parent::getPrevious($sequence, $document);
    }

    public static function number(SecuenciaDocumento $sequence, BusinessDocument $document): string
    {
        return parent::getNewNumber($sequence, $document);
    }
}

/** Observa el SQL del motor real sin activar el modo debug de la aplicación. */
class NumberingSqlTraceFixture extends MysqlEngine
{
    public $numberingSql;

    public function select($link, $sql)
    {
        if (preg_match('/^SELECT .*FROM `?facturascli`?.*ORDER BY/', $sql)) {
            $this->numberingSql = $sql;
        }
        return parent::select($link, $sql);
    }
}

class_alias(NumberingSequenceFixture::class, 'FacturaScripts\\Dinamic\\Model\\SecuenciaDocumento');
class_alias(BusinessDocumentCode::class, 'FacturaScripts\\Dinamic\\Lib\\BusinessDocumentCode');

foreach (['/MyFiles/Tmp/FileCache', '/Dinamic/Table'] as $folder) {
    if (!is_dir(FS_FOLDER . $folder)) {
        mkdir(FS_FOLDER . $folder, 0777, true);
    }
}

$database = new DataBase();
$trace = new NumberingSqlTraceFixture();
$engine = new ReflectionProperty(DataBase::class, 'engine');
$engine->setAccessible(true);
$engine->setValue(null, $trace);
if (!$database->connect()) {
    throw new RuntimeException('No se pudo conectar al fixture de numeración.');
}
$pdo = new PDO('mysql:host=127.0.0.1;dbname=numbering_test;charset=utf8mb4', 'root', FS_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$checks = 0;

function numberingCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message . '\n' . json_encode(MiniLog::read('database', ['error', 'critical'])));
    }
    $checks++;
}

function numberingSql(string $sql): void
{
    global $pdo;
    $pdo->exec($sql);
}

function numberingXml(string $table): void
{
    global $pdo;
    $xml = new SimpleXMLElement('<table/>');
    foreach ($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $field) {
        if ($field['Field'] === DocumentNumberingSchema::COLUMN) {
            continue;
        }
        $column = $xml->addChild('column');
        $column->addChild('name', $field['Field']);
        $column->addChild('type', $field['Type']);
        $column->addChild('null', $field['Null']);
    }
    foreach ($pdo->query("SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS"
        . " WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "'")->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
        $item = $xml->addChild('constraint');
        $item->addChild('name', $constraint['CONSTRAINT_NAME']);
        $fields = $pdo->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . $table . "' AND CONSTRAINT_NAME='" . $constraint['CONSTRAINT_NAME']
            . "' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $item->addChild('type', ($constraint['CONSTRAINT_TYPE'] === 'PRIMARY KEY' ? 'PRIMARY KEY' : 'UNIQUE')
            . '(' . implode(',', $fields) . ')');
    }
    $xml->asXML(FS_FOLDER . '/Dinamic/Table/' . $table . '.xml');
}

function numberingSeed(array $numbers = [], int $next = 1, bool $gaps = true, int $start = 1): void
{
    numberingSql('DROP TABLE IF EXISTS facturascli,secuencias_documentos');
    $extra = '';
    for ($index = 0; $index < 54; $index++) {
        $extra .= ',payload_' . $index . ' varchar(80) NULL';
    }
    numberingSql('CREATE TABLE facturascli (idfactura int AUTO_INCREMENT PRIMARY KEY,idempresa int NOT NULL,'
        . 'codserie varchar(4) NOT NULL,codejercicio varchar(4) NOT NULL,numero varchar(12) NOT NULL,'
        . 'codigo varchar(30) NOT NULL,fecha date NOT NULL,hora time NULL' . $extra
        . ',UNIQUE KEY uniq_number_facturascli(codejercicio,codserie,idempresa,numero),'
        . 'KEY ca_facturascli_codserie(codserie),KEY ca_facturascli_idempresa(idempresa)) ENGINE=InnoDB');
    numberingSql('CREATE TABLE secuencias_documentos (idsecuencia int AUTO_INCREMENT PRIMARY KEY,idempresa int NOT NULL,'
        . 'codserie varchar(4) NOT NULL,codejercicio varchar(4) NULL,tipodoc varchar(30) NOT NULL,inicio int NULL,'
        . 'longnumero int NOT NULL,numero int NOT NULL,patron varchar(50) NOT NULL,usarhuecos tinyint(1) NOT NULL DEFAULT 0,'
        . 'KEY fixture_sequence(idempresa,codserie,tipodoc)) ENGINE=InnoDB');
    numberingSql("INSERT INTO secuencias_documentos (idempresa,codserie,codejercicio,tipodoc,inicio,longnumero,numero,patron,usarhuecos)"
        . " VALUES(1,'NV','2026','FacturaCliente'," . $start . ",6," . $next . ",'F{EJE}{SERIE}{NUM}'," . (int)$gaps . ')');
    foreach ($numbers as $number) {
        $n = (string)$number;
        numberingSql("INSERT INTO facturascli (idempresa,codserie,codejercicio,numero,codigo,fecha,hora) VALUES(1,'NV','2026',"
            . "'" . $n . "','LEGACY-" . $n . "','2026-11-09','09:15:00')");
    }
    numberingXml('facturascli');
    numberingXml('secuencias_documentos');
    NumberingInvoiceFixture::resetModelFields();
    NumberingSequenceFixture::resetModelFields();
    NumberingInvoiceFixture::$failInsert = false;
    NumberingSequenceFixture::$failSave = false;
    DocumentNumberingSchema::reset();
    DbUpdater::rebuild();
    new NumberingInvoiceFixture();
    new NumberingSequenceFixture();
    MiniLog::clear();
}

function numberingMigrate(): void
{
    global $database;
    (new DocumentNumberingSchema($database))->upgrade();
    NumberingInvoiceFixture::resetModelFields();
}

function numberingExpectFailure(callable $action, string $message): void
{
    $failed = false;
    try {
        $action();
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    numberingCheck($failed, $message);
}

function numberingAlterCount(): int
{
    global $database;
    return (int)$database->select("SHOW SESSION STATUS LIKE 'Com_alter_table'")[0]['Value'];
}

function numberingHash(): string
{
    global $pdo;
    $hash = hash_init('sha256');
    // El checksum no debe cargar el conjunto entero ni dominar el pico de RAM.
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $cursor = $pdo->query('SELECT idfactura,idempresa,codserie,codejercicio,numero,codigo,fecha,hora FROM facturascli ORDER BY idfactura');
        while ($row = $cursor->fetch(PDO::FETCH_ASSOC)) {
            hash_update($hash, json_encode($row));
        }
        $cursor->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    return hash_final($hash);
}

function numberingCases(bool $migrated): array
{
    global $pdo;
    $cases = [
        ['empty', [], 14, true, 7],
        ['next', [1, 2, 3], 4, false, 1],
        ['behind', [1, 2, 3], 1, false, 1],
        ['ahead', [1, 2, 3], 15, false, 1],
        ['middle', [1, 3], 4, true, 1],
        ['first', [9, 10], 11, true, 7],
        ['lower-bound', [9, 10], 11, true, 9],
        ['padded', ['0001', '0003'], 4, true, 1],
        ['zero', [0, 2], 3, true, 1],
        ['other-company-series', [1, 3], 4, true, 1],
        ['gap-date', [1, 3], 4, true, 1],
        ['global-excluded-year', [1, 2], 3, true, 1],
        ['global-previous-year', [1, 2], 3, true, 1],
        ['window', array_values(array_diff(range(1, 1800), [200])), 1801, true, 1],
    ];
    $result = [];
    foreach ($cases as [$name, $numbers, $next, $gaps, $start]) {
        numberingSeed($numbers, $next, $gaps, $start);
        if ($name === 'other-company-series') {
            numberingSql("INSERT INTO facturascli (idempresa,codserie,codejercicio,numero,codigo,fecha,hora)"
                . " VALUES(2,'NV','2026','2','OTHER-COMPANY','2026-11-01','00:00:00'),"
                . "(1,'ZZ','2026','2','OTHER-SERIES','2026-11-01','00:00:00')");
        }
        if ($name === 'gap-date') {
            numberingSql("UPDATE facturascli SET fecha='2026-11-10',hora='18:30:00' WHERE numero='3'");
        }
        if (strpos($name, 'global-') === 0) {
            numberingSql('UPDATE secuencias_documentos SET codejercicio=NULL');
            numberingSql("INSERT INTO facturascli (idempresa,codserie,codejercicio,numero,codigo,fecha,hora)"
                . " VALUES(1,'NV','2025','1000','OTHER-YEAR','2025-11-01','15:00:00')");
            if ($name === 'global-excluded-year') {
                numberingSql("INSERT INTO secuencias_documentos (idempresa,codserie,codejercicio,tipodoc,inicio,longnumero,numero,patron,usarhuecos)"
                    . " VALUES(1,'NV','2025','FacturaCliente',1,6,1001,'F{EJE}{SERIE}{NUM}',1)");
            }
        }
        if ($migrated) {
            numberingMigrate();
        }
        $before = numberingHash();
        $sequence = new NumberingSequenceFixture();
        numberingCheck($sequence->loadFromCode(1), 'Carga la secuencia del caso ' . $name);
        $invoice = new NumberingInvoiceFixture();
        $number = NumberingReaderFixture::number($sequence, $invoice);
        numberingCheck(numberingHash() === $before, 'El cálculo no reescribe documentos: ' . $name);
        numberingCheck(count(MiniLog::read('database', ['error', 'critical'])) === 0, 'No hay errores SQL: ' . $name);
        $result[$name] = ['number' => $number, 'date' => $invoice->fecha, 'hour' => $invoice->hora,
            'next' => (int)$pdo->query('SELECT numero FROM secuencias_documentos WHERE idsecuencia=1')->fetchColumn()];
    }
    return $result;
}

$mode = $argv[1] ?? 'smoke';
if ($mode === 'smoke') {
    numberingSeed([1, 3], 4, true);
    $schema = new DocumentNumberingSchema($database);
    numberingCheck($schema->upgrade()['changed'], 'Instala la columna y los índices.');
    NumberingInvoiceFixture::resetModelFields();
    $invoice = new NumberingInvoiceFixture();
    numberingCheck(DocumentNumberingSchema::available($invoice), 'Detecta el acceso optimizado.');
    numberingCheck($invoice->save(), 'Guarda una factura por el camino real del modelo.');
    numberingCheck($invoice->numero === '2', 'Reutiliza el hueco sin cambiar su regla.');
    echo json_encode(['checks' => $checks, 'number' => $invoice->numero, 'date' => $invoice->fecha]), PHP_EOL;
}

if ($mode === 'equivalence') {
    $result = ['fallback' => numberingCases(false)];
    if (getenv('NUMBERING_BASELINE') !== '1') {
        $result['indexed'] = numberingCases(true);
        numberingCheck($result['indexed'] === $result['fallback'], 'El acceso indexado conserva los resultados del acceso compatible.');
    }
    echo json_encode(['checks' => $checks, 'cases' => $result]), PHP_EOL;
}

if ($mode === 'invariants') {
    numberingSeed(['0001', '0003'], 4, true);
    $schema = new DocumentNumberingSchema($database);
    $beforeHash = numberingHash();
    $alters = numberingAlterCount();
    numberingCheck($schema->upgrade()['changed'], 'Instala la optimización explícitamente.');
    numberingCheck(numberingAlterCount() === $alters + 2, 'Separa la columna instantánea de los índices online.');
    $computedExpression = $schema->inspect()['column']['GENERATION_EXPRESSION'];
    numberingCheck(numberingHash() === $beforeHash, 'Conserva exactamente los datos originales.');
    numberingCheck(!$schema->upgrade()['changed'], 'La migración repetida es idempotente.');
    $alters = numberingAlterCount();
    NumberingInvoiceFixture::resetModelFields();
    DbUpdater::rebuild();
    for ($index = 0; $index < 5; $index++) {
        new NumberingInvoiceFixture();
    }
    numberingCheck(numberingAlterCount() === $alters, 'El actualizador ordinario conserva columna e índices sin ALTER repetidos.');
    numberingCheck($schema->inspect()['ready'], 'Los índices siguen instalados después de comprobar el XML.');

    $invoice = new NumberingInvoiceFixture();
    $invoice->fs_numero_orden = 999999;
    numberingCheck($invoice->save(), 'El INSERT ignora el valor enviado para el campo calculado.');
    numberingCheck($invoice->loadFromCode($invoice->idfactura), 'Recarga la factura creada.');
    numberingCheck((int)$invoice->fs_numero_orden === (int)$invoice->numero, 'El motor calcula el orden desde el número original.');
    $invoice->fs_numero_orden = 888888;
    $invoice->payload_0 = 'edición';
    numberingCheck($invoice->save(), 'El UPDATE también ignora el campo calculado.');
    numberingCheck($invoice->loadFromCode($invoice->idfactura) && (int)$invoice->fs_numero_orden === (int)$invoice->numero,
        'Conserva el valor calculado tras editar.');
    $copy = new NumberingInvoiceFixture($invoice->toArray());
    $copy->idfactura = null;
    $copy->codigo = '';
    numberingCheck($copy->save(), 'Copiar un documento no intenta insertar su valor calculado anterior.');
    numberingCheck($copy->loadFromCode($copy->idfactura) && (int)$copy->fs_numero_orden === (int)$copy->numero,
        'La copia utiliza el número nuevo.');

    $next = $pdo->query('SELECT numero FROM secuencias_documentos WHERE idsecuencia=1')->fetchColumn();
    $count = $pdo->query('SELECT COUNT(*) FROM facturascli')->fetchColumn();
    NumberingInvoiceFixture::$failInsert = true;
    $failed = new NumberingInvoiceFixture();
    numberingCheck(!$failed->save(), 'Un fallo al persistir la factura revierte el guardado.');
    numberingCheck($pdo->query('SELECT numero FROM secuencias_documentos WHERE idsecuencia=1')->fetchColumn() === $next,
        'Un fallo en factura no consume el contador.');
    numberingCheck($pdo->query('SELECT COUNT(*) FROM facturascli')->fetchColumn() === $count,
        'Un fallo en factura no deja un documento parcial.');
    NumberingInvoiceFixture::$failInsert = false;
    NumberingSequenceFixture::$failSave = true;
    numberingCheck(!(new NumberingInvoiceFixture())->save(), 'Un fallo en la secuencia no permite guardar una factura.');
    NumberingSequenceFixture::$failSave = false;
    numberingCheck($pdo->query('SELECT COUNT(*) FROM facturascli')->fetchColumn() === $count,
        'El fallo de secuencia no inserta documentos.');

    numberingCheck($database->beginTransaction(), 'Abre una transacción exterior.');
    numberingCheck($database->exec("INSERT INTO facturascli (idempresa,codserie,codejercicio,numero,codigo,fecha)"
        . " VALUES(3,'TX','2026','1','EXTERNAL','2026-11-11')"), 'La transacción exterior contiene un cambio pendiente.');
    numberingExpectFailure(static function () use ($schema): void { $schema->rollback(); }, 'Rechaza DDL dentro de una transacción.');
    numberingCheck($database->inTransaction(), 'El rechazo no confirma la transacción exterior.');
    numberingCheck($database->rollback(), 'El llamador conserva el control de la transacción.');
    numberingCheck((int)$pdo->query("SELECT COUNT(*) FROM facturascli WHERE codigo='EXTERNAL'")->fetchColumn() === 0,
        'La migración rechazada no confirmó los cambios del llamador.');

    numberingSql('ALTER TABLE facturascli DROP INDEX ' . DocumentNumberingSchema::YEAR_INDEX);
    DocumentNumberingSchema::reset();
    $alters = numberingAlterCount();
    numberingCheck(!DocumentNumberingSchema::available(new NumberingInvoiceFixture()), 'Un índice incompleto usa el acceso compatible.');
    numberingCheck(numberingAlterCount() === $alters, 'La detección no ejecuta DDL desde el modelo.');
    numberingCheck($schema->upgrade()['changed'] && $schema->inspect()['ready'], 'La migración explícita repara únicamente el índice ausente.');
    numberingSql('ALTER TABLE facturascli ADD INDEX custom_aux_dependency(fs_numero_orden)');
    numberingExpectFailure(static function () use ($schema): void { $schema->rollback(); }, 'No retira índices ajenos al revertir.');
    numberingSql('ALTER TABLE facturascli DROP INDEX custom_aux_dependency');

    $history = numberingHash();
    numberingCheck($schema->rollback()['changed'], 'Retira solo la optimización.');
    numberingCheck(numberingHash() === $history, 'La reversión conserva los documentos.');
    numberingCheck(!$schema->rollback()['changed'], 'La reversión repetida es idempotente.');
    NumberingInvoiceFixture::resetModelFields();
    numberingCheck(!(new DocumentNumberingSchema($database))->inspect()['ready'], 'Sin migración se selecciona el camino compatible.');

    numberingSeed([1, 3], 4, true);
    numberingSql('ALTER TABLE facturascli ADD COLUMN fs_numero_orden BIGINT UNSIGNED GENERATED ALWAYS AS ('
        . $computedExpression . ') STORED, ADD INDEX idx_fs_numero_ejercicio(idempresa,codserie,codejercicio,fs_numero_orden),'
        . ' ADD INDEX idx_fs_numero_serie(idempresa,codserie,fs_numero_orden), ALGORITHM=COPY, LOCK=SHARED');
    $storedHash = numberingHash();
    numberingCheck($schema->inspect()['ready'], 'Reconoce instalaciones anteriores con columna almacenada.');
    numberingCheck(!$schema->upgrade()['changed'] && numberingHash() === $storedHash,
        'Conserva sin reconstruir la columna almacenada ya optimizada.');
    NumberingInvoiceFixture::resetModelFields();
    DocumentNumberingSchema::reset();
    $storedInvoice = new NumberingInvoiceFixture();
    $storedInvoice->fs_numero_orden = 9999;
    numberingCheck($storedInvoice->save() && $storedInvoice->loadFromCode($storedInvoice->idfactura),
        'Guarda una factura con la variante almacenada existente.');
    numberingCheck((int)$storedInvoice->fs_numero_orden === (int)$storedInvoice->numero,
        'La variante almacenada conserva el cálculo y el campo de solo lectura.');
    numberingCheck($schema->rollback()['changed'], 'La retirada sigue admitiendo la variante almacenada.');

    numberingSeed(['ABC'], 1, true);
    numberingExpectFailure(static function () use ($schema): void { $schema->upgrade(); }, 'Rechaza números de formato incompatible.');
    numberingCheck(!$schema->inspect()['column'], 'No añade una columna después de una validación fallida.');
    numberingSeed(['1', '0001'], 2, true);
    numberingExpectFailure(static function () use ($schema): void { $schema->upgrade(); }, 'Rechaza números equivalentes sin repararlos.');
    numberingSeed([1], 2, true);
    numberingSql('ALTER TABLE facturascli ADD COLUMN fs_numero_orden int NULL');
    numberingExpectFailure(static function () use ($schema): void { $schema->upgrade(); }, 'No sustituye una columna ajena con el mismo nombre.');
    numberingExpectFailure(static function () use ($schema): void { $schema->rollback(); }, 'No borra una columna ajena al revertir.');

    numberingSeed([1], 2, false);
    numberingMigrate();
    numberingCheck(stripos($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'STRICT_TRANS_TABLES') !== false,
        'Los formatos manuales se prueban con modo estricto activado.');
    foreach (['12A', '+12', ' 12', '12.5', '1e2'] as $index => $number) {
        $manual = new NumberingInvoiceFixture();
        $manual->codigo = 'MANUAL-' . $index;
        $manual->numero = $number;
        numberingCheck($manual->save(), 'El campo calculado conserva formatos manuales admitidos: ' . $number);
        $values = $pdo->query('SELECT CAST(numero AS UNSIGNED) AS legacy,fs_numero_orden AS optimized FROM facturascli'
            . ' WHERE idfactura=' . (int)$manual->idfactura)->fetch(PDO::FETCH_ASSOC);
        numberingCheck($values['legacy'] === $values['optimized'], 'El orden coincide con CAST para ' . $number);
    }
    $invoice = new NumberingInvoiceFixture();
    $sequence = new NumberingSequenceFixture();
    numberingCheck($sequence->loadFromCode(1), 'Carga el contador antes de simular un fallo de lectura.');
    numberingCheck(DocumentNumberingSchema::available($invoice), 'La comprobación de metadatos precede al fallo simulado.');
    numberingSql('RENAME TABLE facturascli TO numbering_unavailable');
    numberingExpectFailure(static function () use ($sequence, $invoice): void {
        NumberingReaderFixture::number($sequence, $invoice);
    }, 'Una lectura SQL fallida no se interpreta como historial vacío.');
    numberingSql('RENAME TABLE numbering_unavailable TO facturascli');
    numberingCheck((int)$pdo->query('SELECT numero FROM secuencias_documentos WHERE idsecuencia=1')->fetchColumn() === 2,
        'El fallo de lectura no incrementa el contador.');
    $expression = $schema->inspect()['column']['GENERATION_EXPRESSION'];
    $different = str_replace('[+-]?', '[+-]? ', $expression);
    numberingCheck($expression !== $different, 'El fixture modifica el significado de un literal del cálculo.');
    numberingSql('ALTER TABLE facturascli MODIFY fs_numero_orden BIGINT UNSIGNED GENERATED ALWAYS AS (' . $different . ') VIRTUAL, ALGORITHM=COPY');
    DocumentNumberingSchema::reset();
    numberingCheck(!$schema->inspect()['ready'], 'No confunde expresiones cuyos literales difieren en un espacio.');
    numberingExpectFailure(static function () use ($schema): void { $schema->upgrade(); }, 'No sustituye una expresión calculada ajena.');
    echo json_encode(['checks' => $checks]), PHP_EOL;
}

if ($mode === 'worker') {
    $label = $argv[3];
    $numbers = [];
    for ($iteration = 0; $iteration < 12; $iteration++) {
        $invoice = new NumberingInvoiceFixture();
        numberingCheck($database->beginTransaction(), 'La caja abre su transacción.');
        // Crea un snapshot anterior al primer guardado de la otra caja.
        $database->select('SELECT COUNT(*) AS total FROM facturascli');
        if ($iteration === 0) {
            file_put_contents(FS_FOLDER . '/ready-' . $label, 'ready');
            $deadline = microtime(true) + 15;
            while (!is_file(FS_FOLDER . '/go')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Las cajas no alcanzaron la barrera.');
                }
                usleep(1000);
            }
        }
        numberingCheck($invoice->save(), 'La caja guarda la factura.');
        usleep(1000);
        numberingCheck($database->commit(), 'La caja confirma la operación completa.');
        $numbers[] = (int)$invoice->numero;
    }
    echo json_encode(['numbers' => $numbers]), PHP_EOL;
}

if (in_array($mode, ['concurrency', 'concurrency-no-gaps'], true)) {
    numberingSeed([1, 2], 3, $mode === 'concurrency');
    numberingMigrate();
    $workers = [];
    foreach (['one', 'two'] as $label) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', FS_FOLDER, $label],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        numberingCheck(is_resource($process), 'Inicia una caja independiente.');
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }
    $deadline = microtime(true) + 15;
    while (count(glob(FS_FOLDER . '/ready-*')) < 2) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Ambas cajas no están listas.');
        }
        usleep(1000);
    }
    file_put_contents(FS_FOLDER . '/go', 'go');
    $numbers = [];
    foreach ($workers as $worker) {
        $output = stream_get_contents($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        numberingCheck(proc_close($worker['process']) === 0, 'La caja termina correctamente: ' . $error);
        $numbers = array_merge($numbers, json_decode(trim($output), true)['numbers']);
    }
    sort($numbers);
    numberingCheck($numbers === range(3, 26), 'Dos cajas con snapshots anteriores asignan 24 números consecutivos distintos.');
    numberingCheck((int)$pdo->query('SELECT COUNT(*) FROM facturascli')->fetchColumn() === 26, 'Se confirman exactamente 24 nuevas facturas.');
    numberingCheck((int)$pdo->query('SELECT numero FROM secuencias_documentos WHERE idsecuencia=1')->fetchColumn() === 27,
        'El contador final coincide con los documentos confirmados.');
    echo json_encode(['checks' => $checks, 'numbers' => $numbers]), PHP_EOL;
}

if (in_array($mode, ['benchmark', 'benchmark-no-gaps'], true)) {
    numberingSeed([], 55001, $mode === 'benchmark');
    $fields = [];
    $values = [];
    for ($index = 0; $index < 12; $index++) {
        $fields[] = 'payload_' . $index;
        $values[] = "RPAD(CONCAT('fixture-',seq),24,'x')";
    }
    numberingSql('INSERT INTO facturascli (idempresa,codserie,codejercicio,numero,codigo,fecha,hora,'
        . implode(',', $fields) . ') SELECT IF(seq<=55000,1,2),IF(seq<=58000,\'NV\',\'AA\'),'
        . "IF(seq<=56000,'2026','2025'),CAST(seq AS CHAR),CONCAT('FIX-',seq),'2026-11-10','10:00:00',"
        . implode(',', $values) . ' FROM seq_1_to_60000');
    $baseline = getenv('NUMBERING_BASELINE') === '1';
    $history = numberingHash();
    $migrationTime = null;
    if (!$baseline) {
        $start = microtime(true);
        numberingMigrate();
        $migrationTime = (microtime(true) - $start) * 1000;
        numberingCheck(numberingHash() === $history, 'La migración del volumen de prueba conserva todos los documentos.');
    }
    $pdo->query('ANALYZE TABLE facturascli')->fetchAll(PDO::FETCH_ASSOC);
    $invoice = new NumberingInvoiceFixture();
    numberingCheck($database->beginTransaction(), 'El ensayo reproduce una transacción de venta.');
    $sequence = new NumberingSequenceFixture();
    numberingCheck($sequence->loadFromCode(1), 'Lee los ajustes de numeración del ensayo.');
    $start = microtime(true);
    $rows = NumberingReaderFixture::previous($sequence, $invoice);
    $firstRead = (microtime(true) - $start) * 1000;
    unset($rows);
    for ($iteration = 0; $iteration < 2; $iteration++) {
        $rows = NumberingReaderFixture::previous($sequence, $invoice);
        unset($rows);
    }
    $statistics = static function () use ($database): array {
        return array_column($database->select("SHOW SESSION STATUS WHERE Variable_name IN"
            . " ('Rows_read','Rows_sent','Handler_read_next','Handler_read_prev','Handler_read_rnd_next','Sort_rows','Sort_scan','Created_tmp_tables')"), 'Value', 'Variable_name');
    };
    $before = $statistics();
    $times = [];
    for ($iteration = 0; $iteration < 5; $iteration++) {
        $start = microtime(true);
        $rows = NumberingReaderFixture::previous($sequence, $invoice);
        $times[] = (microtime(true) - $start) * 1000;
        $hash = hash_init('sha256');
        foreach ($rows as $row) {
            hash_update($hash, json_encode([$row->numero, $row->codejercicio, $row->fecha, $row->hora]));
        }
        $firstRow = $rows[0];
        $firstRowHash = hash('sha256', json_encode([$firstRow->numero, $firstRow->codejercicio, $firstRow->fecha, $firstRow->hora]));
        $resultHash = hash_final($hash);
        $returned = count($rows);
        unset($rows);
    }
    $after = $statistics();
    $difference = [];
    foreach ($after as $key => $value) {
        $difference[$key] = (int)$value - (int)$before[$key];
    }
    $sql = $trace->numberingSql;
    numberingCheck($sql !== null, 'El ensayo conserva la consulta real ejecutada por el algoritmo.');
    $plan = $database->select('EXPLAIN ' . rtrim($sql, ';'));
    numberingCheck($database->lastError() === '', 'Obtiene el plan de la consulta real.');
    numberingCheck($database->rollback(), 'El ensayo finaliza sin modificar documentos.');
    numberingCheck(numberingHash() === $history, 'El ensayo deja intacto el conjunto original.');
    sort($times);
    echo json_encode(['checks' => $checks, 'variant' => $baseline ? 'baseline' : 'candidate',
        'dataset_rows' => 60000, 'gaps' => $mode === 'benchmark', 'returned' => $returned,
        'result_sha256' => $resultHash, 'first_row_sha256' => $firstRowHash,
        'first_read_ms' => $firstRead, 'median_ms' => $times[2], 'times_ms' => $times,
        'peak_memory_bytes' => memory_get_peak_usage(true), 'session_delta_for_5_reads' => $difference,
        'migration_ms' => $migrationTime, 'query' => $sql, 'explain' => $plan]), PHP_EOL;
}

if ($mode === 'cli') {
    numberingSeed([1, 3], 4, true);
    $configuration = FS_FOLDER . '/numbering-maintenance-fixture.php';
    $text = "<?php\n";
    foreach (get_defined_constants() as $key => $value) {
        if (strpos($key, 'FS_') === 0) {
            $text .= 'define(' . var_export($key, true) . ',' . var_export($value, true) . ");\n";
        }
    }
    $text .= "echo 'fixture-output-must-not-be-printed';\n";
    file_put_contents($configuration, $text);
    $call = static function (array $arguments): array {
        $process = proc_open(array_merge([PHP_BINARY, '/app/Tools/numbering-schema.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar la herramienta CLI.');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'out' => $out, 'error' => $error];
    };
    $configArg = '--config=' . $configuration;
    $result = $call([$configArg]);
    numberingCheck($result['status'] === 0 && !json_decode($result['out'], true)['result']['ready'],
        'La acción predeterminada inspecciona sin instalar.');
    numberingCheck(strpos($result['out'], 'must-not-be-printed') === false, 'La salida de la configuración se descarta.');
    $result = $call([$configArg, '--apply']);
    numberingCheck($result['status'] === 0 && json_decode($result['out'], true)['result']['changed'], 'La CLI aplica la migración explícita.');
    $result = $call([$configArg, '--apply']);
    numberingCheck($result['status'] === 0 && !json_decode($result['out'], true)['result']['changed'], 'La CLI mantiene la idempotencia.');
    $result = $call([$configArg, '--rollback']);
    numberingCheck($result['status'] === 0 && json_decode($result['out'], true)['result']['changed'], 'La CLI retira la migración.');
    $result = $call([$configArg, '--apply', '--rollback']);
    numberingCheck($result['status'] === 2, 'La CLI rechaza dos acciones incompatibles.');
    $plain = FS_FOLDER . '/not-php.txt';
    file_put_contents($plain, 'fixture-plain-content-must-not-be-printed');
    $result = $call(['--config=' . $plain]);
    numberingCheck($result['status'] === 1 && strpos($result['out'] . $result['error'], 'plain-content') === false,
        'Una configuración de otro formato se rechaza sin imprimir su contenido.');
    echo json_encode(['checks' => $checks]), PHP_EOL;
}

if ($mode === 'foreign-keys') {
    numberingSeed([1, 2], 3, true);
    numberingSql('CREATE TABLE numbering_companies (idempresa int PRIMARY KEY) ENGINE=InnoDB');
    numberingSql('INSERT INTO numbering_companies VALUES(1)');
    numberingSql('ALTER TABLE facturascli ADD idfacturarect int NULL,'
        . ' ADD CONSTRAINT numbering_company FOREIGN KEY(idempresa) REFERENCES numbering_companies(idempresa),'
        . ' ADD CONSTRAINT numbering_rectification FOREIGN KEY(idfacturarect) REFERENCES facturascli(idfactura),'
        . ' AUTO_INCREMENT=900');
    numberingSql('UPDATE facturascli SET idfacturarect=1 WHERE idfactura=2');
    numberingSql('CREATE TABLE numbering_lines (idlinea int PRIMARY KEY,idfactura int NOT NULL,total decimal(12,2),'
        . ' CONSTRAINT numbering_invoice FOREIGN KEY(idfactura) REFERENCES facturascli(idfactura)) ENGINE=InnoDB');
    numberingSql('INSERT INTO numbering_lines VALUES(1,1,51.75),(2,2,10.25)');
    $schema = new DocumentNumberingSchema($database);
    $before = numberingHash();
    $nextId = static function () use ($pdo): int {
        return (int)$pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='facturascli'")->fetchColumn();
    };
    numberingCheck($nextId() === 900, 'El fixture conserva un próximo ID superior al máximo histórico.');
    numberingCheck($schema->upgrade()['changed'], 'Migra con relaciones entrantes, salientes y autorreferencias.');
    numberingCheck(numberingHash() === $before && $nextId() === 900, 'La copia conserva documentos y próximo ID.');
    $restricted = false;
    try {
        numberingSql('DELETE FROM facturascli WHERE idfactura=1');
    } catch (PDOException $exception) {
        $restricted = $exception->getCode() === '23000';
    }
    numberingCheck($restricted, 'Las relaciones siguen rechazando borrados de facturas referenciadas.');
    NumberingInvoiceFixture::resetModelFields();
    $invoice = new NumberingInvoiceFixture();
    numberingCheck($invoice->save() && (int)$invoice->idfactura === 900, 'Guarda con los índices y las relaciones originales.');
    $before = numberingHash();
    numberingCheck($schema->rollback()['changed'], 'Retira los índices conservando las relaciones.');
    numberingCheck(numberingHash() === $before && $nextId() === 901, 'La reversión conserva documentos y próximo ID.');
    numberingCheck($pdo->query('SELECT SUM(total) FROM numbering_lines')->fetchColumn() === '62.00',
        'Las líneas y sus importes permanecen intactos.');
    numberingSql('DROP TABLE numbering_lines');
    numberingSql('ALTER TABLE facturascli DROP FOREIGN KEY numbering_company,DROP FOREIGN KEY numbering_rectification');
    numberingSql('DROP TABLE numbering_companies');
    echo json_encode(['checks' => $checks]), PHP_EOL;
}
