<?php

namespace FacturaScripts\Test\Core;

use FacturaScripts\Core\Base\DataBase\MysqlQueries;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\DbUpdater;
use PHPUnit\Framework\TestCase;

/**
 * Real schema comparison and checked-table cache, with no instance configuration or database.
 * Run with vendor/autoload.php, never Test/bootstrap.php.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DbUpdaterRetryTest extends TestCase
{
    private $temporaryFolder;

    protected function setUp(): void
    {
        $this->temporaryFolder = sys_get_temp_dir() . '/db-updater-retry-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryFolder . '/MyFiles', 0700, true);
        define('FS_FOLDER', $this->temporaryFolder);
        define('FS_DEBUG', false);
        $this->assertFalse(class_exists('FacturaScripts\\Core\\Base\\DataBase', false));
        class_alias(DbUpdaterRetryDatabaseProbe::class, 'FacturaScripts\\Core\\Base\\DataBase');
        MiniLog::clear();
    }

    protected function tearDown(): void
    {
        $file = $this->temporaryFolder . '/MyFiles/' . DbUpdater::FILE_NAME;
        if (file_exists($file)) unlink($file);
        rmdir($this->temporaryFolder . '/MyFiles');
        rmdir($this->temporaryFolder);
        MiniLog::clear();
    }

    public function testFailedAlterRemainsPendingUntilASuccessfulRetry(): void
    {
        $file = FS_FOLDER . '/MyFiles/' . DbUpdater::FILE_NAME;
        $existingCache = json_encode(['default' => ['previously_checked']]);
        file_put_contents($file, $existingCache);
        DbUpdaterRetryDatabaseProbe::$execResult = false;

        $this->assertFalse(DbUpdater::updateTable('payments', $this->structure()));
        $this->assertFalse(DbUpdater::isTableChecked('payments'));
        $this->assertTrue(DbUpdater::isTableChecked('previously_checked'));
        $this->assertSame($existingCache, file_get_contents($file));
        $this->assertCount(1, DbUpdaterRetryDatabaseProbe::$statements);
        $this->assertStringContainsString('ALTER TABLE payments ADD', DbUpdaterRetryDatabaseProbe::$statements[0]);

        DbUpdaterRetryDatabaseProbe::$execResult = true;
        $this->assertTrue(DbUpdater::updateTable('payments', $this->structure()));
        $this->assertTrue(DbUpdater::isTableChecked('payments'));
        $this->assertCount(2, DbUpdaterRetryDatabaseProbe::$statements);
        $this->assertSame(DbUpdaterRetryDatabaseProbe::$statements[0], DbUpdaterRetryDatabaseProbe::$statements[1]);
        $this->assertSame(['default' => ['previously_checked', 'payments']], json_decode(file_get_contents($file), true));

        $this->assertFalse(DbUpdater::updateTable('payments', $this->structure()));
        $this->assertSame(2, DbUpdaterRetryDatabaseProbe::$columnReads);
        $this->assertCount(2, DbUpdaterRetryDatabaseProbe::$statements);
    }

    public function testMatchingStructureIsCheckedWithoutExecutingSql(): void
    {
        DbUpdaterRetryDatabaseProbe::$columns = [
            ['name' => 'id', 'type' => 'integer', 'default' => null, 'is_nullable' => 'YES'],
        ];

        // The false return value means no SQL was executed, even though the check succeeded.
        $this->assertFalse(DbUpdater::updateTable('payments', $this->structure()));
        $this->assertTrue(DbUpdater::isTableChecked('payments'));
        $this->assertSame([], DbUpdaterRetryDatabaseProbe::$statements);
        $this->assertSame(['default' => ['payments']], json_decode(file_get_contents(
            FS_FOLDER . '/MyFiles/' . DbUpdater::FILE_NAME
        ), true));
        $this->assertFalse(DbUpdater::updateTable('payments', $this->structure()));
        $this->assertSame(1, DbUpdaterRetryDatabaseProbe::$columnReads);
    }

    private function structure(): array
    {
        return [
            'columns' => [['name' => 'id', 'type' => 'integer', 'default' => '', 'null' => 'YES']],
            'constraints' => [],
        ];
    }
}

final class DbUpdaterRetryDatabaseProbe
{
    public static $execResult = true, $columns = [], $statements = [], $columnReads = 0;
    public function connect(): bool { return true; }
    public function exec(string $sql): bool
    {
        self::$statements[] = $sql;
        return self::$execResult;
    }
    public function getColumns(string $table): array { ++self::$columnReads; return self::$columns; }
    public function getConstraints(string $table): array { return []; }
    public function getEngine(): DbUpdaterRetryEngineProbe { return new DbUpdaterRetryEngineProbe(); }
}

final class DbUpdaterRetryEngineProbe
{
    public function getSQL(): MysqlQueries { return new MysqlQueries(); }
    public function compareDataTypes(string $actual, string $expected): bool { return $actual === $expected; }
}
