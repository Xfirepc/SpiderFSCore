<?php

// Vista de prueba con datos sintéticos: no carga configuración ni conecta a la instancia.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$root = dirname(__DIR__, 3);
$filters = \FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardFilters::normalize([], '2026-10-27');
$total = ['billed' => 18475.25, 'net' => 16065.43, 'invoices' => 82, 'credits' => 210, 'debits' => 35, 'adjusted' => 18300.25, 'ticket' => 225.31];
$rows = [];
foreach (['Distribuidora del Pacífico', 'Comercial Andina', 'María Fernanda Pérez', 'Almacenes Central', 'Carlos Mendoza'] as $index => $name) {
    $rows[] = ['name' => $name, 'amount' => 4850 - $index * 800, 'units' => 96 - $index * 13,
        'codcliente' => 'C' . $index, 'referencia' => 'PROD-00' . $index, 'url' => 'EditCliente?code=C' . $index];
}
$products = $rows;
foreach (['Café de especialidad 500 g', 'Aceite de oliva premium', 'Chocolate artesanal 70 %', 'Granola de frutos secos', 'Miel de abeja 350 ml'] as $index => $name) {
    $products[$index]['name'] = $name;
}
$list = static function (array $items, int $count = 0): array {
    return ['rows' => $items, 'count' => $count ?: count($items), 'page' => 1, 'pages' => $count > 25 ? (int)ceil($count / 25) : 1];
};
$all = [
    'sales' => ['today' => array_merge($total, ['billed' => 1248.50, 'invoices' => 14]), 'month' => $total,
        'range' => $total, 'previous' => $total, 'previous_dates' => ['from' => '2026-09-04', 'to' => '2026-09-30'],
        'change' => 12.8, 'customers' => $rows, 'products' => $products, 'consumer' => 820.30,
        'series' => ['labels' => ['2026-10-01', '2026-10-04', '2026-10-07', '2026-10-10', '2026-10-13', '2026-10-16', '2026-10-19', '2026-10-22', '2026-10-25', '2026-10-27'],
            'values' => [450, 720, 510, 940, 820, 1150, 680, 1230, 980, 1248.5], 'daily' => true]],
    'receivables' => ['balance' => 6320.50, 'overdue' => 1840.25, 'count' => 18,
        'aging' => ['current' => 4000.25, 'days30' => 1300, 'days60' => 400.25, 'days90' => 140, 'older' => 0, 'undated' => 480],
        'debtors' => [['name' => 'Distribuidora del Pacífico', 'amount' => 2500, 'overdue' => 700, 'invoices' => 4]],
        'list' => $list([['url' => 'EditFacturaCliente?code=1', 'numero2' => '001-001-000001230', 'codigo' => 'F1',
            'fecha' => '2026-09-10', 'nombrecliente' => 'Cliente <img src=x onerror="window.dashboardXss=true">', 'codcliente' => 'C1',
            'total' => 1200, 'paid' => 500, 'balance' => 700, 'due' => '2026-10-01', 'days' => 26]], 28)],
    'inventory' => ['counts' => ['out_count' => 2, 'below_count' => 4, 'low_count' => 6, 'high_count' => 3, 'above_count' => 1, 'alerts_count' => 14],
        'labels' => \FacturaScripts\Plugins\SpiderDashboard\Lib\InventoryMetrics::LABELS,
        'list' => $list([['idproducto' => 1, 'referencia' => 'CAFE-500', 'name' => 'Café de especialidad 500 g', 'warehouse' => 'Almacén principal',
            'codalmacen' => 'A', 'cantidad' => 12, 'disponible' => 9, 'reservada' => 3, 'pterecibir' => 20, 'stockmin' => 10,
            'stockmax' => 100, 'percent' => 12, 'alerts' => ['below'], 'url' => 'EditProducto?code=1']], 28)],
    'cash' => ['open' => $list([['terminal' => 'Caja principal', 'codalmacen' => 'A', 'coddivisa' => 'USD', 'nick' => 'María',
        'fechaini' => '2026-10-27 08:03:00', 'dineroini' => 100, 'totalcaja' => 750.25, 'numtickets' => 14, 'url' => 'EditTpvCaja?code=2']]),
        'last' => [['terminal' => 'Caja principal', 'codalmacen' => 'A', 'coddivisa' => 'USD', 'nick' => 'María', 'fechaini' => '2026-10-26 08:00:00',
            'fechafin' => '2026-10-26 18:30:00', 'totalcaja' => 986.50, 'dinerofin' => 986.50, 'diferencia' => 0, 'url' => 'EditTpvCaja?code=1']],
        'history' => $list([])],
    'ebilling' => ['count' => 1, 'states' => [['status' => 'PENDIENTE', 'count' => 1]],
        'types' => ['FacturaCliente' => 'Factura', 'NotaCreditoCliente' => 'Nota de crédito'],
        'list' => $list([['doc_id' => 1, 'doc_type' => 'FacturaCliente', 'number' => '001-001-000001231', 'url' => 'EditFacturaCliente?code=1',
            'label' => 'Factura', 'codserie' => 'E', 'fecha' => '2026-10-27', 'codalmacen' => 'A', 'subject' => 'Comercial Andina',
            'env' => '2', 'status' => 'PENDIENTE', 'message' => 'Pendiente de autorización.', 'can_authorize' => true]])],
];
$css = file_get_contents($root . '/node_modules/bootstrap/dist/css/bootstrap.min.css')
    . file_get_contents($root . '/node_modules/@fortawesome/fontawesome-free/css/all.min.css')
    . '@font-face{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:900;src:url(data:font/woff2;base64,'
    . base64_encode(file_get_contents($root . '/node_modules/@fortawesome/fontawesome-free/webfonts/fa-solid-900.woff2')) . ') format("woff2")}'
    . file_get_contents($root . '/Plugins/SpiderThemeClassic/Assets/Theme/css/theme-variables.css')
    . file_get_contents(dirname(__DIR__) . '/Assets/CSS/SpiderDashboard.css')
    . 'body{background:var(--theme-bg-body);font-family:system-ui,sans-serif;margin:0}.fixture-nav{padding:15px 40px;background:#05142d;color:#fff;font-size:14px}';
