<?php

namespace FacturaScripts\Test\Core;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\CrashReport;
use FacturaScripts\Core\Error\AccessDenied;
use FacturaScripts\Core\Error\AlreadyInstalled;
use FacturaScripts\Core\Error\DefaultError;
use FacturaScripts\Core\Error\PageNotFound;
use FacturaScripts\Core\ErrorPage;
use FacturaScripts\Core\KernelException;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Core\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/** Pruebas aisladas: no cargan config.php ni acceden a la base de datos. */
final class ErrorPageTest extends TestCase
{
    private $server;
    private $cookies;
    private $user;

    protected function setUp(): void
    {
        if (!defined('FS_FOLDER')) {
            define('FS_FOLDER', dirname(__DIR__, 2));
        }
        if (!defined('FS_DEBUG')) {
            define('FS_DEBUG', true);
        }
        $this->server = $_SERVER;
        $this->cookies = $_COOKIE;
        $this->user = Session::get('user');
        Session::set('user', null);
        unset($_SERVER['CONTENT_TYPE']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookies;
        Session::set('user', $this->user);
    }

    public function testAnonymousCannotSeeTechnicalDataEvenWithForgedCookiesAndDebug(): void
    {
        $_COOKIE = ['fsNick' => 'admin', 'fsLogkey' => 'inventado', 'admin' => true];
        $this->assertFalse(ErrorPage::canShowDetails());
        $this->assertSafe(ErrorPage::render($this->info()));
    }

    public function testRegularUserReceivesSriExplanationWithoutHiddenTechnicalData(): void
    {
        Session::set('user', $this->makeUser(false));
        $html = ErrorPage::render($this->info());
        $this->assertStringContainsString('No pudimos conectar con el SRI', $html);
        $this->assertStringContainsString('revisa su estado', $html);
        $this->assertStringContainsString('ABCDEF123456', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('type="hidden"', $html);
        $this->assertStringNotContainsString('qrserver.com', $html);
        $this->assertSafe($html);
    }

    public function testAdministratorSeesEscapedDetailsInCollapsedSection(): void
    {
        Session::set('user', $this->makeUser(true));
        $html = ErrorPage::render($this->info());
        $this->assertTrue(ErrorPage::canShowDetails());
        $this->assertStringContainsString('<details class="technical">', $html);
        $this->assertStringNotContainsString('<details class="technical" open', $html);
        $this->assertStringContainsString('ElectronicDocController.php', $html);
        $this->assertStringContainsString('Stack trace:', $html);
        $this->assertStringContainsString('SoapFault', $html);
        $this->assertStringContainsString('&lt;script&gt;alert', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testDisabledOrUnauthenticatedAdministratorCannotSeeDetails(): void
    {
        $user = $this->makeUser(true);
        $user->enabled = false;
        Session::set('user', $user);
        $this->assertFalse(ErrorPage::canShowDetails());
        $user->enabled = true;
        $user->nick = '';
        $this->assertFalse(ErrorPage::canShowDetails());
        Session::set('user', (object)['nick' => 'admin', 'enabled' => true, 'admin' => true]);
        $this->assertFalse(ErrorPage::canShowDetails());
    }

    public function testUnknownExceptionUsesSafeFallback(): void
    {
        $info = $this->info();
        $info['message'] = 'SELECT private_password FROM secret_database /var/www/internal.php';
        $html = ErrorPage::render($info);
        $this->assertStringContainsString('Ocurrió un inconveniente al procesar tu solicitud.', $html);
        $this->assertStringNotContainsString('private_password', $html);
        $this->assertStringNotContainsString('secret_database', $html);
        $this->assertStringNotContainsString('internal.php', $html);
    }

    /** @dataProvider handlerProvider */
    public function testSpecificHandlersKeepUsefulExplanations(string $handler, string $expected): void
    {
        $info = $this->info();
        $info['message'] = '/private/internal.php: confidential';
        $public = ErrorPage::publicInfo($info, $handler);
        $this->assertStringContainsString($expected, $public['title'] . ' ' . $public['message']);
        $this->assertStringNotContainsString('confidential', json_encode($public));
    }

    public function handlerProvider(): array
    {
        return [
            ['DatabaseError', 'acceder a la información'],
            ['FileNotFound', 'No encontramos el archivo'],
            ['MyfilesTokenError', 'enlace válido'],
            ['UnsafeFile', 'No podemos abrir este recurso'],
            ['UnsafeFolder', 'No podemos abrir este recurso'],
            ['PageNotFound', 'No encontramos esta página'],
            ['AccessDenied', 'No tienes acceso a esta sección'],
            ['AlreadyInstalled', 'El sistema ya está instalado'],
        ];
    }

    /** @dataProvider pageHandlerProvider */
    public function testPageHandlersKeepStatusAndProtectDetails(
        string $handlerClass,
        string $handlerName,
        int $status,
        string $role,
        string $format
    ): void {
        if ($role !== 'anonymous') {
            Session::set('user', $this->makeUser($role === 'admin'));
        }
        $_SERVER['CONTENT_TYPE'] = $format . '; charset=UTF-8';
        $message = 'confidential /private/path.php SQLSTATE[secret] SOAP-ERROR sri.gob.ec/wsdl <script>alert(1)</script>';
        $handler = new $handlerClass(new KernelException($handlerName, $message));
        $expected = ErrorPage::publicInfo([], $handlerName);
        $previousStatus = http_response_code();
        ob_start();
        try {
            $handler->run();
            $response = (string)ob_get_contents();
            $this->assertSame($status, http_response_code());
        } finally {
            ob_end_clean();
            http_response_code($previousStatus ?: 200);
        }

        if ($format === 'text/html') {
            $this->assertStringContainsString($expected['title'], $response);
            $this->assertSame($role === 'admin', strpos($response, '<details class="technical">') !== false);
            $this->assertStringNotContainsString('<script>alert', $response);
        } elseif ($format === 'application/json') {
            $payload = json_decode($response, true);
            $this->assertSame($expected['message'], $payload['error']);
            $this->assertSame($role === 'admin', isset($payload['info']));
        } elseif ($role !== 'admin') {
            $this->assertStringContainsString($expected['message'], $response);
        }

        if ($role === 'admin') {
            $this->assertStringContainsString('confidential', $response);
        } else {
            $this->assertStringNotContainsString('confidential', rawurldecode($response));
            $this->assertStringNotContainsString('/private/path.php', rawurldecode($response));
            $this->assertSafe($response);
        }
    }

    public function pageHandlerProvider(): array
    {
        $cases = [];
        foreach ([
            [PageNotFound::class, 'PageNotFound', 404],
            [AccessDenied::class, 'AccessDenied', 403],
            [AlreadyInstalled::class, 'AlreadyInstalled', 403],
        ] as $handler) {
            foreach (['anonymous', 'user', 'admin'] as $role) {
                foreach (['text/html', 'application/json', 'text/plain'] as $format) {
                    $cases[] = array_merge($handler, [$role, $format]);
                }
            }
        }
        return $cases;
    }

    public function testLegacyAccessDeniedTemplateUsesCommonErrorHandler(): void
    {
        $controller = new class('ErrorPageTest') extends Controller {
            public function __construct(string $className, string $url = '')
            {
                $this->request = Request::create('/');
            }

            public function publicCore(&$response)
            {
                // Simula la selección de plantilla usada al denegar acceso a un registro.
                $this->setTemplate('Error/AccessDenied');
            }
        };

        try {
            $controller->run();
            $this->fail('La plantilla de acceso denegado debía activar el controlador común.');
        } catch (KernelException $exception) {
            $this->assertSame('AccessDenied', $exception->handler);
        }
    }

    public function testJsonResponseWithCharsetEnforcesPermissions(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=UTF-8';
        $response = ErrorPage::response($this->info());
        $this->assertSafe($response);
        $this->assertSame(['error', 'reference'], array_keys(json_decode($response, true)));

        Session::set('user', $this->makeUser(true));
        $response = json_decode(ErrorPage::response($this->info()), true);
        $this->assertSame($this->info(), $response['info']);
    }

    public function testPlainResponseEnforcesPermissions(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain; charset=UTF-8';
        $response = ErrorPage::response($this->info());
        $this->assertSafe($response);
        $this->assertStringContainsString("\nReferencia: ABCDEF123456", $response);
        Session::set('user', $this->makeUser(true));
        $this->assertSame($this->info()['message'], ErrorPage::response($this->info()));
    }

    public function testSupportUrlCannotBypassPermissions(): void
    {
        $url = CrashReport::generateFormattedMsg($this->info());
        $this->assertSafe(rawurldecode($url));
        $this->assertStringContainsString('ABCDEF123456', rawurldecode($url));
        Session::set('user', $this->makeUser(true));
        $this->assertStringContainsString('ElectronicDocController.php', rawurldecode(CrashReport::generateFormattedMsg($this->info())));
    }

    /** @dataProvider exceptionProvider */
    public function testNativeExceptionHandlerProtectsEveryExceptionType(string $type): void
    {
        $exception = new $type('confidential /private/path.php <script>alert(1)</script>');
        $handler = new class($exception) extends DefaultError {
            protected function setSaveCrash(bool $save): void
            {
                // El registro persistente se comprueba aparte, en un directorio temporal.
                parent::setSaveCrash(false);
            }
        };
        $status = http_response_code();
        ob_start();
        $handler->run();
        $html = ob_get_clean();
        $this->assertSame(500, http_response_code());
        http_response_code($status ?: 200);
        $this->assertStringNotContainsString('confidential', rawurldecode($html));
        $this->assertStringNotContainsString('/private/path.php', rawurldecode($html));
        $this->assertStringNotContainsString('Stack trace:', rawurldecode($html));
        $this->assertStringContainsString('SpiderCode', $html);
    }

    public function exceptionProvider(): array
    {
        return [[Exception::class], [SyntaxError::class], [RuntimeError::class], [LoaderError::class]];
    }

    private function makeUser(bool $admin): User
    {
        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->nick = 'prueba';
        $user->enabled = true;
        $user->admin = $admin;
        return $user;
    }

    private function assertSafe(string $response): void
    {
        foreach (['SoapFault', 'SOAP-ERROR', 'WSDL', 'sri.gob.ec', '/var/www', 'ElectronicDocController.php',
            'Stack trace:', 'secret_method', 'secret_fragment', 'SecretPlugin', 'secret-route', 'alert(',
            'report_qr', 'error_message', 'error_file'] as $secret) {
            $this->assertStringNotContainsString($secret, rawurldecode($response));
        }
    }

    private function info(): array
    {
        return [
            'code' => 0,
            'message' => 'SOAP-ERROR: Parsing WSDL: Could not load https://cel.sri.gob.ec/wsdl '
                . '&lt;script&gt;alert(1)&lt;/script&gt;' . "\nStack trace:\nsecret_method()",
            'exception' => 'SoapFault',
            'file' => '/var/www/Plugins/SpiderFact/Controller/ElectronicDocController.php',
            'line' => 221,
            'fragment' => 'secret_fragment',
            'hash' => 'abcdef1234567890abcdef1234567890',
            'url' => '/secret-route',
            'plugin_list' => 'SecretPlugin',
            'core_version' => '2024.92',
            'php_version' => '7.4.33',
            'os' => 'Linux',
        ];
    }
}
