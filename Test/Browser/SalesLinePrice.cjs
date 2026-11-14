// Ejecutar con Node, PHP y Chromium, sin configuración ni base de datos de la instancia.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const jquery = fs.readFileSync(path.join(root, 'node_modules/jquery/dist/jquery.min.js'), 'utf8');
const source = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = file_get_contents($argv[1] . '/Core/View/Tab/SalesDocument.html.twig');
preg_match('/<script>([\\s\\S]*?)<\\/script>/', $source, $match);
$twig = new Twig\\Environment(new Twig\\Loader\\ArrayLoader(['script' => $match[1]]));
function translatePriceTest($key) { return $key; }
$twig->addFunction(new Twig\\TwigFunction('trans', 'translatePriceTest'));
echo $twig->render('script', [
    'model' => (object)['editable' => true],
    'fsc' => new class { public function url() { return 'EditFacturaCliente'; } },
]);
`, root], {encoding: 'utf8'});

const tests = `
const checks = [];
const failures = [];
const prompts = [];
const requests = [];
const alerts = [];
let answer = '115';
window.addEventListener('error', event => failures.push(event.message));
window.prompt = message => { prompts.push(message); return answer; };
window.alert = message => alerts.push(message);
window.salesFormAction = (...args) => requests.push(args);
function check(condition, label) {
    if (!condition) throw new Error(label);
    checks.push(label);
}
function form(id, state) {
    const node = document.forms.salesForm;
    node.innerHTML = '<input name="codcliente" value="CUSTOMER">';
    for (const [name, value] of Object.entries({
        cantidad: 2, dtopor: 0, dtopor2: 0, iva: 15, recargo: 0, irpf: 0
    })) {
        const field = document.createElement('input');
        field.name = name + '_' + id;
        field.value = value;
        node.appendChild(field);
    }
    const price = document.createElement('input');
    price.type = 'number';
    price.value = 10;
    if (state !== 'missing') {
        if (state !== 'unnamed-disabled') price.name = 'pvpunitario_' + id;
        price.disabled = state.includes('disabled');
        price.readOnly = state === 'readonly';
        node.appendChild(price);
    }
    for (const [name, handler] of [
        ['linetotal', 'salesLineTotalWithTaxes'], ['lineneto', 'salesLineTotalWithoutTaxes']
    ]) {
        const field = document.createElement('input');
        field.name = name + '_' + id;
        field.readOnly = true;
        field.onclick = () => window[handler](id);
        node.appendChild(field);
    }
    prompts.length = requests.length = alerts.length = 0;
    return price;
}
try {
    for (const id of ['42', 'n1']) {
        for (const state of ['unnamed-disabled', 'named-disabled', 'readonly', 'missing']) {
            const price = form(id, state);
            document.forms.salesForm['linetotal_' + id].click();
            document.forms.salesForm['lineneto_' + id].click();
            salesLineTotalWithTaxes(id);
            salesLineTotalWithoutTaxes(id);
            check(prompts.length === 0 && requests.length === 0 && price.value === '10',
                id + '/' + state + ': Subtotal, Neto y llamadas directas no abren el diálogo ni cambian el precio');
        }
    }

    for (const [name, handler, total] of [
        ['linetotal', 'salesLineTotalWithTaxes', '115'],
        ['lineneto', 'salesLineTotalWithoutTaxes', '100']
    ]) {
        let price = form('42', 'editable');
        answer = total;
        document.forms.salesForm[name + '_42'].click();
        check(prompts.length === 1 && price.value === '50'
            && JSON.stringify(requests) === JSON.stringify([['recalculate', '0']]),
            name + ': permite recalcular el precio cuando es editable');

        price = form('42', 'editable');
        Object.assign(document.forms.salesForm.dtopor_42, {value: 10});
        Object.assign(document.forms.salesForm.dtopor2_42, {value: 5});
        Object.assign(document.forms.salesForm.recargo_42, {value: 5});
        Object.assign(document.forms.salesForm.irpf_42, {value: 2});
        answer = name === 'linetotal' ? '100,89' : '85,5';
        window[handler]('42');
        check(price.value === '50' && requests.length === 1,
            name + ': conserva impuestos, descuentos y separador decimal');

        price = form('42', 'editable');
        answer = null;
        window[handler]('42');
        check(price.value === '10' && requests.length === 0 && alerts.length === 0,
            name + ': cancelar no cambia el precio ni genera errores');

        for (const invalid of ['', '0', '-1', 'abc']) {
            price = form('42', 'editable');
            answer = invalid;
            window[handler]('42');
            check(price.value === '10' && requests.length === 0 && alerts.length === 1,
                name + ': rechaza importe inválido ' + JSON.stringify(invalid));
        }
    }

    form('42', 'editable');
    const price = form('42', 'unnamed-disabled');
    document.forms.salesForm.linetotal_42.click();
    check(prompts.length === 0 && requests.length === 0 && price.value === '10',
        'Vuelve a comprobar la restricción tras reemplazar las líneas por AJAX');
    check(failures.length === 0, 'Sin errores JavaScript');
    document.getElementById('result').textContent = JSON.stringify({ok: true, checks});
} catch (error) {
    document.getElementById('result').textContent = JSON.stringify({ok: false, error: error.message, checks});
}
`;

const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'sales-line-price-test-'));
try {
    const fixture = path.join(folder, 'index.html');
    fs.writeFileSync(fixture, '<!doctype html><meta charset="utf-8"><form name="salesForm"></form>'
        + '<pre id="result">pending</pre><script>' + jquery + '</script>'
        + '<script>$.fn.autocomplete = function () { return this; };</script>'
        + '<script>' + source + '</script><script>' + tests + '</script>');
    const output = execFileSync(process.env.CHROMIUM_BIN || 'chromium', [
        '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--disable-background-networking', '--no-proxy-server', '--host-resolver-rules=MAP * ~NOTFOUND',
        '--user-data-dir=' + path.join(folder, 'profile'), '--virtual-time-budget=1000',
        '--dump-dom', 'file://' + fixture
    ], {encoding: 'utf8', maxBuffer: 2 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'], timeout: 20000});
    const match = output.match(/<pre id="result">([^<]+)<\/pre>/);
    assert.ok(match && match[1] !== 'pending', 'Chromium debe completar la prueba');
    const result = JSON.parse(match[1]);
    assert.equal(result.ok, true, JSON.stringify(result));
    for (const message of result.checks) console.log('PASS: ' + message);
    console.log(result.checks.length + ' comprobaciones correctas');
} finally {
    fs.rmSync(folder, {recursive: true, force: true});
}
