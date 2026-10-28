<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Test;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\PluginDeploy;
use FacturaScripts\Core\Internal\Plugin;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SpiderDashboard\Lib\CashMetrics;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardFilters;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardService;
use FacturaScripts\Plugins\SpiderDashboard\Lib\ElectronicDocuments;
use FacturaScripts\Plugins\SpiderDashboard\Lib\InventoryMetrics;
use FacturaScripts\Plugins\SpiderDashboard\Lib\ReceivableMetrics;
use FacturaScripts\Plugins\SpiderDashboard\Lib\SalesMetrics;
use FacturaScripts\Plugins\SpiderDashboard\Test\Fixtures\Access;
use FacturaScripts\Plugins\SpiderFact\Lib\DocumentEmissionLock;
use FacturaScripts\Plugins\SpiderFact\Controller\ElectronicDocController as SriController;
use mysqli;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Consultas reales sobre MariaDB desechable, sin config.php ni bootstrap de la instancia. */
class DashboardIntegrationTest extends TestCase
{
    private static $connection;
    private static $tables = [];
    private $db;
    private $access;
    private $filters;

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->close();
        }
    }

    public static function setUpBeforeClass(): void
    {
        if (getenv('SPIDER_DASHBOARD_TEST_DB') !== '1') {
            self::markTestSkipped('Requiere la base desechable indicada en README.md.');
        }
        self::$connection = new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, (int)FS_DB_PORT);
        $root = dirname(__DIR__, 3);
        $core = ['facturascli', 'facturasprov', 'albaranescli', 'lineasfacturascli', 'productos', 'stocks',
            'almacenes', 'series', 'clientes', 'proveedores', 'estados_documentos', 'recibospagoscli', 'pagoscli'];
        foreach ($core as $table) {
            self::table($table, $root . '/Core/Table/' . $table . '.xml');
        }
        foreach (['notascreditocli', 'notasdebitocli', 'lineasnotacreditocli', 'lineasnotadebitocli',
            'sf_documents_signed', 'sf_retenciones_compras'] as $table) {
            self::table($table, $root . '/Plugins/SpiderFact/Table/' . $table . '.xml');
        }
        foreach (['tpvsneo', 'tpvsneo_cajas'] as $table) {
            self::table($table, $root . '/Plugins/SpiderTPV/Table/' . $table . '.xml');
        }
        self::$connection->query('ALTER TABLE series ADD allow_fact BOOLEAN, ADD establishment VARCHAR(3), ADD terminal VARCHAR(3)');
        self::$connection->query('ALTER TABLE facturasprov ADD tipocomprobantesri VARCHAR(2)');
        foreach (['estado' => 'VARCHAR(20)', 'idasientoreverso' => 'INT'] as $column => $type) {
            if (!self::$connection->query("SHOW COLUMNS FROM pagoscli LIKE '" . $column . "'")->num_rows) {
                self::$connection->query('ALTER TABLE pagoscli ADD ' . $column . ' ' . $type);
            }
        }
    }

    protected function setUp(): void
    {
        foreach (self::$tables as $table) {
            self::$connection->query('TRUNCATE TABLE ' . $table);
        }
        $property = new ReflectionProperty(Tools::class, 'settings');
        $property->setAccessible(true);
        $property->setValue(null, ['facturacione' => ['invoice_status_annulled' => 9, 'allow_accounting' => false]]);
        $property = new ReflectionProperty(Plugins::class, 'plugins');
        $property->setAccessible(true);
        $property->setValue(null, [new Plugin(['name' => 'SpiderFact', 'enabled' => true]), new Plugin(['name' => 'SpiderTPV', 'enabled' => true])]);
        $this->db = new DataBase();
        $this->assertTrue($this->db->connect());
        $this->access = new Access();
        $this->filters = DashboardFilters::normalize([], '2026-10-27');
        $this->insert('estados_documentos', ['idestado' => 1, 'nombre' => 'Emitida']);
        $this->insert('estados_documentos', ['idestado' => 9, 'nombre' => 'Anulada']);
        $this->insert('series', ['codserie' => 'E', 'allow_fact' => 1, 'establishment' => '001', 'terminal' => '001']);
        $this->insert('series', ['codserie' => 'N', 'allow_fact' => 0]);
        foreach (['A' => 1, 'B' => 1, 'X' => 2] as $code => $company) {
            $this->insert('almacenes', ['codalmacen' => $code, 'nombre' => 'Almacén ' . $code, 'idempresa' => $company, 'activo' => 1]);
        }
        $this->insert('clientes', ['codcliente' => 'C1', 'nombre' => 'Cliente Uno']);
        $this->insert('proveedores', ['codproveedor' => 'P1', 'nombre' => 'Proveedor Uno']);
    }

    public function testSalesTotalsExcludeAnnulledForeignCurrencyAndOtherCompanyWithoutConvertingUsd(): void
    {
        $this->invoice(1, ['total' => 51.75, 'neto' => 45]);
        $this->invoice(2, ['total' => 100, 'neto' => 90, 'codalmacen' => 'B']);
        $this->invoice(3, ['total' => 999, 'idestado' => 9]);
        $this->invoice(4, ['total' => 999, 'idempresa' => 2, 'codalmacen' => 'X']);
        $this->invoice(5, ['total' => 999, 'coddivisa' => 'EUR']);
        $this->insert('notascreditocli', $this->document(['idnotacredito' => 1, 'total' => 20, 'neto' => 18]));
        $this->insert('notasdebitocli', $this->document(['idnotadebito' => 1, 'total' => 5, 'neto' => 4]));
        $sale = $this->provider(SalesMetrics::class)->load();
        $this->assertEquals(151.75, $sale['today']['billed']);
        $this->assertEquals(136.75, $sale['range']['adjusted']);
        $this->assertEquals(2, $sale['range']['invoices']);
        $this->assertCount(27, $sale['series']['labels']);
        $this->filters['warehouses'] = ['A'];
        $this->assertEquals(51.75, $this->provider(SalesMetrics::class)->load()['range']['billed']);
    }

    public function testRankingsApplyHeaderDiscountsReturnsAndIgnorePackComponents(): void
    {
        $this->invoice(1, ['total' => 90, 'dtopor1' => 10, 'dtopor2' => 0]);
        $this->insert('lineasfacturascli', ['idlinea' => 1, 'idfactura' => 1, 'idproducto' => 1,
            'referencia' => 'SKU', 'descripcion' => 'Producto', 'cantidad' => 10, 'pvptotal' => 100]);
        $this->insert('lineasfacturascli', ['idlinea' => 2, 'idfactura' => 1, 'idproducto' => 2,
            'referencia' => 'CHILD', 'descripcion' => 'Componente', 'cantidad' => 99, 'pvptotal' => 999, 'stockparent' => 1]);
        $this->insert('notascreditocli', $this->document(['idnotacredito' => 1, 'total' => 20]));
        $this->insert('lineasnotacreditocli', ['idlinea' => 1, 'idnotacredito' => 1, 'idproducto' => 1,
            'referencia' => 'SKU', 'descripcion' => 'Producto', 'cantidad' => 2, 'pvptotal' => 20]);
        $this->insert('notasdebitocli', $this->document(['idnotadebito' => 1, 'total' => 5]));
        $this->insert('lineasnotadebitocli', ['idlinea' => 1, 'idnotadebito' => 1, 'idproducto' => 1,
            'referencia' => 'SKU', 'descripcion' => 'Ajuste de precio', 'cantidad' => 5, 'pvptotal' => 5]);
        $sale = $this->provider(SalesMetrics::class)->load();
        $this->assertCount(1, $sale['products']);
        $this->assertEquals(75, $sale['products'][0]['amount']);
        $this->assertEquals(8, $sale['products'][0]['units']);
        $this->assertEquals(75, $sale['customers'][0]['amount']);
    }

    public function testPartialPaymentsReversalsAndNegativeAdjustmentsKeepActualDebt(): void
    {
        $this->invoice(1, ['total' => 100]);
        $this->invoice(2, ['total' => 100]);
        $this->invoice(3, ['total' => 100, 'pagada' => 1]);
        $this->insert('recibospagoscli', ['idrecibo' => 1, 'idfactura' => 1, 'importe' => 100, 'pagado' => 0, 'vencimiento' => '2026-09-01']);
        $this->insert('pagoscli', ['idpago' => 1, 'idrecibo' => 1, 'importe' => 30]);
        $this->insert('pagoscli', ['idpago' => 2, 'idrecibo' => 1, 'importe' => 20, 'estado' => 'REVERTIDO']);
        $this->insert('recibospagoscli', ['idrecibo' => 2, 'idfactura' => 1, 'importe' => -30, 'pagado' => 0, 'vencimiento' => '2026-09-01']);
        $this->insert('recibospagoscli', ['idrecibo' => 3, 'idfactura' => 2, 'importe' => 120, 'pagado' => 1, 'vencimiento' => '2026-09-01']);
        $value = $this->provider(ReceivableMetrics::class)->load();
        $this->assertEquals(70, $value['balance']);
        $this->assertEquals(70, $value['overdue']);
        $this->assertSame(1, $value['count']);
    }

    public function testReadOnlySectionsDoNotCreateElectronicRecordsOrModifyHistory(): void
    {
        $this->invoice(1);
        $this->insert('tpvsneo', ['idtpv' => 1, 'idempresa' => 1, 'codalmacen' => 'A', 'name' => 'Caja Uno', 'coddivisa' => 'USD']);
        $this->insert('tpvsneo_cajas', ['idcaja' => 1, 'idtpv' => 1, 'nick' => 'ana', 'fechaini' => '2026-09-01 08:00:00',
            'fechafin' => '2026-09-01 18:00:00', 'totalcaja' => 51.75, 'dinerofin' => 50, 'diferencia' => -1.75]);
        $before = $this->db->select('SELECT * FROM tpvsneo_cajas');
        $service = new DashboardService($this->db, $this->access, 1, $this->filters, '2026-10-27');
        $data = $service->load('summary');
        $this->assertEquals(51.75, $data['cash']['last'][0]['totalcaja']);
        $this->assertSame($before, $this->db->select('SELECT * FROM tpvsneo_cajas'));
        $this->assertSame([], $this->db->select('SELECT * FROM sf_documents_signed'));
        $this->assertSame([], $data['cash']['history']['rows']);
    }

    public function testElectronicsIncludeMissingXmlAllSixTypesAndSkipReceivedPurchaseInvoices(): void
    {
        $this->invoice(1);
        $this->insert('notascreditocli', $this->document(['idnotacredito' => 1]));
        $this->insert('notasdebitocli', $this->document(['idnotadebito' => 1]));
        $delivery = $this->document(['idalbaran' => 1]);
        unset($delivery['numero2']);
        $this->insert('albaranescli', $delivery);
        $purchase = $this->document(['idfactura' => 1, 'codproveedor' => 'P1', 'tipocomprobantesri' => '03']);
        unset($purchase['codcliente'], $purchase['nombrecliente'], $purchase['numero2'], $purchase['codagente']);
        $this->insert('facturasprov', $purchase);
        $purchase['idfactura'] = 2;
        $purchase['tipocomprobantesri'] = '01';
        $this->insert('facturasprov', $purchase);
        $this->insert('sf_retenciones_compras', ['id' => 1, 'idfactura' => 1, 'codserie' => 'E', 'fecha' => '2026-10-27', 'codigo' => 'R1', 'nick' => 'ana']);
        $value = $this->provider(ElectronicDocuments::class)->load();
        $this->assertEquals(6, $value['count']);
        $this->assertCount(6, array_unique(array_column($value['list']['rows'], 'doc_type')));
        $this->assertSame([], $this->db->select('SELECT * FROM sf_documents_signed'));
        $this->insert('sf_documents_signed', ['id' => 1, 'doc_type' => 'FacturaCliente', 'doc_id' => 1, 'verified' => 1, 'status' => 'AUTORIZADO']);
        $this->assertEquals(5, $this->provider(ElectronicDocuments::class)->load()['count']);
    }

    public function testOwnerAndModulePermissionsApplyToAggregatesAndAuthorization(): void
    {
        $this->invoice(1, ['nick' => 'ana']);
        $this->invoice(2, ['nick' => 'bea', 'codagente' => 'AG2']);
        $this->access = new Access(true);
        $this->assertEquals(100, $this->provider(SalesMetrics::class)->load()['range']['billed']);
        $this->assertEquals(1, $this->provider(ElectronicDocuments::class)->load()['count']);
        $this->assertNull($this->provider(ElectronicDocuments::class)->find('FacturaCliente', 2));
        $this->access->permission('EditFacturaCliente')->allowUpdate = false;
        $this->assertFalse($this->provider(ElectronicDocuments::class)->find('FacturaCliente', 1)['can_authorize']);
        $this->access->permission('ListFacturaCliente')->allowAccess = false;
        $this->assertEquals(0, $this->provider(SalesMetrics::class)->load()['range']['billed']);
    }

    public function testInventoryCountsEachWarehouseSeparatelyAndPaginates(): void
    {
        $this->insert('productos', ['idproducto' => 1, 'descripcion' => 'Producto', 'nostock' => 0]);
        for ($id = 1; $id <= 28; ++$id) {
            $this->insert('stocks', ['idstock' => $id, 'idproducto' => 1, 'referencia' => 'SKU' . $id,
                'codalmacen' => $id === 28 ? 'B' : 'A', 'cantidad' => $id === 28 ? 90 : 12,
                'disponible' => 12, 'stockmin' => 10, 'stockmax' => 100]);
        }
        $value = $this->provider(InventoryMetrics::class)->load();
        $this->assertEquals(28, $value['counts']['alerts_count']);
        $this->assertCount(25, $value['list']['rows']);
        $this->filters['page'] = 2;
        $this->assertCount(3, $this->provider(InventoryMetrics::class)->load()['list']['rows']);
        $this->filters['warehouses'] = ['B'];
        $this->assertEquals(1, $this->provider(InventoryMetrics::class)->load()['counts']['high_count']);
    }

    public function testConnectionLockBlocksOtherWorkerAndReleasesAfterException(): void
    {
        $lock = new DocumentEmissionLock($this->db, 'fixture');
        $key = 'sri:' . substr(hash('sha256', 'fixture:FacturaCliente:1'), 0, 60);
        self::$connection->query("SELECT GET_LOCK('" . $key . "',0)");
        $called = false;
        $this->assertFalse($lock->run('FacturaCliente', 1, static function () use (&$called): bool { $called = true; return true; }));
        $this->assertFalse($called);
        self::$connection->query("SELECT RELEASE_LOCK('" . $key . "')");
        try {
            $lock->run('FacturaCliente', 1, static function (): bool { throw new \RuntimeException('fixture'); });
        } catch (\RuntimeException $exception) {
            $this->assertSame('fixture', $exception->getMessage());
        }
        $free = self::$connection->query("SELECT IS_FREE_LOCK('" . $key . "') AS available")->fetch_assoc();
        $this->assertEquals(1, $free['available']);
    }

    public function testEmissionLockRefreshesAnEarlierReadSnapshotWithoutCommittingCallerTransaction(): void
    {
        $this->invoice(1);
        $this->insert('sf_documents_signed', ['id' => 1, 'doc_id' => 1, 'doc_type' => 'FacturaCliente', 'verified' => 0]);
        $this->db->select('SELECT * FROM sf_documents_signed');
        self::$connection->query('UPDATE sf_documents_signed SET verified=1 WHERE id=1');
        $lock = new DocumentEmissionLock($this->db, 'fixture');
        $this->assertTrue($lock->run('FacturaCliente', 1, function (): bool {
            $rows = $this->db->select('SELECT verified FROM sf_documents_signed WHERE id=1');
            return (int)$rows[0]['verified'] === 1;
        }));
        $this->db->commit();
        $this->assertTrue($this->db->beginTransaction());
        $this->assertTrue($this->db->exec('UPDATE facturascli SET total=777 WHERE idfactura=1'));
        $this->assertTrue($lock->run('FacturaCliente', 1, static function (): bool { return true; }));
        $this->assertTrue($this->db->rollback());
        $rows = self::$connection->query('SELECT total FROM facturascli WHERE idfactura=1')->fetch_assoc();
        $this->assertEquals(100, $rows['total']);
    }

    public function testAccountingSettingDoesNotGateCommercialIndicatorsOrCreateJournalTables(): void
    {
        $this->invoice(1, ['total' => 51.75]);
        $before = $this->provider(SalesMetrics::class)->load();
        Tools::settingsSet('facturacione', 'allow_accounting', true);
        $after = $this->provider(SalesMetrics::class)->load();
        $this->assertSame($before, $after);
        $this->assertEquals(51.75, $after['range']['billed']);
        $this->assertFalse($this->db->tableExists('asientos'));
    }

    public function testAuthorizedResponseAndSourceStatusCommitTogetherAndLocalFailureCanBeRetried(): void
    {
        $this->invoice(1);
        $this->insert('sf_documents_signed', ['id' => 1, 'doc_id' => 1, 'doc_type' => 'FacturaCliente', 'verified' => 0, 'status' => 'PENDIENTE']);
        $document = (new \ReflectionClass(\FacturaScripts\Core\Model\FacturaCliente::class))->newInstanceWithoutConstructor();
        $record = new AuthorizationRecordProbe();
        AuthorizationControllerProbe::$fail = true;
        try {
            AuthorizationControllerProbe::checkStatusAuth($document, $record);
            $this->fail('Un fallo local no debe informar autorización completada.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('estado local', $exception->getMessage());
        }
        $this->assertFalse($record->verified);
        $this->assertSame('PENDIENTE', $record->status);
        $row = $this->db->select('SELECT verified FROM sf_documents_signed WHERE id=1');
        $this->assertEquals(0, $row[0]['verified']);
        AuthorizationControllerProbe::$fail = false;
        $this->assertTrue(AuthorizationControllerProbe::checkStatusAuth($document, $record));
        $row = $this->db->select('SELECT verified FROM sf_documents_signed WHERE id=1');
        $this->assertEquals(1, $row[0]['verified']);
        $xml = simplexml_load_string(AuthorizationControllerProbe::$xml);
        $this->assertSame('AUTORIZADO', (string)$xml->estado);
        $this->assertSame('<factura>original del SRI</factura>', (string)$xml->comprobante);
    }

    public function testRebuildInTemporaryFolderPublishesPluginControllerViewAndAssets(): void
    {
        $root = dirname(__DIR__, 3);
        symlink($root . '/Core', FS_FOLDER . '/Core');
        symlink($root . '/Plugins', FS_FOLDER . '/Plugins');
        $deploy = new PluginDeploy();
        $deploy->deploy($root . '/Plugins/', ['SpiderDashboard'], true);
        $this->assertStringContainsString('FacturaScripts\\Plugins\\SpiderDashboard\\Controller\\Dashboard',
            file_get_contents(FS_FOLDER . '/Dinamic/Controller/Dashboard.php'));
        $this->assertSame(file_get_contents(dirname(__DIR__) . '/View/Dashboard.html.twig'),
            file_get_contents(FS_FOLDER . '/Dinamic/View/Dashboard.html.twig'));
        $this->assertFileExists(FS_FOLDER . '/Dinamic/Assets/CSS/SpiderDashboard.css');
        $this->assertFileExists(FS_FOLDER . '/Dinamic/Assets/JS/SpiderDashboard.js');
    }

    private function provider(string $class)
    {
        return new $class($this->db, $this->access, 1, $this->filters, '2026-10-27');
    }

    private function document(array $values = []): array
    {
        return array_merge(['codigo' => 'F1', 'numero2' => '001-001-000000001', 'idempresa' => 1, 'codalmacen' => 'A',
            'codserie' => 'E', 'coddivisa' => 'USD', 'fecha' => '2026-10-27', 'total' => 100, 'neto' => 90,
            'nick' => 'ana', 'codagente' => 'AG1', 'codcliente' => 'C1', 'nombrecliente' => 'Cliente Uno',
            'cifnif' => '1234567890001', 'idestado' => 1, 'dtopor1' => 0, 'dtopor2' => 0], $values);
    }

    private function invoice(int $id, array $values = []): void
    {
        $this->insert('facturascli', $this->document(array_merge(['idfactura' => $id], $values)));
    }

    private function insert(string $table, array $values): void
    {
        $quoted = array_map(static function ($value): string {
            return $value === null ? 'NULL' : "'" . self::$connection->real_escape_string((string)$value) . "'";
        }, $values);
        if (!self::$connection->query('INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', $quoted) . ')')) {
            throw new \RuntimeException(self::$connection->error);
        }
        if ($this->db && $this->db->connected()) {
            $this->db->commit();
        }
    }

    private static function table(string $table, string $file): void
    {
        self::$connection->query('DROP TABLE IF EXISTS ' . $table);
        $xml = simplexml_load_file($file);
        $columns = [];
        foreach ($xml->column as $column) {
            $type = (string)$column->type;
            $type = str_ireplace(['character varying', 'double precision', 'boolean', 'integer', 'timestamp', 'serial'],
                ['VARCHAR', 'DOUBLE', 'TINYINT', 'INT', 'DATETIME', 'INT'], $type);
            $columns[] = '`' . (string)$column->name . '` ' . $type . ' NULL';
        }
        if (!self::$connection->query('CREATE TABLE ' . $table . ' (' . implode(',', $columns) . ')')) {
            throw new \RuntimeException($table . ': ' . self::$connection->error);
        }
        self::$tables[] = $table;
    }
}

/** La prueba conserva transacciones y escrituras reales; sustituye únicamente SOAP y el modelo de origen. */
class AuthorizationControllerProbe extends SriController
{
    public static $fail = false;
    public static $xml = '';

    public static function statusDocument(\FacturaScripts\Core\Model\Base\BusinessDocument $document)
    {
        return (object)['estado' => 'AUTORIZADO', 'fechaAutorizacion' => '2026-10-27T10:30:00-05:00',
            'numeroAutorizacion' => 'fixture-key', 'comprobante' => '<factura>original del SRI</factura>'];
    }

    public static function saveContentTo($document, $content, $folder = 'generado', $ext = '.xml')
    {
        self::$xml = $content;
    }

    public static function saveStatusDocument($document): bool
    {
        return !self::$fail && (new DataBase())->exec('UPDATE facturascli SET idestado=2 WHERE idfactura=1');
    }
}

class AuthorizationRecordProbe
{
    public $date_authorized;
    public $num_auth;
    public $message;
    public $status = 'PENDIENTE';
    public $verified = false;

    public function save(): bool
    {
        return (new DataBase())->exec('UPDATE sf_documents_signed SET verified=1 WHERE id=1');
    }
}
