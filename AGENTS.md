# Instrucciones del proyecto

Estas instrucciones se aplican a Core y a todos los plugins bajo `Plugins/`.

## Regla permanente: código limpio y mantenible

- Escribir código legible, con nombres que expresen su propósito y métodos con una
  responsabilidad clara. Usar bloques e indentación consistentes; no comprimir
  validaciones, bucles o manejo de errores en una sola línea.
- Declarar el `namespace` y los imports `use` en la cabecera. Referenciar las clases
  por su nombre importado; resolver colisiones con alias descriptivos y eliminar
  imports sin uso. Para clases conocidas, preferir `Clase::class` a cadenas con
  namespaces. Conservar la resolución dinámica cuando el modelo dependa del
  documento o de plugins opcionales.
- Reutilizar la lógica de negocio compartida. Evitar copiar validaciones, cálculos
  de stock y bloques de persistencia entre controladores o plugins. Extraer métodos
  o servicios cuando expresen una operación común real; no crear abstracciones que
  sólo añadan indirección.
- Preferir el ORM de FacturaScripts: modelos, `loadFromCode()`, `all()`, `count()` y
  `DataBaseWhere`; para lecturas con bloqueo dentro de una transacción, usar
  `allForUpdate()`. Usar los modelos de `Dinamic` cuando corresponda respetar las
  extensiones instaladas. No sustituir operaciones del modelo por escrituras SQL
  que omitan sus validaciones, hooks o efectos de stock.
- Reservar SQL directo para operaciones que el ORM no pueda expresar con las mismas
  garantías, como bloqueos de filas, reclamaciones atómicas de idempotencia y ciertas
  consultas agregadas. Explicar la razón junto a la consulta; escapar valores con la
  API de base de datos y usar tablas/columnas de origen controlado. No eliminar un
  bloqueo para simplificar la sintaxis.
- Evitar consultas repetidas dentro de bucles y cargas completas innecesarias.
  Conservar precisión, orden de bloqueos, atomicidad y propagación de errores al
  optimizar. Respetar la versión de PHP soportada y los contratos de los modelos.
- Al mejorar código existente, concentrar el cambio en el flujo afectado y comprobar
  su comportamiento. Las pruebas deben cubrir resultados e invariantes, no depender
  del texto exacto ni de los imports del código fuente.

## Regla permanente: inventario y stock

- Las operaciones futuras deben confirmar o revertir juntos documento, líneas,
  saldos y movimientos. Reutilizar `Inventory` y el modelo `Stock`; comprobar el
  resultado de cada guardado, borrado y confirmación de transacción.
- Distinguir un movimiento relativo de un conteo absoluto. Conservar almacén,
  referencia, cantidades reservadas y pendientes de recibir, composición original
  y vínculos de devolución. Los reintentos no deben duplicar ventas ni salidas.
- Registrar los movimientos desde los cambios efectivos de saldo y conservar el
  historial nuevo inmutable. No reconstruir existencias desde un historial parcial.
- No reparar ni reinterpretar datos históricos de tenants como parte de estos
  cambios. La corrección histórica queda para la revisión manual solicitada.
- Al reutilizar saldos o cálculos, limitar su vida a la transacción real de base
  de datos, incluyendo transacciones exteriores. Invalidarlos al confirmar,
  revertir, reintentar o cambiar la identidad de productos y variantes. Mantener
  los totales derivados visibles después de cada guardado y registrar cada efecto
  individual; no reemplazar bloqueos ni lecturas vigentes por cachés persistentes.
- Medir las optimizaciones contra una copia del workspace que incluya los plugins
  ignorados por Git. Comparar consultas, latencia, concurrencia y memoria con la
  misma carga y datos desechables; no confundir regresiones funcionales con pruebas
  de rendimiento ni afirmar mejoras de producción sin mediciones representativas.
- Ejecutar las regresiones de inventario en una base desechable. No cargar
  `config.php`, `Test/bootstrap.php` ni conectar a las bases de las instancias para
  estas pruebas. Documentar qué se comprobó y qué requiere validación funcional.

## Regla permanente: USD para Ecuador

La prioridad funcional confirmada por el usuario es operar en **USD** en Ecuador.
Actualmente no se requiere operar en EUR ni usar EUR como moneda funcional.

- En los flujos de Ecuador, USD es la moneda de referencia para documentos,
  recibos, pagos, asientos e informes. Para una operación USD contabilizada en
  USD, la tasa efectiva es **1** y el importe contable coincide con el importe
  original: un pago de **51,75 USD** se contabiliza por **51,75 USD**.
- No interpretar las tasas históricas del catálogo de divisas respecto de EUR
  como tasas respecto de USD. El valor heredado `USD.tasaconv = 1.129` no debe
  convertir un pago de 51,75 USD en un asiento de 45,84 USD.
