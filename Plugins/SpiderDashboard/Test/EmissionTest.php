<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Test;

use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Dinamic\Controller\ElectronicDocController;
use FacturaScripts\Plugins\SpiderFact\Lib\DocumentElectronic;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EmissionTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__ . '/Fixtures/EmissionClient.php';
        EmissionProbe::$electronic = (object)['verified' => false, 'status' => 'PENDIENTE'];
    }

    public function testDashboardEmissionDoesNotPrintOrSendEmail(): void
    {
        $this->assertTrue(EmissionProbe::run($this->document(), false));
        $this->assertSame(['generate', 'sign', 'receive'], ElectronicDocController::$calls);
    }

    public function testExistingCallersKeepTheirRequestedEmailBehavior(): void
    {
        $this->assertTrue(EmissionProbe::run($this->document(), true));
        $this->assertSame(['generate', 'sign', 'receive', 'print', 'email'], ElectronicDocController::$calls);
    }

    public function testAlreadyAuthorizedNeverRegenerates(): void
    {
        EmissionProbe::$electronic->verified = true;
        $this->assertTrue(EmissionProbe::run($this->document(), false));
        $this->assertSame([], ElectronicDocController::$calls);
    }

    public function testRemoteAuthorizationAfterTimeoutIsConsultedBeforeRegeneration(): void
    {
        EmissionProbe::$electronic->date_generated = '2026-10-27 10:00:00';
        ElectronicDocController::$remoteAuthorized = true;
        $this->assertTrue(EmissionProbe::run($this->document(), false));
        $this->assertSame(['consult'], ElectronicDocController::$calls);
    }

    public function testReceivedDocumentOnlyRequestsAuthorization(): void
    {
        EmissionProbe::$electronic->status = 'RECIBIDA';
        $this->assertTrue(EmissionProbe::run($this->document(), false));
        $this->assertSame(['consult', 'authorize'], ElectronicDocController::$calls);
    }

    public function testRecoveredAuthorizationKeepsTheEmailRequestedByExistingCallers(): void
    {
        foreach ([true, false] as $remoteAuthorized) {
            ElectronicDocController::$calls = [];
            ElectronicDocController::$remoteAuthorized = $remoteAuthorized;
            EmissionProbe::$electronic->date_generated = '2026-10-27 10:00:00';
            EmissionProbe::$electronic->status = $remoteAuthorized ? 'PENDIENTE' : 'RECIBIDA';
            $this->assertTrue(EmissionProbe::run($this->document(), true));
            $expected = $remoteAuthorized ? ['consult', 'print', 'email'] : ['consult', 'authorize', 'print', 'email'];
            $this->assertSame($expected, ElectronicDocController::$calls);
        }
    }

    public function testConsultationFailureDoesNotDeleteOrGenerateXml(): void
    {
        EmissionProbe::$electronic->date_sign = '2026-10-27 10:00:00';
        ElectronicDocController::$timeout = true;
        try {
            EmissionProbe::run($this->document(), false);
            $this->fail('Debe conservar el error de consulta.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(['consult'], ElectronicDocController::$calls);
        }
    }

    private function document(): BusinessDocument
    {
        $document = (new ReflectionClass(FacturaCliente::class))->newInstanceWithoutConstructor();
        $document->nick = 'ana';
        return $document;
    }
}

class EmissionProbe extends DocumentElectronic
{
    public static $electronic;

    public static function ebilling($document)
    {
        return self::$electronic;
    }

    public static function run(BusinessDocument $document, bool $sent): bool
    {
        return parent::processLockedDocument($document, $sent);
    }
}
