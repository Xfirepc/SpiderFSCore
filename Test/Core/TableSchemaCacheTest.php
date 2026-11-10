<?php

namespace FacturaScripts\Test\Core;

use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Lib\TableSchemaCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Caché real en archivos temporales, sin configuración ni conexión de una instancia.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TableSchemaCacheTest extends TestCase
{
    private $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/table-schema-cache-' . bin2hex(random_bytes(8));
        mkdir($this->folder . '/MyFiles', 0700, true);
        define('FS_FOLDER', $this->folder);
        define('FS_DEBUG', false);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->folder . '/MyFiles') as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->folder . '/MyFiles/' . $file);
            }
        }
        rmdir($this->folder . '/MyFiles');
        rmdir($this->folder);
    }

    public function testLegacyTenantMapIsPreservedAndNewWritesAreMerged(): void
    {
        file_put_contents($this->path(), json_encode(['tenant-a' => ['old'], 'tenant-b' => ['other']]));
        $generation = TableSchemaCache::generation();
        TableSchemaCache::remember('tenant-a', 'first', $generation);
        TableSchemaCache::remember('tenant-a', 'second', $generation);
        TableSchemaCache::remember('tenant-a', 'first', $generation);
        $this->assertSame(['old', 'first', 'second'], TableSchemaCache::read('tenant-a')['tables']);
        $this->assertSame(['other'], TableSchemaCache::read('tenant-b')['tables']);
        $this->assertSame(['tenant-a', 'tenant-b'], array_keys(json_decode(file_get_contents($this->path()), true)));
    }

    public function testRebuildRejectsAnOlderRequestAndRefreshesStaticChecks(): void
    {
        $generation = TableSchemaCache::generation();
        TableSchemaCache::remember('default', 'payments', $generation);
        $this->assertTrue(DbUpdater::isTableChecked('payments'));
        DbUpdater::rebuild();
        $this->assertFalse(DbUpdater::isTableChecked('payments'));
        $this->assertNull(TableSchemaCache::remember('default', 'payments', $generation));
        $this->assertSame([], TableSchemaCache::read('default')['tables']);
    }

    public function testForgettingOneDatabasePreservesOthersAndInvalidatesOlderWriters(): void
    {
        $generation = TableSchemaCache::generation();
        TableSchemaCache::remember('tenant-a', 'payments', $generation);
        TableSchemaCache::remember('tenant-b', 'products', $generation);
        DbUpdater::forgetDatabase('tenant-a');
        $this->assertSame([], TableSchemaCache::read('tenant-a')['tables']);
        $this->assertSame(['products'], TableSchemaCache::read('tenant-b')['tables']);
        $this->assertNull(TableSchemaCache::remember('tenant-a', 'payments', $generation));
    }

    public function testInterruptedInvalidationIsCompletedBeforeReading(): void
    {
        $generation = TableSchemaCache::generation();
        TableSchemaCache::remember('tenant-a', 'payments', $generation);
        TableSchemaCache::remember('tenant-b', 'products', $generation);
        $next = str_repeat('a', 32);
        file_put_contents($this->folder . '/MyFiles/.db-updater.lock', json_encode([
            'generation' => $next, 'pending' => true, 'database' => 'tenant-a',
        ]));
        $this->assertSame([], TableSchemaCache::read('tenant-a')['tables']);
        $this->assertSame(['products'], TableSchemaCache::read('tenant-b')['tables']);
        $this->assertSame($next, TableSchemaCache::generation());
        $state = json_decode(file_get_contents($this->folder . '/MyFiles/.db-updater.lock'), true);
        $this->assertFalse($state['pending']);
    }

    public function testCorruptCacheCannotSilentlyOverwriteOtherTenants(): void
    {
        TableSchemaCache::generation();
        file_put_contents($this->path(), '{"tenant-a": [');
        $this->expectException(RuntimeException::class);
        TableSchemaCache::remember('tenant-b', 'payments', TableSchemaCache::generation());
    }

    public function testLegacyListDoesNotLeakChecksToAnotherDatabase(): void
    {
        file_put_contents($this->path(), json_encode(['payments', 'products']));
        $this->assertSame([], TableSchemaCache::read('default')['tables']);
    }

    private function path(): string
    {
        return $this->folder . '/MyFiles/db-updater.json';
    }
}
