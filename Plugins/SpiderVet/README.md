# SpiderVet

Plugin de FacturaScripts para gestionar pacientes veterinarios y peluquería de
mascotas, vinculado al catálogo de clientes del sistema. La ficha clínica sigue
las diez secciones de `Ficha_Consulta_Veterinaria_HOPE_v2.docx`.

## Activación

1. Mantener esta carpeta en `Plugins/SpiderVet`.
2. Desde **Administrador → Plugins**, activar **SpiderVet** y completar la
   reconstrucción habitual de FacturaScripts. La instalación crea las tablas
   propias y el rol **SpiderVet**; no modifica documentos comerciales.
3. Asignar el rol SpiderVet a los usuarios que gestionarán la veterinaria. Los
   permisos se pueden ajustar con las herramientas habituales del sistema.
4. En instancias con menú administrado por licencia o configuración manual,
   habilitar las páginas del módulo en su configuración de menú, incluidas las
   páginas de edición e impresión. El plugin conserva esa política de acceso.

La activación corresponde a la instancia elegida. No se habilita automáticamente
en todas las bases de datos que comparten el código. Las actualizaciones respetan
los permisos que ya se hayan personalizado y conservan los registros existentes.

## Uso

- **Cliente → Mascotas:** consultar sus pacientes y agregar una mascota con el
  tutor ya seleccionado. Un cliente puede tener varias mascotas.
- **SpiderVet → Pacientes:** buscar por nombre, tutor, microchip o número de
  historia clínica; filtrar por cliente, especie o estado. La historia clínica
  se numera automáticamente como `SV-000001`.
- **Ficha del paciente:** registrar identificación, antecedentes, alergias,
  medicamentos e indicaciones de baño y manejo. Sus pestañas muestran consultas,
  peluquerías, citas y recepciones.
- **Recepción y agenda:** seleccionar un día, registrar la llegada desde una
  cita o abrir una recepción sin cita. La sala de espera incluye las atenciones
  pendientes de otros días.
- **Citas:** registrar inicio, fin, servicio, profesional, motivo y estado. Los
  estados recibida y completada se sincronizan desde la recepción.
- **Recepción:** abrir una consulta, una peluquería o ambas, según el servicio
  solicitado. Para visitas combinadas, la salida se registra cuando ambos
  servicios están terminados. Cada cita tiene como máximo una recepción.
- **Consulta:** completar antecedentes, examen físico, evaluación clínica,
  indicaciones y seguimiento. Usar **Agregar prueba** y **Agregar tratamiento**
  para registrar tantas filas como se necesiten en sus tablas. El próximo control
  se conserva en la consulta; agendarlo se hace desde **Nueva cita**.
- **Peluquería:** revisar y adaptar las indicaciones de baño para la atención,
  registrar el servicio realizado, incidencias y recomendaciones de entrega.

Las consultas y peluquerías terminadas siguen siendo editables con permisos. Una
consulta conserva los datos del tutor y paciente del día en que se registró; las
modificaciones posteriores de la ficha general no sustituyen esa información.
Cada peluquería conserva sus propias indicaciones de baño. Un control nuevo se
registra como una nueva consulta.

## Impresión

- **Imprimir ficha completa:** identificación, antecedentes, indicaciones de
  baño, observaciones, consultas con todas sus pruebas y tratamientos, y atenciones
  de peluquería.
- **Imprimir consulta:** las diez secciones de la ficha original y espacios para
  la firma del tutor y la firma y sello del veterinario.

Usar **Imprimir / guardar PDF** para imprimir en A4 o guardar un PDF con el
navegador. La cabecera usa la empresa asociada al paciente, incluyendo su logo
cuando está configurado. Se respetan los permisos de lectura y exportación de
cada tipo de registro. Las firmas son espacios impresos; no son firmas digitales.

## Conservación de datos

Un paciente con citas o atenciones no se puede eliminar: se desactiva su ficha.
Las atenciones terminadas se conservan, y una consulta con pruebas o tratamientos
no se puede eliminar. Los borradores sin registros dependientes pueden eliminarse
con permiso. Los datos desconocidos de examen físico permanecen vacíos.

El plugin no genera facturas ni cobros; se utilizan los flujos comerciales
existentes. No crea asientos contables ni requiere activar contabilidad.

## Validación

Desde la raíz de FacturaScripts:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Plugins/SpiderVet/Test/ValidationTest.php
bash Plugins/SpiderVet/Test/run-integration.sh
SPIDERVET_TEST_DB_IMAGE=mariadb:10.7 bash Plugins/SpiderVet/Test/run-integration.sh
```

Las pruebas de integración necesitan Docker, `mysql:8.0` o `mariadb:10.7`, y la
imagen `main_spidercodeapp-php:latest`. Se puede cambiar esta última con
`SPIDERVET_TEST_PHP_IMAGE`. Crean una base desechable, aíslan la red y montan el
código como solo lectura. No cargan `config.php` ni se conectan a las instancias.

Comprueban instalación repetida, claves foráneas con intercalaciones diferentes,
varias mascotas por tutor, visitas combinadas, consultas editables, historial,
búsqueda, controles de acceso, protección de formularios e impresión completa.
