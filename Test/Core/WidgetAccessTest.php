<?php

namespace FacturaScripts\Test\Core;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/** Comprueba las plantillas reales sin sesiones, base de datos ni solicitudes externas. */
final class WidgetAccessTest extends TestCase
{
    /** @dataProvider users */
    public function testOnlyAuthorizedWidgetsAreRendered($user, array $expected): void
    {
        $root = dirname(__DIR__, 2);
        $templates = [
            'notices' => file_get_contents($root . '/Plugins/SpiderBuilder/Extension/View/MenuTemplate_BodyEnd_20.html.twig'),
            'payment' => file_get_contents($root . '/Plugins/SpiderBuilder/Extension/View/MenuTemplate_BodyEnd.html.twig'),
            'agent' => file_get_contents($root . '/Plugins/SpiderAgent/View/Master/Widgets.html.twig'),
        ];
        $twig = new Environment(new ArrayLoader($templates), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', static function (string $path): string {
            return '/' . $path;
        }));
        $twig->addFunction(new TwigFunction('constant', static function (string $name): string {
            self::assertSame('FS_DB_NAME', $name);
            return 'widget-access-isolated-test';
        }));
        $markers = ['notices' => 'sb-notices.js', 'payment' => 'sb-payment-notices.js', 'agent' => 'chat.js'];
        foreach ($markers as $template => $marker) {
            $html = $twig->render($template, ['fsc' => (object)['user' => $user]]);
            if (in_array($template, $expected, true)) {
                $this->assertStringContainsString($marker, $html);
            } else {
                $this->assertStringNotContainsString($marker, $html);
            }
        }
    }

    public function users(): array
    {
        return [
            'anónimo' => [null, []],
            'administrador sin permisos de endpoints' => [new WidgetAccessUser(true, []), []],
            'usuario con avisos y agente' => [new WidgetAccessUser(false, ['SBNotices', 'SBPaymentNotices', 'SAgentManager']), ['notices', 'agent']],
            'administrador con todos los permisos' => [new WidgetAccessUser(true, ['SBNotices', 'SBPaymentNotices', 'SAgentManager']), ['notices', 'payment', 'agent']],
            'administrador solo con avisos' => [new WidgetAccessUser(true, ['SBNotices']), ['notices']],
        ];
    }
}

final class WidgetAccessUser
{
    public $admin;
    public $nick = 'widget-test';
    private $allowed;

    public function __construct(bool $admin, array $allowed)
    {
        $this->admin = $admin;
        $this->allowed = $allowed;
    }

    public function can(string $page): bool
    {
        return in_array($page, $this->allowed, true);
    }
}
