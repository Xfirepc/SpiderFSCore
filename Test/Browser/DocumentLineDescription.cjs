// Ejecutar con Node y Chromium, sin iniciar PHP ni conectar a la base de datos.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const jquery = read('node_modules/jquery/dist/jquery.min.js');
const source = read('Core/Assets/JS/DocumentLineDescription.js');
const css = [
    'Plugins/SpiderThemeClassic/Assets/Theme/css/theme-variables.css',
    'Plugins/SpiderThemeClassic/Assets/Theme/css/vertical-layout-light/style.css',
    'Core/Assets/CSS/custom.css',
    'Plugins/SpiderThemeClassic/Assets/Theme/css/fixes.css'
].map(read).join('\n');

const tests = `
const checks = [];
const failures = [];
window.addEventListener('error', event => failures.push(event.message));
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
function check(condition, label) {
    if (!condition) throw new Error(label);
    checks.push(label);
}
function addLine(form) {
    const field = document.createElement('textarea');
    field.className = 'form-control form-control-sm border-0 doc-line-desc';
    field.name = 'descripcion_1';
    field.rows = 1;
    form.appendChild(field);
    return field;
}
function input(field, value, inputType = 'insertText') {
    field.value = value;
    field.setSelectionRange(value.length, value.length);
    field.dispatchEvent(new InputEvent('input', {bubbles: true, inputType}));
}
function fits(field) {
    return field.scrollHeight <= field.clientHeight + 1;
}
async function run() {
    // Los formularios y sus líneas se crean después de registrar el manejador.
    for (const theme of ['light', 'dark']) {
        document.documentElement.dataset.theme = theme;
        for (const name of ['salesForm', 'purchasesForm']) {
            const label = theme + '/' + name;
            const form = document.createElement('form');
            form.name = name;
            form.style.width = '400px';
            document.body.appendChild(form);
            let field = addLine(form);
            field.focus();
            field.click();
            await tick();
            check(document.activeElement === field && field.value === '', label + ': foco y clic en línea vacía');
            if (theme === 'dark') {
                const properties = getComputedStyle(field).transitionProperty.split(',').map(value => value.trim());
                check(!properties.includes('all') && !properties.includes('height'), label + ': el tema no anima la altura');
            }

            input(field, 'Servicio\\nDescripción adicional');
            check(fits(field), label + ': escritura multilínea');
            input(field, field.value + '\\nOtro detalle');
            check(fits(field), label + ': ampliación sucesiva de la descripción');
            const longText = 'Descripción larga del servicio '.repeat(20);
            input(field, longText, 'insertFromPaste');
            check(field.clientHeight > 100 && $(field).height() <= 200, label + ': pegado sin keyup y altura limitada');
            check(field.value === longText && field.selectionStart === longText.length,
                label + ': conserva texto y posición del cursor');
            input(field, 'Detalle\\n'.repeat(100), 'insertFromPaste');
            check($(field).height() <= 200 && !fits(field), label + ': texto extenso disponible mediante scroll');
            check(getComputedStyle(field).overflowY !== 'hidden', label + ': el contenido excedente no queda oculto');

            form.replaceChildren();
            field = addLine(form);
            input(field, 'Nueva línea\\nTras reemplazar el formulario');
            check(fits(field), label + ': funciona tras reemplazo de líneas por AJAX');
            field.readOnly = true;
            const previousHeight = field.style.height;
            input(field, longText);
            check(field.style.height === previousHeight, label + ': respeta campos de solo lectura');

            // Reproduce la condición peligrosa aunque otro tema vuelva a animar la altura.
            field = addLine(form);
            field.style.height = '37px';
            field.style.transition = 'all 0.3s ease';
            field.getBoundingClientRect();
            input(field, 'Uno\\nDos\\nTres');
            await tick();
            check(field.value === 'Uno\\nDos\\nTres', label + ': responde aun con transición de altura');

            field = addLine(form);
            field.style.maxHeight = '40px';
            input(field, longText);
            await tick();
            check(field.scrollHeight > field.clientHeight, label + ': responde aun con max-height restrictivo');
            form.remove();
        }
    }
    const unrelated = addLine(document.body);
    input(unrelated, 'Fuera de un documento\\nOtra línea');
    check(unrelated.style.height === '', 'No modifica descripciones de otros formularios');
    check(failures.length === 0, 'Sin errores JavaScript');
    return {ok: true, checks};
}
run().then(result => {
    document.getElementById('result').textContent = JSON.stringify(result);
}).catch(error => {
    document.getElementById('result').textContent = JSON.stringify({ok: false, error: error.message, checks});
});
`;

const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'document-description-test-'));
try {
    const html = '<!doctype html><html><head><meta charset="utf-8"><style>' + css
        + '</style></head><body><pre id="result">pending</pre><script>' + jquery
        + '</script><script>' + source + '</script><script>' + tests + '</script></body></html>';
    const fixture = path.join(folder, 'index.html');
    fs.writeFileSync(fixture, html);
    const output = execFileSync(process.env.CHROMIUM_BIN || 'chromium', [
        '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--disable-background-networking', '--no-proxy-server', '--host-resolver-rules=MAP * ~NOTFOUND',
        '--user-data-dir=' + path.join(folder, 'profile'), '--virtual-time-budget=5000',
        '--dump-dom', 'file://' + fixture
    ], {encoding: 'utf8', maxBuffer: 5 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'], timeout: 20000});
    const match = output.match(/<pre id="result">([^<]+)<\/pre>/);
    assert.ok(match && match[1] !== 'pending', 'Chromium debe completar la prueba');
    const result = JSON.parse(match[1]);
    assert.equal(result.ok, true, JSON.stringify(result));
    for (const message of result.checks) console.log('PASS: ' + message);
    console.log(result.checks.length + ' comprobaciones correctas');
} finally {
    fs.rmSync(folder, {recursive: true, force: true});
}