$fixture = '<!doctype html><html lang="es" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>' . $css . '</style>';
$fixture .= '<script>window.dashboardFixture=' . json_encode($all, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';window.dashboardRequests=[];window.fetch=async function(url,options){var parsed=new URL(url,location.href);window.dashboardRequests.push({url:String(url),body:options&&options.body?String(options.body):""});if(parsed.searchParams.get("action")==="authorize-document"){await new Promise(resolve=>setTimeout(resolve,20));window.dashboardFixture.ebilling.count=0;window.dashboardFixture.ebilling.list.rows=[];window.dashboardFixture.ebilling.list.count=0;return {ok:true,json:async()=>({ok:true,message:"Comprobante autorizado correctamente.",token:"renewed-token"})};}var section=parsed.searchParams.get("section");var data=section==="summary"?window.dashboardFixture:{[section]:window.dashboardFixture[section]};return {ok:true,json:async()=>({ok:true,data:data,updated:"12:34:56",token:"next-token"})};};</script>';
$fixture .= '<script>' . file_get_contents($root . '/node_modules/chart.js/dist/Chart.min.js') . '</script><script>'
    . file_get_contents(dirname(__DIR__) . '/Assets/JS/SpiderDashboard.js') . '</script></head><body><div class="fixture-nav">SpiderCode <span style="opacity:.5;margin-left:20px">Mi empresa / Informes</span></div>{% block body %}{% endblock %}<pre id="browser-test-result" hidden>pending</pre></body></html>';
$loader = new ChainLoader([new ArrayLoader(['Master/MenuBghTemplate.html.twig' => $fixture]), new FilesystemLoader(dirname(__DIR__) . '/View')]);
$twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
function dashboardPreviewAsset($path): string
{
    return '/' . ltrim($path, '/');
}
$twig->addFunction(new TwigFunction('asset', 'dashboardPreviewAsset'));
$controller = new class {
    public $filters;
    public $sections;
    public $warehouses;
    public $initialData;
    public $dashboardError = '';
    public $dashboardToday = '2026-10-27';
    public $empresa;
    public $user;
    public $multiRequestProtection;

    public function url(): string
    {
        return 'Dashboard';
    }
};
$controller->filters = $filters;
$controller->sections = ['summary' => 'Resumen', 'sales' => 'Ventas', 'receivables' => 'Cartera', 'inventory' => 'Inventario', 'cash' => 'Caja', 'ebilling' => 'Facturación electrónica'];
$controller->warehouses = [['codalmacen' => 'A', 'nombre' => 'Almacén principal', 'activo' => true], ['codalmacen' => 'B', 'nombre' => 'Sucursal norte', 'activo' => true]];
$controller->initialData = $all;
$controller->empresa = (object)['nombrecorto' => 'Mi empresa'];
$controller->user = (object)['homepage' => 'Dashboard'];
$controller->multiRequestProtection = new class {
    public function newToken(): string
    {
        return 'fixture-token';
    }
};
echo $twig->render('Dashboard.html.twig', ['fsc' => $controller]);
