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
