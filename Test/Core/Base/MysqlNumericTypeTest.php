<?php

namespace FacturaScripts\Test\Core\Base;

use FacturaScripts\Core\Base\DataBase\MysqlEngine;
use PHPUnit\Framework\TestCase;

/**
 * Schema decisions with the actual SQL engine; no connection or instance config.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MysqlNumericTypeTest extends TestCase
{
    protected function setUp(): void
    {
        define('FS_FOLDER', dirname(__DIR__, 3));
        define('FS_LANG', 'es_EC');
        define('FS_DEBUG', false);
    }

    /** @dataProvider columnTypes */
    public function testOnlyEquivalentNumericDefinitionsSkipConversion(string $actual, string $expected, bool $same): void
    {
        define('FS_DB_TYPE_CHECK', true);
        $engine = new MysqlEngine();
        $this->assertSame($same, $engine->compareDataTypes($actual, $expected));
    }

    public function columnTypes(): array
    {
        return [
            'mysql numeric alias' => ['decimal(20,3)', 'numeric(20,3)', true],
            'reverse alias' => ['numeric(20,3)', 'decimal(20,3)', true],
            'same numeric' => ['numeric(20,3)', 'numeric(20,3)', true],
            'same decimal' => ['decimal(20,3)', 'decimal(20,3)', true],
            'case and spacing' => ['DECIMAL(20, 3)', 'numeric(20,3)', true],
            'precision increase' => ['decimal(18,3)', 'numeric(20,3)', false],
            'precision decrease' => ['decimal(22,3)', 'numeric(20,3)', false],
            'scale increase' => ['decimal(20,2)', 'numeric(20,3)', false],
            'scale decrease' => ['decimal(20,6)', 'numeric(20,3)', false],
            'float conversion' => ['double', 'numeric(20,3)', false],
            'integer conversion' => ['int(11)', 'numeric(20,3)', false],
            'unsigned attribute' => ['decimal(20,3) unsigned', 'numeric(20,3)', false],
            'incomplete numeric definition' => ['decimal(20,3)', 'numeric', false],
            'existing integer alias' => ['int(11)', 'integer', true],
            'existing boolean alias' => ['tinyint(1)', 'boolean', true],
            'existing float alias' => ['double', 'double precision', true],
            'existing varchar alias' => ['varchar(30)', 'character varying(30)', true],
            'existing varchar resize' => ['varchar(30)', 'character varying(80)', false],
        ];
    }

    public function testDisablingTypeChecksKeepsTheExistingContract(): void
    {
        define('FS_DB_TYPE_CHECK', false);
        $this->assertTrue((new MysqlEngine())->compareDataTypes('decimal(20,2)', 'numeric(20,3)'));
    }
}
