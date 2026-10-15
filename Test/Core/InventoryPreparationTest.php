<?php

namespace FacturaScripts\Test\Core;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\MysqlEngine;
use FacturaScripts\Core\Lib\Inventory\Inventory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Actual transaction coordinator with an in-memory SQL boundary; no config or DB.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InventoryPreparationTest extends TestCase
{
    public function testPreparationCannotCommitAnOuterTransactionsChanges(): void
    {
        define('FS_DB_TYPE', 'mysql');
        define('FS_DB_NAME', 'inventory-preparation-test-only');
        define('FS_DEBUG', false);
        define('FS_FOLDER', dirname(__DIR__, 2));
        define('FS_LANG', 'es_EC');
        $db = new DataBase();
        $engine = new InventoryPreparationEngine();
        foreach (['engine' => $engine, 'link' => (object) ['test' => true]] as $property => $value) {
            $reflection = new ReflectionProperty(DataBase::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $value);
        }

        $preparations = 0;
        $insideBeforePrepare = null;
        Inventory::registerRecorder(
            static function (): bool {
                return true;
            },
            static function () use ($db, &$preparations, &$insideBeforePrepare): void {
                $preparations++;
                $insideBeforePrepare = $db->inTransaction();
                $db->exec('CREATE TABLE preparation_probe (id int)');
            }
        );
        $this->assertTrue($db->beginTransaction());
        $this->assertTrue($db->exec('INSERT INTO preparation_probe VALUES (1)'));
        $this->assertFalse(Inventory::run($db, static function (): bool {
            return false;
        }));
        $this->assertTrue($db->rollback());
        $this->assertSame([], $engine->rows);
        $this->assertFalse($insideBeforePrepare);
        $this->assertTrue($db->beginTransaction());
        $this->assertTrue($db->rollback());
        $this->assertSame(1, $preparations);
    }
}

class InventoryPreparationEngine extends MysqlEngine
{
    public $rows = [];
    private $open = false;
    private $snapshot = [];

    public function beginTransaction($link)
    {
        $this->snapshot = $this->rows;
        $this->open = true;
        return true;
    }

    public function inTransaction($link)
    {
        return $this->open;
    }

    public function exec($link, $sql)
    {
        if (strpos($sql, 'CREATE TABLE') === 0) {
            $this->snapshot = $this->rows;
        } elseif (strpos($sql, 'INSERT INTO') === 0) {
            $this->rows[] = 1;
        }
        return true;
    }

    public function commit($link)
    {
        $this->open = false;
        return true;
    }

    public function rollback($link)
    {
        $this->rows = $this->snapshot;
        $this->open = false;
        return true;
    }
}
