<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Test;

use DateTimeImmutable;
use FacturaScripts\Core\Lib\CustomerBalance;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardFilters;
use FacturaScripts\Plugins\SpiderDashboard\Lib\ElectronicDocuments;
use FacturaScripts\Plugins\SpiderDashboard\Lib\InventoryMetrics;
use FacturaScripts\Plugins\SpiderDashboard\Test\Fixtures\Access;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    public function testFiltersKeepMultipleWarehousesAndInclusiveEquivalentPeriod(): void
    {
        $filters = DashboardFilters::normalize(['from' => '2026-10-01', 'to' => '2026-10-27',
            'warehouses' => ['A', 'B', 'A'], 'page' => -1], '2026-10-27');
        $this->assertSame(['A', 'B'], $filters['warehouses']);
        $this->assertSame(1, $filters['page']);
        $this->assertSame(['from' => '2026-09-04', 'to' => '2026-09-30'], DashboardFilters::previous($filters));
        $this->assertSame('2026-10-01', DashboardFilters::normalize([], '2026-10-27')['from']);
    }

    /** @dataProvider invalidFilters */
    public function testInvalidFiltersCannotSilentlyBroadenTheContext(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DashboardFilters::normalize($input, '2026-10-27');
    }

    public static function invalidFilters(): array
    {
        return [[['from' => '2026-02-30']], [['from' => '2026-10-28', 'to' => '2026-10-01']],
            [['warehouses' => 'A']], [['warehouses' => [['A']]]], [['warehouses' => ['larger']]]];
    }

    /** @dataProvider stockCases */
    public function testStockUsesAvailableForMinimumAndPhysicalForMaximum(array $values, array $expected): void
    {
        $stock = array_merge(['cantidad' => 50, 'disponible' => 50, 'stockmin' => 10, 'stockmax' => 100], $values);
        $this->assertSame($expected, InventoryMetrics::classify($stock));
    }

    public static function stockCases(): array
    {
        return [
            [['disponible' => 12], ['low']], [['disponible' => 12.01], []], [['disponible' => 10], ['below']],
            [['cantidad' => 80], ['high']], [['cantidad' => 100], ['above']],
            [['cantidad' => -2, 'disponible' => 0], ['out', 'below']],
            [['stockmin' => 0, 'stockmax' => 0, 'disponible' => 1], []],
            [['cantidad' => 80, 'disponible' => 9], ['below', 'high']],
            [['stockmin' => 100, 'stockmax' => 90], ['below', 'invalid']],
        ];
    }

    public function testAgingUsesActualPaymentsReversalsAndLimitsDebtAfterNegativeAdjustments(): void
    {
        $invoice = CustomerBalance::build(['total' => 100], [
            ['importe' => 100, 'pagado' => false, 'vencimiento' => '2026-09-01', 'payments' => [
                ['importe' => 40], ['importe' => 20, 'estado' => 'REVERTIDO']]],
            ['importe' => -40, 'pagado' => false, 'vencimiento' => '2026-09-01'],
        ], new DateTimeImmutable('2026-10-27'));
        $this->assertEquals(60, $invoice['balance']);
        $this->assertEquals(60, array_sum(CustomerBalance::aging($invoice)));
        $this->assertEquals(60, CustomerBalance::aging($invoice)['days60']);
        $overpaid = CustomerBalance::build(['total' => 51.75], [
            ['importe' => 60, 'pagado' => true, 'vencimiento' => '2026-01-01'],
        ], new DateTimeImmutable('2026-10-27'));
        $this->assertEquals(0, array_sum(CustomerBalance::aging($overpaid)));
        $this->assertEquals(51.75, $overpaid['total']);
    }

    public function testTodayIsNotOverdueAndMissingDueDateRemainsVisible(): void
    {
        $invoice = CustomerBalance::build(['total' => 100], [
            ['importe' => 60, 'pagado' => false, 'vencimiento' => '2026-10-27'],
            ['importe' => 40, 'pagado' => false, 'vencimiento' => null],
        ], new DateTimeImmutable('2026-10-27'));
        $this->assertEquals(60, CustomerBalance::aging($invoice)['current']);
        $this->assertEquals(40, CustomerBalance::aging($invoice)['undated']);
    }

    public function testAccessCombinesDashboardOwnerRestrictionAndModulePermissions(): void
    {
        $access = new Access(true);
        $this->assertTrue($access->owns('EditFacturaCliente', ['nick' => 'ana']));
        $this->assertTrue($access->owns('EditFacturaCliente', ['nick' => null]));
        $this->assertTrue($access->owns('EditFacturaCliente', ['nick' => 'bea', 'codagente' => 'AG1']));
        $this->assertFalse($access->owns('EditFacturaCliente', ['nick' => 'bea', 'codagente' => 'AG2']));
        $access->permission('EditFacturaCliente')->allowUpdate = false;
        $this->assertFalse($access->can('EditFacturaCliente', true));
    }

    public function testSriMessagesExposeUserInformationWithoutStackTraces(): void
    {
        $json = json_encode(['respuesta' => ['mensaje' => 'Identificación incorrecta', 'informacionAdicional' => 'Revisa el RUC']]);
        $this->assertSame('Identificación incorrecta · Revisa el RUC', ElectronicDocuments::message($json, 'DEVUELTO'));
        $this->assertSame('', ElectronicDocuments::message('{"trace":"/secret.php"}', 'ERROR SRI'));
        $this->assertStringNotContainsString('/secret', ElectronicDocuments::message('/secret.php', 'ERROR INTERNO'));
    }
}
