<?php

namespace FacturaScripts\Test\Plugins\SpiderBuilder;

use DateTimeImmutable;
use FacturaScripts\Plugins\SpiderBuilder\Lib\API\DemoProvisioning;
use FacturaScripts\Plugins\SpiderBuilder\Lib\DemoApiException;
use FacturaScripts\Plugins\SpiderBuilder\Lib\DemoHostGuard;
use FacturaScripts\Plugins\SpiderBuilder\Lib\DemoIdentity;
use FacturaScripts\Plugins\SpiderBuilder\Lib\DemoRegistrationService;
use FacturaScripts\Plugins\SpiderBuilder\Lib\MenuAccessSync;
use FacturaScripts\Plugins\SpiderBuilder\Lib\TenantProvisioner;
use FacturaScripts\Plugins\SpiderBuilder\Lib\Tools\FiscalNum;
use FacturaScripts\Core\Base\TenantMenuPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoProvisioningTest extends TestCase
{
    public function testCalendarMonthClampsNonLeapFebruary(): void
    {
        $start = new DateTimeImmutable('2025-01-31 15:45:12');
        $end = TenantProvisioner::calendarMonthLater($start);

        $this->assertSame('2025-02-28 15:45:12', $end->format('Y-m-d H:i:s'));
    }

    public function testCalendarMonthClampsLeapFebruary(): void
    {
        $start = new DateTimeImmutable('2024-01-31 08:10:00');
        $end = TenantProvisioner::calendarMonthLater($start);

        $this->assertSame('2024-02-29 08:10:00', $end->format('Y-m-d H:i:s'));
    }

    public function testCalendarMonthKeepsValidDayAcrossYear(): void
    {
        $start = new DateTimeImmutable('2025-12-15 23:59:59');
        $end = TenantProvisioner::calendarMonthLater($start);

        $this->assertSame('2026-01-15 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function testNickFallsBackToEmailPrefix(): void
    {
        $nick = DemoIdentity::suggestNick('Ventas.Norte@example.com', '1790012345001');

        $this->assertSame('ventas.norte', $nick);
        $this->assertTrue(DemoIdentity::isValid($nick));
    }

    public function testReservedAdminGetsDeterministicSuffix(): void
    {
        $first = DemoIdentity::suggestNick('admin@example.com', '1790012345001');
        $second = DemoIdentity::suggestNick('admin@example.com', '1790012345001');

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('admin_user_', $first);
        $this->assertTrue(DemoIdentity::isValid($first));
    }

    public function testActivationTokensAreOpaqueAndHashable(): void
    {
        $token = DemoRegistrationService::newToken();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertSame(64, strlen(DemoRegistrationService::hashToken($token)));
        $this->assertNotSame($token, DemoRegistrationService::hashToken($token));
    }

    public function testDemoResourcesAreDiscoverable(): void
    {
        $api = new DemoProvisioning(new Response(), Request::create('/api/3', 'GET'), []);

        $this->assertSame(
            ['demoLicenses', 'demoRegistrations'],
            array_keys($api->getResources())
        );
    }

    public function testHostGuardRejectsTenantCookie(): void
    {
        $cookieRequest = Request::create('/', 'GET', [], ['ruc' => '1790012345001']);
        try {
            DemoHostGuard::assertHost($cookieRequest);
            $this->fail('La cookie tenant debía rechazarse');
        } catch (DemoApiException $exception) {
            $this->assertSame(403, $exception->httpStatus());
        }
    }

    public function testApiReturnsSafeForbiddenResponseForTenantCookie(): void
    {
        $request = Request::create(
            '/api/3/demoLicenses',
            'GET',
            [],
            ['ruc' => '1790012345001'],
            [],
            []
        );
        $response = new Response();
        $api = new DemoProvisioning($response, $request, []);

        $this->assertFalse($api->processResource('demoLicenses'));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Este recurso solo está disponible en el host'],
            json_decode((string)$response->getContent(), true)
        );
    }

    public function testMenuAccessLegacyFormatsNormalizeToBooleanMap(): void
    {
        $map = MenuAccessSync::normalize([
            ['name' => 'ListCliente', 'show' => 1],
            ['name' => 'EditCliente', 'show' => 0],
            'Dashboard',
        ]);

        $this->assertSame([
            'ListCliente' => true,
            'EditCliente' => false,
            'Dashboard' => true,
        ], $map);
    }

    public function testHomepagePrefersVisibleAllowedPageAndFallsBackToAuxiliary(): void
    {
        $sync = new class extends MenuAccessSync {
            public function masterPages(): array
            {
                return [
                    (object)['name' => 'EditServicioAT', 'showonmenu' => false],
                    (object)['name' => 'ListDenied', 'showonmenu' => true],
                    (object)['name' => 'ListServicioAT', 'showonmenu' => true],
                ];
            }
        };

        $this->assertSame('ListServicioAT', $sync->homepageForMap([
            'EditServicioAT' => true,
            'ListDenied' => false,
            'ListServicioAT' => true,
        ]));
        $this->assertSame('EditServicioAT', $sync->homepageForMap([
            'EditServicioAT' => true,
        ]));
        $this->assertNull($sync->homepageForMap([]));
    }

    public function testTenantPolicyLeavesLoginAndApisOutsideWhitelist(): void
    {
        $this->assertTrue(TenantMenuPolicy::isExempt('Login'));
        $this->assertTrue(TenantMenuPolicy::isExempt('ApiRoot'));
        $this->assertTrue(TenantMenuPolicy::isExempt('ApiCreateFacturaCliente'));
        $this->assertFalse(TenantMenuPolicy::isExempt('ListFacturaCliente'));
    }

    public function testFiscalNumOwnsItsSriServiceCredential(): void
    {
        $key = (new \ReflectionClass(FiscalNum::class))->getConstant('KEY');

        $this->assertIsString($key);
        $this->assertNotSame('', trim($key));
    }
}
