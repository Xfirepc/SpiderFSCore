/* Panel de negocio. Las consultas y acciones conservan siempre el contexto del usuario. */
(function () {
    'use strict';

    function start() {
        const root = document.getElementById('spider-dashboard');
        const initial = document.getElementById('dash-initial-data');
        if (!root || !initial) {
            return;
        }
        const state = JSON.parse(initial.textContent);
        const content = document.getElementById('dash-content');
        const form = document.getElementById('dash-filter-form');
        const message = document.getElementById('dash-message');
        const endpoint = new URL(root.dataset.endpoint, window.location.href);
        const charts = [];
        let pending;
        let requestNumber = 0;
        let authorizing = false;
        const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
        const number = value => new Intl.NumberFormat('es-EC', {maximumFractionDigits: 2}).format(Number(value) || 0);
        const money = (value, currency = 'USD') => new Intl.NumberFormat('es-EC', {style: 'currency', currency: currency || 'USD'}).format(Number(value) || 0);
        const date = value => value ? String(value).slice(0, 10).split('-').reverse().join('/') : 'Sin fecha';
        const time = value => value ? date(value) + ' · ' + String(value).slice(11, 16) : '—';
        const badge = (label, tone = '') => `<span class="dash-badge ${tone ? 'dash-badge-' + tone : ''}">${escape(label)}</span>`;
        const link = (url, label) => url ? `<a href="${escape(url)}">${escape(label)}</a>` : escape(label);
        const empty = label => `<div class="dash-empty"><i class="fas fa-chart-pie" aria-hidden="true"></i>${escape(label)}</div>`;
        const tabLink = (section, label = 'Ver detalle') => `<button type="button" class="dash-text-link" data-section="${section}">${escape(label)} <i class="fas fa-arrow-right" aria-hidden="true"></i></button>`;

        function panel(title, subtitle, body, action = '') {
            return `<section class="dash-panel"><div class="dash-panel-head"><div><h2>${escape(title)}</h2>${subtitle ? `<p>${escape(subtitle)}</p>` : ''}</div>${action}</div>${body}</section>`;
        }

        function metric(label, value, note, icon, section, tone = '') {
            return `<button type="button" class="dash-kpi ${tone ? 'dash-tone-' + tone : ''}" data-section="${section}"><span class="dash-kpi-top"><span>${escape(label)}</span><span class="dash-kpi-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></span></span><strong class="dash-kpi-value">${escape(value)}</strong><span class="dash-kpi-note">${escape(note)}</span></button>`;
        }

        function table(headers, rows, noRows) {
            if (!rows.length) {
                return empty(noRows);
            }
            return `<div class="dash-table-wrap"><table class="dash-table"><thead><tr>${headers.map(header => `<th scope="col">${escape(header)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
        }

        function pager(list, key = 'page') {
            if (!list.count) {
                return '';
            }
            return `<div class="dash-pager"><span>${number(list.count)} registros</span><div class="dash-pager-actions"><button type="button" class="dash-button" data-page="${list.page - 1}" data-page-key="${key}" ${list.page <= 1 ? 'disabled' : ''} aria-label="Página anterior"><i class="fas fa-chevron-left" aria-hidden="true"></i></button><span>${list.page} / ${list.pages}</span><button type="button" class="dash-button" data-page="${list.page + 1}" data-page-key="${key}" ${list.page >= list.pages ? 'disabled' : ''} aria-label="Página siguiente"><i class="fas fa-chevron-right" aria-hidden="true"></i></button></div></div>`;
        }

        function chart(sales, large = false) {
            const rows = sales.series.labels.map((label, index) => `<tr><td>${escape(date(label.length === 7 ? label + '-01' : label))}</td><td class="dash-number">${escape(money(sales.series.values[index]))}</td></tr>`);
            return panel('Evolución de ventas', 'Ventas ajustadas con impuestos · ' + date(state.filters.from) + ' — ' + date(state.filters.to),
                `<div class="dash-chart-wrap ${large ? 'dash-chart-large' : ''}"><canvas id="dash-sales-chart" role="img" aria-label="Evolución de ventas ajustadas. Los valores también están disponibles en la tabla inferior."></canvas></div><details class="dash-chart-table"><summary>Ver valores del gráfico</summary><div>${table(['Período', 'Ventas ajustadas'], rows, 'Sin movimientos en este período.')}</div></details>`);
        }

        function ranks(rows, section, products = false, limit = 10) {
            const visible = rows.slice(0, limit);
            const max = Math.max(1, ...visible.map(row => Number(row.amount)));
            const body = visible.length ? `<div class="dash-rank-list">${visible.map((row, index) => `<div class="dash-rank"><span class="dash-rank-position">${String(index + 1).padStart(2, '0')}</span><div><span class="dash-rank-name" title="${escape(row.name)}">${link(row.url, row.name || row.referencia || row.codcliente)}</span><div class="dash-bar" aria-hidden="true"><span style="width:${Math.max(0, Number(row.amount) / max * 100)}%"></span></div>${products ? `<span class="dash-cell-note">${escape(row.referencia)} · ${number(row.units)} unidades netas</span>` : ''}</div><strong class="dash-rank-amount">${escape(money(row.amount))}</strong></div>`).join('')}</div>` : empty('Todavía no hay ventas para este período.');
            return panel(products ? 'Productos protagonistas' : 'Clientes principales', products ? 'Ventas netas sin impuestos, con devoluciones' : 'Ventas ajustadas con impuestos · Consumidor final separado', body, tabLink(section));
        }

        function alerts(data) {
            const rows = [];
            function add(section, icon, title, subtitle, value) {
                rows.push(`<button type="button" class="dash-alert-row" data-section="${section}"><span class="dash-alert-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></span><span><strong>${escape(title)}</strong><small>${escape(subtitle)}</small></span><span class="dash-alert-number">${escape(value)}</span></button>`);
            }
            if (data.ebilling) {
                add('ebilling', 'shield-alt', 'Comprobantes pendientes', 'Autorización individual en el SRI', number(data.ebilling.count));
            }
            if (data.inventory) {
                add('inventory', 'boxes-stacked', 'Inventario por revisar', 'Referencias por almacén con alertas', number(data.inventory.counts.alerts_count));
            }
            if (data.receivables) {
                add('receivables', 'clock', 'Cartera vencida', 'Saldo cuyo vencimiento ya pasó', money(data.receivables.overdue));
            }
            if (data.cash) {
                add('cash', 'cash-register', 'Cajas abiertas', 'Aperturas vigentes de los terminales', number(data.cash.open.count));
            }
            return panel('Necesita tu atención', 'Estado actual de tu operación', `<div class="dash-alerts">${rows.join('')}</div>` || empty('Todo listo para consultar tu negocio.'));
        }

        function summary(data) {
            let cards = '';
            if (data.sales) {
                cards += metric('Facturado hoy', money(data.sales.today.billed), 'Con impuestos · ' + number(data.sales.today.invoices) + ' facturas', 'chart-line', 'sales');
                cards += metric('Facturado este mes', money(data.sales.month.billed), 'Con impuestos · ' + number(data.sales.month.invoices) + ' facturas', 'calendar-alt', 'sales');
            }
            if (data.receivables) {
                cards += metric('Por cobrar', money(data.receivables.balance), number(data.receivables.count) + ' facturas con saldo · Al día de hoy', 'file-invoice-dollar', 'receivables');
                cards += metric('Cartera vencida', money(data.receivables.overdue), 'Parte vencida del saldo por cobrar', 'clock', 'receivables', 'red');
            }
            let html = cards ? `<div class="dash-kpis">${cards}</div>` : '';
            html += `<div class="dash-grid ${data.sales ? 'dash-grid-main' : ''}">${data.sales ? chart(data.sales) : ''}${alerts(data)}</div>`;
            if (data.sales) {
                html += `<div class="dash-grid">${ranks(data.sales.customers, 'sales', false, 5)}${ranks(data.sales.products, 'sales', true, 5)}</div>`;
            }
            if (data.cash) {
                html += cash(data.cash, true);
            }
            if (Object.keys(data).length === 0) {
                return empty('Tu usuario tiene acceso al dashboard. Los indicadores aparecerán cuando tenga permisos sobre los módulos del negocio.');
            }
            return html;
        }

        function sales(data) {
            const sale = data.sales;
            const change = sale.change === null ? 'Sin base de comparación' : (sale.change >= 0 ? '+' : '') + number(sale.change) + ' % frente al período anterior';
            return `<div class="dash-kpis">${metric('Facturado en el período', money(sale.range.billed), 'Con impuestos · ' + number(sale.range.invoices) + ' facturas', 'file-invoice-dollar', 'sales')}${metric('Neto de facturas', money(sale.range.net), 'Sin impuestos', 'chart-line', 'sales')}${metric('Ventas ajustadas', money(sale.range.adjusted), 'Facturas + débitos − créditos', 'balance-scale', 'sales')}${metric('Ticket promedio', money(sale.range.ticket), 'Total facturado / número de facturas', 'receipt', 'sales')}</div>
                <div class="dash-note"><strong>${escape(change)}</strong> · Comparación con ${date(sale.previous_dates.from)} — ${date(sale.previous_dates.to)}. Notas de crédito: <strong>${money(sale.range.credits)}</strong> · Notas de débito: <strong>${money(sale.range.debits)}</strong>. Consumidor final: <strong>${money(sale.consumer)}</strong>.</div>
                ${chart(sale, true)}<div class="dash-toolbar"><label for="dash-product-order">Orden de productos</label><select id="dash-product-order" class="dash-input" data-filter="product_order"><option value="amount" ${state.filters.product_order === 'amount' ? 'selected' : ''}>Por importe neto</option><option value="units" ${state.filters.product_order === 'units' ? 'selected' : ''}>Por unidades netas</option></select></div>
                <div class="dash-grid">${ranks(sale.customers, 'sales')}${ranks(sale.products, 'sales', true)}</div>`;
        }

        function searchToolbar(extra = '') {
            return `<div class="dash-toolbar"><input type="search" class="dash-input dash-search" id="dash-search" aria-label="Buscar en esta sección" placeholder="Buscar en esta sección…" value="${escape(state.filters.q)}"><button type="button" class="dash-button" data-search>Buscar</button>${extra}</div>`;
        }

        function receivables(data) {
            const value = data.receivables;
            const labels = {current: 'Por vencer', days30: '1–30 días', days60: '31–60 días', days90: '61–90 días', older: 'Más de 90 días', undated: 'Sin vencimiento'};
            const aging = Object.keys(labels).map(key => `<div class="dash-aging-row"><span>${labels[key]}</span><div class="dash-bar" aria-hidden="true"><span style="width:${value.balance ? Math.max(0, value.aging[key] / value.balance * 100) : 0}%"></span></div><strong>${money(value.aging[key])}</strong></div>`).join('');
            const debtors = value.debtors.map(row => `<tr><td><span class="dash-cell-title">${escape(row.name || row.codcliente)}</span><span class="dash-cell-note">${number(row.invoices)} facturas</span></td><td class="dash-number">${money(row.amount)}</td><td class="dash-number dash-danger">${money(row.overdue)}</td></tr>`);
            const rows = value.list.rows.map(row => `<tr><td>${link(row.url, row.numero2 || row.codigo)}<span class="dash-cell-note">${date(row.fecha)}</span></td><td><span class="dash-cell-title">${escape(row.nombrecliente || row.codcliente)}</span></td><td class="dash-number">${money(row.total)}</td><td class="dash-number">${money(row.paid)}</td><td class="dash-number"><strong>${money(row.balance)}</strong></td><td>${date(row.due)}<span class="dash-cell-note ${row.days > 0 ? 'dash-danger' : ''}">${row.days > 0 ? number(row.days) + ' días de atraso' : 'Sin atraso'}</span></td></tr>`);
            return `<p class="dash-period-caption">Situación actual · El rango de fechas de ventas no recorta la deuda pendiente.</p><div class="dash-kpis">${metric('Saldo por cobrar', money(value.balance), number(value.count) + ' facturas pendientes', 'file-invoice-dollar', 'receivables')}${metric('Saldo vencido', money(value.overdue), 'Parte vencida de la cartera', 'clock', 'receivables', 'red')}</div>${searchToolbar()}<div class="dash-grid">${panel('Antigüedad de cartera', 'Saldo pendiente limitado a la deuda real de cada factura', `<div class="dash-aging">${aging}</div>`)}${panel('Principales deudores', 'Los 10 clientes con mayor saldo', table(['Cliente', 'Saldo', 'Vencido'], debtors, 'No hay cartera pendiente.'))}</div>${panel('Facturas por cobrar', 'Ordenadas por antigüedad de la factura', table(['Factura', 'Cliente', 'Total', 'Cobrado', 'Saldo', 'Próximo vencimiento'], rows, 'No hay facturas pendientes para esta selección.') + pager(value.list))}`;
        }

        function inventory(data) {
            const value = data.inventory;
            const options = '<option value="all">Todas las alertas</option>' + Object.entries(value.labels).map(([key, label]) => `<option value="${key}" ${state.filters.stock_state === key ? 'selected' : ''}>${escape(label)}</option>`).join('');
            const rows = value.list.rows.map(row => `<tr><td><span class="dash-cell-title">${link(row.url, row.referencia)}</span><span class="dash-cell-note">${escape(row.name)}</span></td><td>${escape(row.warehouse)}<span class="dash-cell-note">${escape(row.codalmacen)}</span></td><td class="dash-number">${number(row.cantidad)}<span class="dash-cell-note">Disponible: ${number(row.disponible)}</span></td><td class="dash-number">${number(row.reservada)}</td><td class="dash-number">${number(row.pterecibir)}</td><td class="dash-number">${number(row.stockmin)} / ${number(row.stockmax)}${row.percent !== null ? `<div class="dash-bar" aria-label="${escape(number(row.percent))} por ciento del máximo"><span style="width:${Number(row.percent)}%"></span></div>` : ''}</td><td>${row.alerts.map(key => badge(value.labels[key], ['out', 'below', 'invalid'].includes(key) ? 'red' : 'amber')).join('')}</td></tr>`);
            return `<p class="dash-period-caption">Existencias actuales por referencia y almacén · Avisos a un 20 % del mínimo o máximo configurado.</p><div class="dash-kpis">${metric('Sin existencias', number(value.counts.out_count), 'Stock físico igual o menor que cero', 'box-open', 'inventory', 'red')}${metric('Bajo mínimo', number(value.counts.below_count), 'Disponible igual o menor al mínimo', 'arrow-down', 'inventory', 'red')}${metric('Cerca del mínimo', number(value.counts.low_count), 'Hasta un 20 % por encima del mínimo', 'bell', 'inventory')}${metric('Máximo o proximidad', number(Number(value.counts.high_count || 0) + Number(value.counts.above_count || 0)), 'Desde el 80 % de la existencia máxima', 'arrow-up', 'inventory')}</div>${searchToolbar(`<select class="dash-input" data-filter="stock_state" aria-label="Tipo de alerta de inventario">${options}</select>`)}${panel('Inventario por revisar', 'Mínimo sobre disponible · Máximo sobre existencia física', table(['Producto', 'Almacén', 'Existencia', 'Reservado', 'Por recibir', 'Mín. / Máx.', 'Alertas'], rows, 'No hay alertas de inventario para esta selección.') + pager(value.list))}<div class="dash-note">Los límites en cero se consideran sin configurar. Un producto puede tener más de una señal; cada almacén conserva sus propios límites y saldos.</div>`;
        }

        function cash(value, compact = false) {
            const open = value.open.rows.map(row => `<tr><td>${link(row.url, row.terminal)}<span class="dash-cell-note">${escape(row.codalmacen)} · ${escape(row.nick)}</span></td><td>${time(row.fechaini)}</td><td class="dash-number">${money(row.dineroini, row.coddivisa)}</td><td class="dash-number"><strong>${money(row.totalcaja, row.coddivisa)}</strong><span class="dash-cell-note">${number(row.numtickets)} documentos</span></td></tr>`);
            const closedRows = rows => rows.map(row => `<tr><td>${link(row.url, row.terminal)}<span class="dash-cell-note">${escape(row.codalmacen)} · ${escape(row.nick)}</span></td><td>${time(row.fechafin)}<span class="dash-cell-note">Apertura: ${time(row.fechaini)}</span></td><td class="dash-number">${money(row.totalcaja, row.coddivisa)}</td><td class="dash-number">${money(row.dinerofin, row.coddivisa)}</td><td class="dash-number ${Math.abs(Number(row.diferencia)) >= .005 ? 'dash-danger' : ''}"><strong>${money(row.diferencia, row.coddivisa)}</strong></td></tr>`);
            let html = compact ? '' : '<p class="dash-period-caption">Cajas abiertas al día de hoy · Los cierres conservan sus importes registrados.</p>' + searchToolbar();
            html += panel('Cajas abiertas', number(value.open.count) + ' aperturas vigentes', table(['Terminal / Responsable', 'Apertura', 'Inicial', 'Efectivo esperado'], open, 'No hay cajas abiertas.') + pager(value.open, 'open_page'), compact ? tabLink('cash') : '');
            html += panel('Último cierre por terminal', 'Último cierre guardado, independientemente del período seleccionado', table(['Terminal', 'Cierre', 'Esperado', 'Contado', 'Diferencia'], closedRows(value.last), 'Todavía no hay cierres de caja registrados.'));
            if (!compact) {
                html += panel('Histórico de cierres', date(state.filters.from) + ' — ' + date(state.filters.to), table(['Terminal', 'Cierre', 'Esperado', 'Contado', 'Diferencia'], closedRows(value.history.rows), 'No hay cierres en este período.') + pager(value.history));
            }
            return html;
        }

        function ebilling(data) {
            const value = data.ebilling;
            const typeOptions = '<option value="">Todos los tipos</option>' + Object.entries(value.types).map(([key, label]) => `<option value="${key}" ${state.filters.doc_type === key ? 'selected' : ''}>${escape(label)}</option>`).join('');
            const statusOptions = '<option value="">Todos los estados</option>' + ['SIN GENERAR', 'PENDIENTE', 'FIRMADO', 'RECIBIDA', 'DEVUELTO', 'RECHAZADO', 'ERROR SRI', 'ERROR INTERNO'].map(status => `<option value="${status}" ${state.filters.sri_state === status ? 'selected' : ''}>${status}</option>`).join('');
            const environmentOptions = '<option value="">Todos los ambientes</option><option value="1" ' + (state.filters.environment === '1' ? 'selected' : '') + '>Pruebas</option><option value="2" ' + (state.filters.environment === '2' ? 'selected' : '') + '>Producción</option>';
            const rows = value.list.rows.map(row => `<tr><td>${link(row.url, row.number)}<span class="dash-cell-note">${escape(row.label)} · Serie ${escape(row.codserie)}</span></td><td>${date(row.fecha)}<span class="dash-cell-note">${escape(row.codalmacen)}</span></td><td><span class="dash-cell-title">${escape(row.subject)}</span></td><td>${badge(row.status, /ERROR|RECHAZ|DEVUEL/.test(row.status) ? 'red' : 'amber')}${badge(row.env === '2' || row.env === 2 ? 'Producción' : (row.env === '1' || row.env === 1 ? 'Pruebas' : 'Sin generar'), 'neutral')}</td><td class="dash-cell-message">${escape(row.message || 'Pendiente de autorización.')}</td><td>${row.can_authorize ? `<button type="button" class="dash-button dash-button-primary" data-authorize="${escape(row.doc_type)}" data-document="${Number(row.doc_id)}" ${authorizing ? 'disabled' : ''}><i class="fas fa-check" aria-hidden="true"></i> Autorizar</button>` : '<span class="dash-muted">Solo consulta</span>'}</td></tr>`);
            return `<p class="dash-period-caption">Pendientes actuales de todas las fechas · Se incluyen comprobantes elegibles todavía sin XML.</p>${searchToolbar(`<select class="dash-input" data-filter="doc_type" aria-label="Tipo de comprobante">${typeOptions}</select><select class="dash-input" data-filter="sri_state" aria-label="Estado electrónico">${statusOptions}</select><select class="dash-input" data-filter="environment" aria-label="Ambiente del SRI">${environmentOptions}</select>`)}${panel('Comprobantes por autorizar', number(value.count) + ' documentos pendientes en esta selección', table(['Comprobante', 'Fecha / Almacén', 'Destinatario', 'Estado', 'Mensaje', 'Acción'], rows, 'No hay comprobantes pendientes para esta selección.') + pager(value.list))}<div class="dash-note">La autorización procesa un documento por vez y muestra la respuesta del SRI. Si faltan datos, abre el comprobante para corregirlos y vuelve a intentarlo.</div>`;
        }

        function drawChart() {
            while (charts.length) {
                charts.pop().destroy();
            }
            const canvas = document.getElementById('dash-sales-chart');
            if (!canvas || !window.Chart || !state.data.sales) {
                return;
            }
            const style = getComputedStyle(root);
            const color = style.getPropertyValue('--dash-accent').trim();
            const muted = style.getPropertyValue('--dash-muted').trim();
            const border = style.getPropertyValue('--dash-border').trim();
            const context = canvas.getContext('2d');
            const gradient = context.createLinearGradient(0, 0, 0, 260);
            gradient.addColorStop(0, 'rgba(8,151,160,.20)');
            gradient.addColorStop(1, 'rgba(8,151,160,0)');
            charts.push(new window.Chart(context, {type: 'line', data: {labels: state.data.sales.series.labels,
                datasets: [{label: 'Ventas ajustadas', data: state.data.sales.series.values, borderColor: color, backgroundColor: gradient, borderWidth: 2.5, pointRadius: state.data.sales.series.labels.length > 40 ? 0 : 2, pointHoverRadius: 5, lineTension: .25}]},
                options: {responsive: true, maintainAspectRatio: false, animation: {duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 350}, legend: {display: false},
                    tooltips: {mode: 'index', intersect: false, callbacks: {label: item => ' ' + money(item.yLabel)}},
                    scales: {xAxes: [{gridLines: {display: false}, ticks: {fontColor: muted, fontSize: 10, maxTicksLimit: 8, maxRotation: 0}}],
                        yAxes: [{gridLines: {color: border, drawBorder: false, borderDash: [3, 4]}, ticks: {fontColor: muted, fontSize: 10, beginAtZero: true, maxTicksLimit: 5, callback: value => Math.abs(value) >= 1000 ? '$' + number(value / 1000) + ' mil' : '$' + number(value)}}]}}}));
        }

        function render() {
            const section = state.filters.section;
            const renderers = {summary, sales, receivables, inventory, ebilling, cash: data => cash(data.cash)};
            while (charts.length) {
                charts.pop().destroy();
            }
            content.innerHTML = Object.keys(state.data).length ? renderers[section](state.data) : empty(state.error || 'No hay indicadores disponibles para tu usuario.');
            content.setAttribute('aria-labelledby', 'dash-tab-' + section);
            root.querySelectorAll('.dash-tab').forEach(tab => {
                const selected = tab.dataset.section === section;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
            });
            drawChart();
        }

        function showMessage(text, error = false) {
            message.textContent = text;
            message.hidden = !text;
            message.classList.toggle('is-error', error);
        }

        function parameters() {
            const result = new URLSearchParams(new FormData(form));
            ['section', 'page', 'open_page', 'q', 'stock_state', 'doc_type', 'sri_state', 'environment', 'product_order'].forEach(key => {
                if (state.filters[key] !== undefined) {
                    result.set(key, state.filters[key]);
                }
            });
            return result;
        }

        async function load(keepMessage = false) {
            if (pending) {
                pending.abort();
            }
            pending = new AbortController();
            const sequence = ++requestNumber;
            const params = parameters();
            params.set('action', 'data');
            const url = new URL(endpoint);
            url.search = params.toString();
            content.classList.add('is-loading');
            content.setAttribute('aria-busy', 'true');
            if (!keepMessage) {
                showMessage('');
            }
            try {
                const response = await fetch(url, {credentials: 'same-origin', signal: pending.signal, headers: {Accept: 'application/json'}});
                const result = await response.json();
                if (sequence !== requestNumber) {
                    return;
                }
                if (result.token) {
                    state.token = result.token;
                }
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'No se pudo cargar esta sección.');
                }
                state.data = result.data;
                state.error = '';
                state.filters.from = form.elements.from.value;
                state.filters.to = form.elements.to.value;
                render();
                document.getElementById('dash-updated').textContent = 'Actualizado ' + result.updated;
                params.delete('action');
                const browserUrl = new URL(endpoint);
                browserUrl.search = params.toString();
                window.history.replaceState(null, '', browserUrl);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    showMessage(error.message || 'La conexión no está disponible. Vuelve a actualizar el panel.', true);
                }
            } finally {
                if (sequence === requestNumber) {
                    content.classList.remove('is-loading');
                    content.setAttribute('aria-busy', 'false');
                }
            }
        }

        async function authorize(button) {
            if (authorizing) {
                return;
            }
            authorizing = true;
            root.querySelectorAll('[data-authorize]').forEach(item => { item.disabled = true; });
            button.innerHTML = '<span class="dash-spinner" aria-hidden="true"></span> Autorizando…';
            const url = new URL(endpoint);
            const params = parameters();
            params.set('action', 'authorize-document');
            url.search = params.toString();
            const body = new URLSearchParams({doc_type: button.dataset.authorize, doc_id: button.dataset.document, multireqtoken: state.token});
            showMessage('Procesando el comprobante seleccionado. Esperando la respuesta del SRI…');
            try {
                const response = await fetch(url, {method: 'POST', credentials: 'same-origin', body, headers: {Accept: 'application/json'}});
                const result = await response.json();
                if (result.token) {
                    state.token = result.token;
                }
                const detail = result.document && result.document.message && !result.ok ? ' ' + result.document.message : '';
                showMessage((result.message || 'No se pudo completar la autorización.') + detail, !result.ok);
                authorizing = false;
                await load(true);
            } catch (error) {
                showMessage('No se pudo confirmar el resultado. Actualiza el estado del comprobante antes de reintentarlo.', true);
            } finally {
                authorizing = false;
                root.querySelectorAll('[data-authorize]').forEach(item => {
                    item.disabled = false;
                    item.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Autorizar';
                });
            }
        }

        function updateWarehouseLabel() {
            const checked = form.querySelectorAll('[name="warehouses[]"]:checked');
            document.getElementById('dash-warehouse-label').textContent = checked.length ? checked.length + ' almacén' + (checked.length === 1 ? '' : 'es') + ' seleccionado' + (checked.length === 1 ? '' : 's') : 'Todos los almacenes';
        }

        form.addEventListener('submit', event => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            state.filters.page = state.filters.open_page = 1;
            document.getElementById('dash-warehouse-picker').open = false;
            load();
        });
        form.addEventListener('change', updateWarehouseLabel);
        document.getElementById('dash-all-warehouses').addEventListener('click', () => {
            form.querySelectorAll('[name="warehouses[]"]').forEach(input => { input.checked = false; });
            updateWarehouseLabel();
        });
        document.getElementById('dash-refresh').addEventListener('click', () => load());
        document.getElementById('dash-period').addEventListener('change', event => {
            if (event.target.value === 'custom') {
                return;
            }
            const today = new Date(state.today + 'T12:00:00');
            let from = state.today;
            if (event.target.value === 'week') {
                today.setDate(today.getDate() - 6);
                from = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
            } else if (event.target.value === 'month') {
                from = state.today.slice(0, 7) + '-01';
            } else if (event.target.value === 'year') {
                from = state.today.slice(0, 4) + '-01-01';
            }
            form.elements.from.value = from;
            form.elements.to.value = state.today;
            state.filters.page = 1;
            load();
        });
        ['dash-from', 'dash-to'].forEach(id => document.getElementById(id).addEventListener('change', () => {
            document.getElementById('dash-period').value = 'custom';
        }));
        root.addEventListener('click', event => {
            const sectionButton = event.target.closest('[data-section]');
            if (sectionButton && state.sections[sectionButton.dataset.section]) {
                state.filters.section = sectionButton.dataset.section;
                Object.assign(state.filters, {page: 1, open_page: 1, q: '', stock_state: 'all', doc_type: '', sri_state: '', environment: ''});
                load();
            }
            const pageButton = event.target.closest('[data-page]');
            if (pageButton && !pageButton.disabled) {
                state.filters[pageButton.dataset.pageKey] = Number(pageButton.dataset.page);
                load();
            }
            const authButton = event.target.closest('[data-authorize]');
            if (authButton) {
                authorize(authButton);
            }
            if (event.target.closest('[data-search]')) {
                state.filters.q = document.getElementById('dash-search').value;
                state.filters.page = 1;
                load();
            }
        });
        root.addEventListener('change', event => {
            if (event.target.dataset.filter) {
                state.filters[event.target.dataset.filter] = event.target.value;
                state.filters.page = 1;
                load();
            }
        });
        root.addEventListener('keydown', event => {
            if (event.target.id === 'dash-search' && event.key === 'Enter') {
                event.preventDefault();
                state.filters.q = event.target.value;
                state.filters.page = 1;
                load();
            }
            if (event.target.classList.contains('dash-tab') && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                const tabs = Array.from(root.querySelectorAll('.dash-tab'));
                let index = tabs.indexOf(event.target) + (event.key === 'ArrowLeft' ? -1 : 1);
                if (event.key === 'Home') {
                    index = 0;
                } else if (event.key === 'End') {
                    index = tabs.length - 1;
                }
                const tab = tabs[(index + tabs.length) % tabs.length];
                tab.focus();
                tab.click();
            }
        });
        new MutationObserver(drawChart).observe(document.documentElement, {attributes: true, attributeFilter: ['data-theme']});
        document.getElementById('dash-period').value = state.filters.from === state.today.slice(0, 7) + '-01' && state.filters.to === state.today ? 'month' : 'custom';
        updateWarehouseLabel();
        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
