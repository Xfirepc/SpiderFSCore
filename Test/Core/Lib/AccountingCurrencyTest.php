<?php

namespace FacturaScripts\Test\Core\Lib;

use FacturaScripts\Core\DataSrc\Divisas;
use FacturaScripts\Core\Lib\Accounting\AccountingCurrency;
use FacturaScripts\Core\Lib\Accounting\InvoiceToAccounting;
use FacturaScripts\Core\Lib\Accounting\PaymentToAccounting;
use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Model\Base\CurrencyRelationTrait;
use FacturaScripts\Core\Model\Partida;
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Divisa;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** Ejecutar con vendor/autoload.php, sin configuración ni BD de la instancia. */
final class AccountingCurrencyTest extends TestCase
{
    private $settings;
    private $currencies;

    protected function setUp(): void
    {
        if (!defined('FS_NF0')) {
            define('FS_NF0', 2);
        }
        $this->settings = $this->replace(Tools::class, 'settings', ['default' => ['coddivisa' => 'USD']]);
        $this->currencies = $this->replace(Divisas::class, 'list', []);
    }

    protected function tearDown(): void
    {
        $this->replace(Tools::class, 'settings', $this->settings);
        $this->replace(Divisas::class, 'list', $this->currencies);
    }

    /** @dataProvider currencyAmounts */
    public function testEffectiveAmount(string $base, ?string $currency, float $rate, float $expected): void
    {
        Tools::settingsSet('default', 'coddivisa', $base);
        $this->assertSame($expected, AccountingCurrency::amount(51.75, $currency, $rate));
    }

    public static function currencyAmounts(): array
    {
        return [
            ['USD', 'USD', 1.129, 51.75],
            ['USD', 'USD', 2.0, 51.75],
            ['USD', 'USD', 0.0, 51.75],
            ['EUR', 'USD', 1.129, 45.84],
            ['USD', 'EUR', 2.0, 25.88],
            ['USD', null, 2.0, 25.88],
        ];
    }

    public function testNewPurchasesAndSalesIgnoreLegacyDollarCatalogRate(): void
    {
        $currency = (new ReflectionClass(Divisa::class))->newInstanceWithoutConstructor();
        $currency->coddivisa = 'USD';
        $currency->tasaconv = 1.129;
        $currency->tasaconvcompra = 1.15;
        $this->replace(Divisas::class, 'list', [$currency]);
        foreach ([true, false] as $purchase) {
            $document = new class { use CurrencyRelationTrait; };
            $document->setCurrency('USD', $purchase);
            $this->assertSame('USD', $document->coddivisa);
            $this->assertSame(1.0, $document->tasaconv);
        }
        $this->assertSame(1.129, $currency->tasaconv);
        $this->assertSame(1.15, $currency->tasaconvcompra);
    }

    /** @dataProvider paymentDirections */
    public function testPaymentLinesAndHeaderUseOriginalDollars(bool $customer, float $amount): void
    {
        $account = $this->getMockBuilder(Subcuenta::class)->disableOriginalConstructor()->onlyMethods(['exists'])->getMock();
        $account->method('exists')->willReturn(true);
        $lines = [];
        for ($i = 0; $i < 2; ++$i) {
            $line = $this->getMockBuilder(Partida::class)->disableOriginalConstructor()->onlyMethods(['save'])->getMock();
            $line->expects($this->once())->method('save')->willReturn(true);
            $lines[] = $line;
        }
        $entry = $this->getMockBuilder(Asiento::class)->disableOriginalConstructor()->onlyMethods(['getNewLine', 'getLines'])->getMock();
        // Dos partidas: nunca crear una diferencia de cambio ficticia USD/USD.
        $entry->expects($this->exactly(2))->method('getNewLine')->willReturnOnConsecutiveCalls(...$lines);
        $entry->method('getLines')->willReturn($lines);
        $payment = (object)['coddivisa' => 'USD', 'tasaconv' => 1.129, 'importe' => $amount, 'gastos' => 0, 'fecha' => '09-09-2026'];
        $receipt = new UsdAccountingReceipt($account);
        $service = new UsdPaymentAccounting($payment, $receipt, $account);
        $this->assertTrue($service->lines($entry, $customer));
        $this->assertSame(abs($amount), $entry->importe);
        $bankDebit = ($customer && $amount > 0) || (!$customer && $amount < 0);
        $this->assertSame($bankDebit ? abs($amount) : 0.0, (float)$lines[0]->debe);
        $this->assertSame($bankDebit ? 0.0 : abs($amount), (float)$lines[0]->haber);
        $this->assertSame((float)$lines[0]->debe, (float)$lines[1]->haber);
        $this->assertSame((float)$lines[0]->haber, (float)$lines[1]->debe);
        foreach ($lines as $line) {
            $this->assertSame('USD', $line->coddivisa);
            $this->assertSame(1.0, $line->tasaconv);
        }
        // Calcular un asiento nuevo no reescribe los objetos históricos origen.
        $this->assertSame(1.129, $payment->tasaconv);
        $this->assertSame(1.15, $receipt->getInvoice()->tasaconv);
    }