- Al corregir moneda o conversión, revisar de forma coherente compras, ventas,
  recibos, pagos y sus informes; no limitar la corrección al importe visible.
  Conservar la regla `allow_accounting` y la trazabilidad de asientos históricos.

## Regla permanente: `allow_accounting`

Todas las funciones y restricciones contables deben depender de la configuración
de la instancia `facturacione.allow_accounting`. Esta es una regla funcional
confirmada por el usuario y debe conservarse en futuros cambios.

- Consultar `FacturaScripts\Core\Lib\Accounting\AccountingSettings::isEnabled()`;
  no duplicar la interpretación del setting ni mantener una caché propia. En Twig,
  utilizar `accountingEnabled()`.
- Con el check **desactivado**, ninguna restricción contable debe impedir
  operaciones sobre facturas, líneas, pagos o recibos, aunque tengan asientos
  previos. Esto incluye los bloqueos de inmutabilidad de datos económicos, la
  obligación de corregir mediante notas o reversar pagos y los campos de pantalla
  bloqueados exclusivamente por motivos contables.
- Los procesos automáticos deben omitir silenciosamente la contabilización, la
  trazabilidad contable nueva y el kardex contable cuando el check esté desactivado.
  No deben exigir cuentas especiales ni validar existencias del kardex contable
  para permitir una operación comercial.
- Las acciones explícitas de contabilizar, revertir, conciliar o cerrar deben
  rechazarse mediante `AccountingSettings::requireEnabled()` cuando el check esté
  desactivado; sus controles de interfaz deben respetar el mismo setting. Nunca
  informar que una operación contable se completó si se omitió.
- Con el check desactivado no se crean, editan, eliminan ni renumeran asientos o
  partidas. Las ediciones operativas no deben borrar, regenerar ni desvincular los
  asientos históricos. La existencia de `idasiento` no habilita la contabilidad.
- Los permisos, estados del documento y validaciones operativas siguen vigentes.
  Con el check **activado**, se aplican las funciones y protecciones contables
  correspondientes. Activarlo no contabiliza retroactivamente ni modifica los
  asientos históricos para reflejar ediciones hechas con el check desactivado.

Al cambiar una integración o restricción contable, comprobar ambos estados del
check, documentos con asientos previos y los flujos de clientes y proveedores que
resulten afectados. Las pruebas deben verificar que desactivar permite la operación
sin efectos contables y que reactivar restablece la protección. Revisar tanto las
validaciones del servidor como las restricciones de la interfaz.

