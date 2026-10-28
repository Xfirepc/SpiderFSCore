<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Test;

use FacturaScripts\Plugins\SpiderDashboard\Controller\Dashboard;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardService;
use FacturaScripts\Plugins\SpiderDashboard\Lib\ElectronicDocuments;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('FS_FOLDER')) {
            define('FS_FOLDER', dirname(__DIR__, 3));
        }
    }

    /** @dataProvider rejectedActions */
    public function testDirectActionsCannotBypassMethodTokenTypeOrDocumentPermissions(string $method, bool $token, array $input, ?array $record, int $status, int $reads): void
    {
        $controller = new DashboardControllerProbe();
        $controller->request = Request::create('/Dashboard?action=authorize-document', $method, $input);
        $controller->multiRequestProtection = new TokenProbe($token);
        $service = new DashboardServiceProbe($record);
        $action = new ReflectionMethod(Dashboard::class, 'authorize');
        $action->setAccessible(true);
        $action->invoke($controller, $service);
        $this->assertSame($status, $controller->result()->getStatusCode());
        $this->assertSame($reads, $service->provider->reads);
        $this->assertNotEmpty(json_decode($controller->result()->getContent(), true)['token']);
    }

    public static function rejectedActions(): array
    {
        $valid = ['doc_type' => 'FacturaCliente', 'doc_id' => 1, 'multireqtoken' => 'token'];
        return [
            ['GET', true, $valid, null, 405, 0],
            ['POST', false, $valid, null, 403, 0],
            ['POST', true, ['doc_type' => 'User', 'doc_id' => 1, 'multireqtoken' => 'token'], null, 400, 0],
            ['POST', true, $valid, null, 404, 1],
            ['POST', true, $valid, ['authorized' => false, 'can_authorize' => false], 403, 1],
            ['POST', true, $valid, ['authorized' => true, 'can_authorize' => false], 200, 1],
        ];
    }
}

class DashboardControllerProbe extends Dashboard
{
    public function __construct(string $className = 'Dashboard', string $url = '')
    {
        $this->response = new Response();
    }

    public function result(): Response
    {
        return $this->response;
    }
}

class TokenProbe
{
    private $valid;

    public function __construct(bool $valid)
    {
        $this->valid = $valid;
    }

    public function validate($token): bool
    {
        return $this->valid;
    }

    public function tokenExist($token): bool
    {
        return false;
    }

    public function newToken(): string
    {
        return 'new-fixture-token';
    }
}

class DashboardServiceProbe extends DashboardService
{
    public $provider;

    public function __construct(?array $record)
    {
        $this->provider = new ElectronicProviderProbe($record);
    }

    public function electronic(): ElectronicDocuments
    {
        return $this->provider;
    }
}

class ElectronicProviderProbe extends ElectronicDocuments
{
    public $reads = 0;
    private $record;

    public function __construct(?array $record)
    {
        $this->record = $record;
    }

    public function find(string $type, int $id): ?array
    {
        $this->reads++;
        return $this->record;
    }
}
