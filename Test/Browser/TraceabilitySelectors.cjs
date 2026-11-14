// Prueba los selectores reales en Chromium, sin configuración ni base de datos.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const plugin = process.env.TRACEABILITY_SOURCE || path.join(root, 'Plugins/SpiderTraceability');
const markup = execFileSync('php', ['-r', `
define('FS_FOLDER', $argv[1]);
define('FS_ROUTE', '');
require $argv[1] . '/vendor/autoload.php';
require $argv[2] . '/Lib/Widget/WidgetSelectTraza.php';
use FacturaScripts\\Plugins\\SpiderTraceability\\Lib\\Widget\\WidgetSelectTraza;
foreach (['editable' => 'false', 'readonly' => 'true', 'missing-parent' => 'false'] as $name => $readonly) {
    $widget = new WidgetSelectTraza(['type' => 'selectTraza', 'fieldname' => 'numserie',
        'parent' => $name === 'missing-parent' ? 'missing' : 'idlinea',
        'readonly' => $readonly, 'children' => [['tag' => 'values']]]);
    echo '<form id="' . $name . '"><input name="activetab" value="EditFacturaClienteTrazabilidad">';
    echo '<select name="idlinea"><option value="linea-prueba">Línea</option></select>';
    echo $widget->edit((object)['numserie' => null]);
    echo '</form>';
}
`, root, plugin], {encoding: 'utf8'});
const jquery = fs.readFileSync(path.join(root, 'node_modules/jquery/dist/jquery.min.js'), 'utf8');
const normal = fs.readFileSync(path.join(root, 'Core/Assets/JS/WidgetSelect.js'), 'utf8');
const traceability = fs.readFileSync(path.join(plugin, 'Assets/JS/WidgetSelectTraza.js'), 'utf8');
const setup = `
window.requests = []; window.failures = [];
window.alert = message => failures.push(message);
window.addEventListener('error', event => failures.push(event.message));
$.fn.select2 = function () { return this; };
$.fn.form = function () { return this.closest('form'); };
$.ajax = function (options) {
    requests.push({...options.data});
    options.success([{key: 'lote-prueba', value: 'Lote de prueba'}]);
};
`;
const checks = `
$(function () {
    setTimeout(function () {
        const checks = [];
        function check(value, message) {
            if (!value) throw new Error(message);
            checks.push(message);
        }
        try {
            const ordinary = requests.filter(r => r.field === 'almacen');
            const lots = requests.filter(r => r.field === 'numserie');
            check(ordinary.length === 1 && ordinary[0].action === 'select'
                && ordinary[0].source === 'almacenes', 'El selector normal conserva su acción y origen');
            check(lots.length === 1 && lots[0].action === 'selectTraza'
                && lots[0].activetab === 'EditFacturaClienteTrazabilidad', 'El selector de lotes usa su acción una sola vez');
            check(!requests.some(r => r.action === 'select' && !r.source), 'No solicita tablas vacías');
            check(requests.length === 2, 'Los campos de solo lectura y sin padre no solicitan opciones');
            check($('#editable select[name="numserie"]').val() === 'lote-prueba', 'Conserva las opciones de trazabilidad');
            requests.length = 0;
            $('#ordinary select[name="empresa"]').trigger('change');
            check(requests.length === 1 && requests[0].action === 'select', 'Cambiar empresa no dispara trazabilidad');
            requests.length = 0;
            $('#editable select[name="idlinea"]').trigger('change');
            check(requests.length === 1 && requests[0].action === 'selectTraza', 'Cambiar línea produce una sola lectura de lotes');
            check(failures.length === 0, 'Sin errores JavaScript ni alertas');
            document.getElementById('result').textContent = JSON.stringify({ok: true, checks});
        } catch (error) {
            document.getElementById('result').textContent = JSON.stringify({ok: false,
                error: error.message, checks, requests, failures});
        }
    }, 100);
});
`;

const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'traceability-selectors-'));
try {
    for (const [label, scripts] of [['normal-first', [normal, traceability]], ['traceability-first', [traceability, normal]]]) {
        const fixture = path.join(folder, label + '.html');
        fs.writeFileSync(fixture, '<!doctype html><meta charset="utf-8">'
            + '<form id="ordinary"><input name="activetab" value="main">'
            + '<select name="empresa"><option value="empresa-prueba">Empresa</option></select>'
            + '<select class="parentSelect" name="almacen" parent="empresa" data-field="almacen"'
            + ' data-source="almacenes" data-fieldcode="codalmacen" data-fieldtitle="nombre"></select></form>'
            + markup + '<pre id="result">pending</pre><script>' + jquery + '</script>'
            + '<script>' + setup + '</script>' + scripts.map(s => '<script>' + s + '</script>').join('')
            + '<script>' + checks + '</script>');
        const output = execFileSync(process.env.CHROMIUM_BIN || 'chromium', [
            '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
            '--disable-background-networking', '--no-proxy-server', '--host-resolver-rules=MAP * ~NOTFOUND',
            '--user-data-dir=' + path.join(folder, label + '-profile'), '--virtual-time-budget=1500',
            '--dump-dom', 'file://' + fixture
        ], {encoding: 'utf8', maxBuffer: 2 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'], timeout: 20000});
        const match = output.match(/<pre id="result">([^<]+)<\/pre>/);
        assert.ok(match && match[1] !== 'pending', 'Chromium debe terminar la prueba');
        const result = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
        assert.equal(result.ok, true, label + ': ' + JSON.stringify(result));
        console.log(JSON.stringify({order: label, checks: result.checks.length, passed: true}));
    }
} finally {
    fs.rmSync(folder, {recursive: true, force: true});
}
