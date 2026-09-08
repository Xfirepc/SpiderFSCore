# Instrucciones del proyecto

Estas instrucciones se aplican a Core y a todos los plugins bajo `Plugins/`.

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
