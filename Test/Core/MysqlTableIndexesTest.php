<?php

namespace FacturaScripts\Test\Core;

use FacturaScripts\Core\Lib\MysqlTableIndexes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MysqlTableIndexesTest extends TestCase
{
    public function testAnExistingWiderIndexAvoidsADuplicate(): void
    {
        $rows = [$this->part('custom', 1, 'codcliente'), $this->part('custom', 2, 'pagada'),
            $this->part('custom', 3, 'fecha')];
        $this->assertSame([], MysqlTableIndexes::missing(['balance' => ['codcliente', 'pagada']], $rows));
        $this->assertSame(['balance' => ['pagada', 'codcliente']],
            MysqlTableIndexes::missing(['balance' => ['pagada', 'codcliente']], $rows));
    }

    public function testPrefixTextAndIgnoredIndexesDoNotProvideFullCoverage(): void
    {
        foreach ([['Sub_part' => 4], ['Ignored' => 'YES'], ['Visible' => 'NO'], ['Index_type' => 'FULLTEXT']] as $change) {
            $row = array_replace($this->part('custom', 1, 'channel'), $change);
            $this->assertSame(['channels' => ['channel']],
                MysqlTableIndexes::missing(['channels' => ['channel']], [$row]));
        }
    }

    public function testAConflictingNameDoesNotProduceAReplacement(): void
    {
        $this->expectException(RuntimeException::class);
        MysqlTableIndexes::missing(['channels' => ['channel']], [$this->part('channels', 1, 'id')]);
    }

    public function testXmlDeclaresNonUniqueIndexesIndependentlyOfForeignKeys(): void
    {
        $xml = simplexml_load_string('<table><index name="balance"><column name="codcliente"/>'
            . '<column name="pagada"/></index></table>');
        $this->assertSame(['balance' => ['codcliente', 'pagada']], MysqlTableIndexes::readXml($xml));
    }

    public function testMalformedIdentifiersAreRejectedBeforeGeneratingSql(): void
    {
        $this->expectException(RuntimeException::class);
        MysqlTableIndexes::readXml(simplexml_load_string('<table><index name="bad;name"><column name="id"/></index></table>'));
    }

    public function testDuplicateDefinitionsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        MysqlTableIndexes::readXml(simplexml_load_string('<table><index name="a"><column name="id"/></index>'
            . '<index name="a"><column name="channel"/></index></table>'));
    }

    private function part(string $name, int $position, string $column): array
    {
        return ['Key_name' => $name, 'Seq_in_index' => $position, 'Column_name' => $column,
            'Sub_part' => null, 'Index_type' => 'BTREE'];
    }
}