    public static function paymentDirections(): array
    {
        return [[true, 51.75], [false, 51.75], [true, -51.75], [false, -51.75]];
    }

    public function testInvoiceBasicLinesUseDollarsAndPreserveSourceData(): void
    {
        $document = (object)['coddivisa' => 'USD', 'tasaconv' => 1.129, 'total' => 51.75];
        $service = new UsdInvoiceAccounting($document);
        $account = $this->getMockBuilder(Subcuenta::class)->disableOriginalConstructor()->getMock();
        foreach ([true, false] as $debit) {
            $line = $this->getMockBuilder(Partida::class)->disableOriginalConstructor()->onlyMethods(['setAccount'])->getMock();
            $line->method('setAccount')->willReturnSelf();
            $entry = $this->getMockBuilder(Asiento::class)->disableOriginalConstructor()->onlyMethods(['getNewLine'])->getMock();
            $entry->method('getNewLine')->willReturn($line);
            $this->assertSame($line, $service->line($entry, $account, $debit));
            $this->assertSame($debit ? 51.75 : 0.0, (float)$line->debe);
            $this->assertSame($debit ? 0.0 : 51.75, (float)$line->haber);
            $this->assertSame(1.0, $line->tasaconv);
        }
        $this->assertSame(1.129, $document->tasaconv);
    }

    private function replace(string $class, string $field, $value)
    {
        $property = new ReflectionProperty($class, $field);
        $property->setAccessible(true);
        $previous = $property->getValue();
        $property->setValue(null, $value);
        return $previous;
    }
}

class UsdAccountingReceipt
{
    public $coddivisa = 'USD';
    private $account;
    private $invoice;
    public function __construct($account)
    {
        $this->account = $account;
        $this->invoice = new class {
            public $coddivisa = 'USD', $tasaconv = 1.15, $codigo = 'FAC2026FE111';
            public function getSerie() { return (object)['canal' => '']; }
        };
    }
    public function getInvoice() { return $this->invoice; }
    public function getSubject() { return $this; }
    public function getSubcuenta($exercise, $create) { return $this->account; }
}

class UsdPaymentAccounting extends PaymentToAccounting
{
    private $account;
    public function __construct($payment, $receipt, $account)
    {
        $this->payment = $payment;
        $this->receipt = $receipt;
        $this->account = $account;
        $this->exercise = (object)['codejercicio' => '2026', 'idempresa' => 1];
    }
    protected function getTreasuryAccount(bool $expenses) { return $this->account; }
    public function lines(Asiento $entry, bool $customer): bool
    {
        $this->setCommonData($entry, 'Pago USD', $this->receipt->getInvoice());
        return ($customer
            ? $this->customerPaymentBankLine($entry) && $this->customerPaymentLine($entry)
            : $this->supplierPaymentBankLine($entry) && $this->supplierPaymentLine($entry))
            && $this->paymentExchangeDifferenceLine($entry);
    }
}

class UsdInvoiceAccounting extends InvoiceToAccounting
{
    public function __construct($document) { $this->document = $document; }
    public function line($entry, $account, bool $debit) { return $this->getBasicLine($entry, $account, $debit); }
}
