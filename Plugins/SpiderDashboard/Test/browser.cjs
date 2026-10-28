// Prueba el Twig y los assets reales con datos sintéticos; no inicia el ERP ni accede al SRI.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');

async function main() {
    const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'render-dashboard.php')], {encoding: 'utf8', maxBuffer: 5 * 1024 * 1024});
    const server = http.createServer((request, response) => {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(html);
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'spider-dashboard-browser-'));
    const chrome = spawn(process.env.CHROMIUM_BIN || 'chromium', ['--headless', '--no-sandbox', '--disable-gpu',
        '--disable-dev-shm-usage', '--disable-background-networking', '--no-proxy-server',
        '--remote-debugging-port=0', '--user-data-dir=' + folder], {stdio: ['ignore', 'ignore', 'pipe']});
    let socket;
    try {
        const endpoint = await new Promise((resolve, reject) => {
            let buffer = '';
            const timer = setTimeout(() => reject(new Error('Chromium no inició.')), 15000);
            chrome.stderr.on('data', chunk => {
                buffer += String(chunk);
                const match = buffer.match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) {
                    clearTimeout(timer);
                    resolve(match[1]);
                }
            });
            chrome.on('error', reject);
        });
        socket = new WebSocket(endpoint);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve, {once: true});
            socket.addEventListener('error', reject, {once: true});
        });
        const waiting = new Map();
        let id = 0;
        socket.addEventListener('message', event => {
            const data = JSON.parse(event.data);
            const entry = waiting.get(data.id);
            if (entry) {
                waiting.delete(data.id);
                clearTimeout(entry.timer);
                if (data.error) {
                    entry.reject(new Error(JSON.stringify(data.error)));
                } else {
                    entry.resolve(data.result);
                }
            }
        });
        function call(method, params = {}, sessionId) {
            return new Promise((resolve, reject) => {
                const current = ++id;
                const timer = setTimeout(() => reject(new Error('Tiempo de espera: ' + method)), 15000);
                waiting.set(current, {resolve, reject, timer});
                socket.send(JSON.stringify({id: current, method, params, sessionId}));
            });
        }
        const target = await call('Target.createTarget', {url: 'about:blank'});
        const attached = await call('Target.attachToTarget', {targetId: target.targetId, flatten: true});
        const session = attached.sessionId;
        const page = (method, params) => call(method, params, session);
        await page('Page.enable');
        await page('Emulation.setDeviceMetricsOverride', {width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false});
        await page('Page.navigate', {url: 'http://127.0.0.1:' + server.address().port + '/Dashboard'});
        const ready = await page('Runtime.evaluate', {expression: `new Promise((resolve,reject)=>{const timer=setInterval(()=>{if(document.querySelector('.dash-kpi-value')){clearInterval(timer);resolve(true)}},10);setTimeout(()=>reject(new Error('El panel no inició')),5000)})`, awaitPromise: true, returnByValue: true});
        assert.equal(ready.result.value, true, JSON.stringify(ready));
        const result = await page('Runtime.evaluate', {awaitPromise: true, returnByValue: true, expression: `(async()=>{
            const checks=[];const errors=[];
            window.addEventListener('error',event=>errors.push(event.message));
            window.addEventListener('unhandledrejection',event=>errors.push(String(event.reason)));
            const tick=()=>new Promise(resolve=>setTimeout(resolve,35));
            const check=(ok,label)=>{if(!ok)throw new Error(label);checks.push(label)};
            const content=()=>document.getElementById('dash-content');
            const tab=async(section)=>{document.querySelector('.dash-tab[data-section="'+section+'"]').click();await tick()};
            check(document.querySelectorAll('.dash-kpi').length===4,'Resumen con cuatro indicadores principales');
            check(document.getElementById('dash-sales-chart').width>0,'Gráfico real de Chart.js');
            for(const [section,label] of Object.entries({sales:'Ventas ajustadas',receivables:'Facturas por cobrar',inventory:'Inventario por revisar',cash:'Histórico de cierres',ebilling:'Comprobantes por autorizar'})){
                await tab(section);check(content().textContent.includes(label),'Pestaña '+section+' carga su detalle');
            }
            await tab('receivables');
            check(!window.dashboardXss&&!content().querySelector('img'),'Los nombres se escapan y no ejecutan HTML');
            document.querySelector('[data-page="2"]').click();await tick();
            check(new URL(window.dashboardRequests.at(-1).url).searchParams.get('page')==='2','Paginación conserva el contexto');
            document.getElementById('dash-search').value='Cliente & Otro';
            document.querySelector('[data-search]').click();await tick();
            check(new URL(window.dashboardRequests.at(-1).url).searchParams.get('q')==='Cliente & Otro','Búsqueda enviada sin alterar caracteres');
            document.querySelectorAll('[name="warehouses[]"]').forEach(input=>{input.checked=true});
            document.getElementById('dash-filter-form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await tick();
            check(new URL(window.dashboardRequests.at(-1).url).searchParams.getAll('warehouses[]').length===2,'Selección simultánea de dos almacenes');
            await tab('inventory');
            check(new URL(window.dashboardRequests.at(-1).url).searchParams.get('q')==='','Cambiar de pestaña limpia filtros particulares');
            const select=content().querySelector('[data-filter="stock_state"]');select.value='below';select.dispatchEvent(new Event('change',{bubbles:true}));await tick();
            check(new URL(window.dashboardRequests.at(-1).url).searchParams.get('stock_state')==='below','Filtro de alertas de stock');
            await tab('ebilling');
            const button=document.querySelector('[data-authorize]');button.click();button.click();await new Promise(resolve=>setTimeout(resolve,100));
            const posts=window.dashboardRequests.filter(request=>request.body);
            check(posts.length===1,'Un doble clic genera una sola solicitud');
            const body=new URLSearchParams(posts[0].body);
            check(body.get('doc_type')==='FacturaCliente'&&body.get('doc_id')==='1'&&!!body.get('multireqtoken'),'POST individual con tipo, identificador y token');
            check(!document.querySelector('[data-authorize]')&&document.getElementById('dash-message').textContent.includes('correctamente'),'Autorización actualiza la lista y muestra resultado');
            await tab('summary');
            document.documentElement.dataset.theme='dark';await tick();
            check(getComputedStyle(document.querySelector('.dash-kpi')).backgroundColor!=='rgb(255, 255, 255)','El tema oscuro cambia las tarjetas');
            check(!errors.length,'Sin errores JavaScript: '+errors.join(','));
            return checks;
        })()`});
        assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
        const checks = result.result.value;
        for (const label of checks) console.log('PASS: ' + label);
        for (const theme of ['light', 'dark']) {
            await page('Runtime.evaluate', {expression: 'document.documentElement.dataset.theme=' + JSON.stringify(theme)});
            await new Promise(resolve => setTimeout(resolve, 400));
            const screenshot = await page('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
            fs.writeFileSync(path.join(os.tmpdir(), 'spider-dashboard-' + theme + '.png'), Buffer.from(screenshot.data, 'base64'));
        }
        await page('Emulation.setDeviceMetricsOverride', {width: 390, height: 1000, deviceScaleFactor: 1, mobile: true});
        await new Promise(resolve => setTimeout(resolve, 400));
        const mobile = await page('Runtime.evaluate', {returnByValue: true, expression: '({width:innerWidth,scroll:document.documentElement.scrollWidth,kpis:document.querySelectorAll(".dash-kpi").length,dateWidth:document.getElementById("dash-from").getBoundingClientRect().width})'});
        assert.ok(mobile.result.value.scroll <= 391, JSON.stringify(mobile.result.value));
        assert.equal(mobile.result.value.kpis, 4);
        assert.ok(mobile.result.value.dateWidth >= 130, JSON.stringify(mobile.result.value));
        console.log('PASS: Móvil de 390 px sin desbordamiento de la página');
        const screenshot = await page('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
        fs.writeFileSync(path.join(os.tmpdir(), 'spider-dashboard-mobile.png'), Buffer.from(screenshot.data, 'base64'));
        console.log((checks.length + 1) + ' comprobaciones de navegador correctas');
    } finally {
        if (socket) socket.close();
        chrome.kill('SIGTERM');
        await once(chrome, 'exit').catch(() => {});
        server.close();
        fs.rmSync(folder, {recursive: true, force: true});
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