La descripción funcional está en
[la guía contable, sección 9](Plugins/SpiderAccounting/Doc/guia_configuracion_contable.md#9-setting-allow_accounting).
Las pruebas de regresión existentes están en `Plugins/SpiderAccounting/Test/` y
`Plugins/SpiderPagos/Test/`; ejecutarlas con `vendor/autoload.php`, sin cargar la
configuración ni conectar a la base de datos de la instancia.

## Directrices generales de CLAUDE.md

Las siguientes directrices, adaptadas del `CLAUDE.md` del workspace, se conservan
versionadas junto con las reglas específicas anteriores. Las rutas `html/` y
`docker/` de estas secciones parten de la carpeta contenedora del proyecto,
donde se encuentran `CLAUDE.md`, `html/` y `docker/`.

### Project Overview

This is a **FacturaScripts**-based ERP system customized for Ecuador, running as a Docker-based PHP application. The custom plugins (prefixed `Spider*`) add Ecuador-specific functionality: electronic invoicing (SpiderFact), POS (SpiderTPV), WhatsApp integration, theming, food/recipes, and more.

### Running the Application

```bash
# Start/stop (from project root)
cd docker && docker compose up -d
cd docker && docker compose down

# The app is served via nginx → php-fpm, accessible at the configured network alias (multispider.mm)
# External spider-network must exist: docker network create spider-network
```

After modifying CSS/JS/assets in a plugin, **rebuild the plugin from AdminPlugins** (browser: `/AdminPlugins`) to copy assets into `Dinamic/Assets/`.

### Architecture

#### Core (html/Core/)
FacturaScripts MVC framework. Controllers, Models, Views (Twig templates), XMLView definitions, Lib (services), and Table (XML schema definitions). Core classes in `Base/` and `DataSrc/` **cannot** be overridden by plugins.

#### Dinamic System (html/Dinamic/)
Auto-generated directory that merges Core + Plugin overrides. Contains the same subdirectories as Core (Controller, Model, View, XMLView, etc.). **Never edit files here directly** — they are regenerated when plugins are rebuilt from AdminPlugins.

#### Plugins (html/Plugins/)
Each plugin follows the same structure as Core and can override or extend any Core class:

- **facturascripts.ini** — plugin metadata (name, version, dependencies via `require`)
- **Init.php** — plugin lifecycle hooks (init, update, uninstall)
- **Controller/** — new pages or overrides of Core controllers
- **Extension/** — trait-based extensions that inject into existing controllers/models without replacing them
- **Model/** — new or overriding data models
- **View/** & **XMLView/** — Twig templates and XML view definitions
- **Table/** — XML table schema definitions
- **Assets/** — CSS, JS, images
- **Lib/** — helper classes and services
- **Mod/** — modifiers (used by some plugins like SpiderTPV)
- **Translation/** — JSON translation files (Spanish)

#### Key Custom Plugins
| Plugin | Purpose | Dependencies |
|---|---|---|
| **SpiderFact** | Ecuador electronic invoicing (SRI), XML signing | SpiderWhatsApp, SpiderPrinter |
| **SpiderTPV** | Point of Sale (POS) | Tickets |
| **SpiderThemeClassic** | Custom dark/light theme | — |
| **SpiderAgent** | AI chat widget | — |
| **SpiderAccounting** | Accounting extensions | — |
| **SpiderTools** | Shared utilities (Guzzle HTTP, etc.) | — |

#### Extension Pattern
Plugins use PHP traits in `Extension/` directories to add behavior to existing classes. Extensions are registered in `Init.php` and merged at runtime — this is how plugins modify Core behavior without replacing files.

### SpiderAgent Cache Busting

When modifying SpiderAgent assets (chat.js, chat.css), always increment the `c` query parameter in `html/Plugins/SpiderAgent/View/Master/Widgets.html.twig` (e.g., `chat.css?c=28` → `chat.css?c=29`).

### Core Customizations (reapply on FacturaScripts upgrade)

Estos cambios viven en `html/Core/` y se pierden si se actualiza FacturaScripts. Reaplicar tras cada upgrade:

- **`Core/Model/FormaPago.php`, `Core/Table/formaspago.xml`, `Core/Lib/Accounting/AccountingAccounts.php`, `Core/XMLView/EditFormaPago.xml`, `Core/XMLView/ListFormaPago.xml` y traducciones `es_EC`/`es_ES`** — subcuenta contable opcional por forma de pago (`codsubcuenta`). Para cobros, pagos y anticipos, la cuenta bancaria de la operación o de la forma de pago tiene prioridad; si no hay banco, se usa la subcuenta propia y, solo si tampoco se configuró, CAJA. La subcuenta explícita se busca por código en el ejercicio de la operación y se valida la empresa. Si falta, no se crea automáticamente ni se sustituye por otra. No se alteran asientos históricos y se conserva la regla `allow_accounting`. Pruebas aisladas: `Plugins/SpiderAccounting/Test/PaymentSubaccountTest.php`. Al desplegar, reconstruir desde AdminPlugins para combinar el esquema y las vistas de Core con las extensiones de SpiderFact/SpiderPagos; no editar Dinamic manualmente.

- **`html/Core/Model/AttachedFile.php`** — método `setFile()`: el nombre del archivo añade entropía (`{time}_{bin2hex(random_bytes(6))}.{ext}`) en vez de solo `{time}.{ext}`. Motivo: en este sistema multitenant `MyFiles/` es compartido entre tenants pero cada tenant tiene su propia BD; sin entropía dos uploads en el mismo segundo con misma extensión colisionan, `rename()` sobreescribe, y un `unlink` posterior afecta archivos de otros tenants.

- **`html/Core/XMLView/EditStock.xml`** — se agregan atributos `numcolumns` a todas las columnas para distribuirlas en dos filas balanceadas de 12 (Bootstrap) y evitar el wrap incómodo dentro del tab Stock de `EditProducto`. Distribución: fila 1 → `reference`(3) + `warehouse`(3) + `quantity`(2) + `location`(4); fila 2 → `stockmin`(2) + `stockmax`(2) + `reserved`(2) + `pending-reception`(3) + `available`(3).

- **`html/Core/Base/AjaxForms/PurchasesLineHTML.php`** — añade indicador de stock en las líneas de compra (`EditFacturaProveedor`, `EditAlbaranProveedor`, etc.) replicando el comportamiento que ya existe en ventas (`SalesLineHTML::cantidadStock`). Cambios:
  1. `use FacturaScripts\Dinamic\Model\Stock;`
  2. Nuevo método privado `cantidadStock()` que pinta un botón `btn-outline-success`/`btn-outline-danger` con `stock->cantidad` del almacén actual, usando la caché `self::$stocks` precargada por `CommonLineHTML::loadProducts()`.
  3. En `cantidad()` (rama editable) se invoca `self::cantidadStock($i18n, $line, $model)` justo después del input, dentro del mismo `input-group`.

- **`html/Core/Base/MenuManager.php`** — filtro de menú por tenant (customización de 2025-08-13): `loadPages()` y `getAllowedNamePages()` leen `MyFiles/Menu/{FS_DB_NAME}.json` (formato `{"PageName": true|false}`). Sin archivo → fail-open (se ve todo lo `showonmenu=true`). Con mapa poblado → whitelist: página ausente = oculta; el JSON solo restringe, nunca muestra lo que en BD tiene `showonmenu=false`. Los JSON los escriben `EditSBInstallation` (tab Menu) y `SpiderBuilder\Lib\MenuAccessSync` (sincronización masiva desde `MassMenuSyncSBInstallation` y automática tras el rebuild masivo). Además, `ACCOUNTING_SUBMENUS` agrupa el menú plano de Contabilidad por áreas funcionales sin modificar las rutas, permisos ni el `submenu` persistido de las páginas; si un controlador declara su propio submenú, este tiene prioridad.

- **`html/Core/XMLView/EditProducto.xml` y `html/Core/XMLView/EditVariante.xml`** — el widget de `referencia` baja de `maxlength="30"` a `maxlength="25"`. Motivo: el XSD del SRI limita `codigoPrincipal` a 25 caracteres; con 30 la factura se rechaza con `cvc-maxLength-valid`. (Complementa la truncada defensiva a 25 en `SpiderFact/Lib/DocumentLayout/DetalleDocument.php` y el script de datos `~/Viny/Spider/dbs/fix_referencias_25.sql`.)

- **`html/Core/Plugins.php`** — método `deploy()`: al final se llama `opcache_reset()` (con guard `function_exists`). Motivo: producción corre con `opcache.validate_timestamps=Off` (rendimiento), así que un `git pull` + "Reconstruir" desde AdminPlugins dejaba el bytecode viejo compilado en memoria indefinidamente hasta reiniciar el contenedor php-fpm — el código en disco ya estaba actualizado pero el opcache servía la versión anterior. Como `deploy()` es el método común a `install()`/`enable()`/`disable()`/AdminPlugins/el script CLI de rebuild, este es el único punto que cubre todos los caminos de reconstrucción.

### Key Conventions

- **Pantallas de error SpiderCode (Core)**: `html/Core/ErrorPage.php` y `html/Core/View/Error/Standalone.html.php` unifican la presentación de excepciones (`Core/Template/ErrorController.php`, `Core/Error/DefaultError.php`), errores fatales (`Core/CrashReport.php`), páginas no encontradas (`Core/Error/PageNotFound.php`), acceso denegado (`Core/Error/AccessDenied.php`) y sistema ya instalado (`Core/Error/AlreadyInstalled.php`). `Core/Base/Controller.php` dirige la plantilla antigua `Error/AccessDenied` al controlador común, conservando el estado 403 también para permisos sobre registros. Los detalles solo se generan para `Session::get('user')` autenticado, habilitado y administrador; no consultar nuevamente la BD ni confiar en cookies o `FS_DEBUG`. `Core/Kernel.php` limpia la salida parcial antes de mostrar el error. Conservar esta personalización al actualizar FacturaScripts. La prueba aislada y las notas de registro están en `html/Test/README.md`.

- **Language**: All code comments, translations, and UI text are in **Spanish**
- **PHP version**: Determined by Docker PHP container (see `docker/php/`)
- **Database**: MySQL/MariaDB (external to this docker-compose, on the spider-network)
- **Templates**: Twig (`.html.twig` files)
- **View definitions**: XML files in `XMLView/` define list/edit views declaratively (columns, widgets, filters)
- **Table schemas**: XML files in `Table/` define database tables; FacturaScripts auto-creates/migrates them

### Builds y exportaciones para publicar

- Antes de cada nueva entrega de un build/export estático, preguntar al usuario la URL pública completa y el path público exacto (por ejemplo `/`, `/fletexa/` o `/clientes/app/`). Si ya los proporcionó para esa entrega en la conversación, usarlos sin volver a preguntar.
- No deducir el destino del nombre de las carpetas del repositorio ni asumir que una URL de una entrega anterior sigue vigente. La pregunta es para conocer el destino técnico, no para pedir aprobación adicional sobre trabajo ya autorizado.
- Configurar las rutas de recursos y navegación para el destino confirmado, reconstruir el build y comprobar CSS, JavaScript, fuentes e imágenes sirviéndolo en ese path antes de empaquetar. Incluir instrucciones de extracción con el destino correcto.
