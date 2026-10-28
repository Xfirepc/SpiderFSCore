# SpiderDashboard

Panel de negocio en `/Dashboard`: resumen, ventas, cartera, inventario, caja y
comprobantes electrónicos. Funciona con la empresa del usuario y permite consultar
todos sus almacenes o una selección. Los indicadores y las acciones respetan los
permisos de los módulos de origen y la restricción de datos propios.

## Activación

1. Desplegar `SpiderDashboard` y los cambios compartidos de esta entrega:
   `Core/Lib/CustomerBalance.php`, `SpiderAccounting/Lib/CustomerStatement.php` y
   los cambios de emisión de `SpiderFact` (incluido `DocumentEmissionLock.php`).
2. En **AdminPlugins**, activar **SpiderDashboard** y pulsar **Reconstruir**. La
   reconstrucción publica la vista y los assets; no editar `Dinamic` manualmente.
3. Abrir `/Dashboard`. Si los roles o el menú del tenant restringen módulos,
   únicamente aparecerán los autorizados. La acción de autorizar requiere permiso
   de modificación en el editor del documento correspondiente.

La activación no añade tablas ni modifica saldos o documentos históricos. Para
volver al dashboard anterior, desactivar SpiderDashboard y reconstruir. Conservar
`CustomerBalance.php` al actualizar Core: también lo utiliza el estado de cuenta.

## Criterios de los indicadores

- **Hoy/mes:** total guardado de facturas no anuladas en USD, con impuestos, aunque
  sigan pendientes del SRI. El análisis muestra también neto, créditos, débitos,
  ventas ajustadas, ticket y comparación con el período anterior de igual duración.
- **Rankings:** clientes por venta ajustada con impuestos; consumidor final aparte.
  Productos por importe neto o unidades, con devoluciones y descuentos globales.
  Las notas de débito ajustan importes, sin añadir unidades vendidas.
  Los componentes técnicos de packs/recetas no duplican las líneas comerciales.
- **Cartera actual:** pagos efectivos, reversos, recibos históricos y abonos;
  los sobrepagos no aumentan la deuda. La antigüedad se distribuye desde el
  vencimiento más antiguo, limitada al saldo real; lo no fechado aparece separado.
- **Inventario actual:** mínimo sobre disponible, máximo sobre existencia física;
  proximidad del 20 %. Un límite cero se considera no configurado. Las referencias
  se evalúan por almacén y pueden presentar varias señales simultáneas.
- **Caja:** los totales abiertos se calculan con `TpvCaja` sin guardar. Los cierres
  se leen como fueron registrados. El histórico se filtra por fecha de cierre.
- **Electrónicos actuales:** facturas, notas, guías, retenciones y liquidaciones
  propias. Incluye documentos elegibles sin XML. Una factura recibida no se
  convierte en liquidación por compartir serie. Se distinguen pruebas/producción.

La autorización es individual mediante POST y token. Revalida contexto y permisos
bajo un bloqueo por base/tipo/id, consulta claves previamente generadas antes de
regenerarlas y conserva el XML de la respuesta del SRI. No envía correos desde el
panel. El cron existente mantiene su configuración y utiliza el mismo bloqueo.
Los indicadores comerciales funcionan con `allow_accounting` activado o
desactivado; las escrituras de emisión conservan los modelos y sus reglas.

## Pruebas

Desde `html/`, sin cargar `config.php` ni `Test/bootstrap.php`:

```bash
php vendor/bin/phpunit --do-not-cache-result --no-configuration --bootstrap vendor/autoload.php Plugins/SpiderDashboard/Test/DashboardTest.php
php vendor/bin/phpunit --do-not-cache-result --no-configuration --bootstrap vendor/autoload.php Plugins/SpiderDashboard/Test/DashboardControllerTest.php
php vendor/bin/phpunit --do-not-cache-result --no-configuration --bootstrap vendor/autoload.php Plugins/SpiderDashboard/Test/EmissionTest.php
php vendor/bin/phpunit --do-not-cache-result --no-configuration --bootstrap vendor/autoload.php Plugins/SpiderAccounting/Test/CustomerStatementTest.php
node Plugins/SpiderDashboard/Test/browser.cjs
```

El navegador requiere Chromium y Node 22 o posterior. Sus respuestas del ERP/SRI
son simuladas; verifica el Twig y los assets reales, las pestañas, filtros,
paginación, escape de HTML, POST individual, tema oscuro y móvil. Genera capturas
de datos sintéticos en el directorio temporal del sistema.

La integración utiliza exclusivamente una MariaDB nueva y desechable, con una
base llamada `spider_dashboard_tests`. El bootstrap rechaza ejecuciones sin la
variable explícita de prueba. Ejemplo desde la raíz del workspace:

```bash
docker run -d --rm --name spider-dashboard-test-db -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:10.7
docker exec spider-dashboard-test-db mariadb-admin ping --wait=30
docker exec spider-dashboard-test-db mariadb -uroot -e 'CREATE DATABASE spider_dashboard_tests'
docker run --rm --network container:spider-dashboard-test-db -v "$PWD/html:/app:ro" -w /app -e SPIDER_DASHBOARD_TEST_DB=1 --entrypoint php main_spidercodeapp-php:latest vendor/bin/phpunit --do-not-cache-result --no-configuration --bootstrap Plugins/SpiderDashboard/Test/Fixtures/integration-bootstrap.php Plugins/SpiderDashboard/Test/DashboardIntegrationTest.php
docker stop spider-dashboard-test-db
```

La imagen PHP del ejemplo debe tener las extensiones del proyecto y PHP 7.4 o
posterior. Los tests crean sus esquemas a partir de los XML de este workspace,
comprueban consultas y exclusión entre conexiones, sin acceder a las bases de las
instancias. Las llamadas reales al SRI se validan funcionalmente en su ambiente de
pruebas, con un documento y certificado de prueba.
