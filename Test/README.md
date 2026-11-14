# Tests
Usamos phpunit para ejecutar los tests unitarios. Abra un terminal y ejecute el siguiente comando:
```bash
vendor/bin/phpunit
```

Para comprobar que los diálogos de Subtotal y Neto respetan el bloqueo del precio
unitario en el formulario común de ventas:

```bash
node Test/Browser/SalesLinePrice.cjs
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result Plugins/SpiderPermisos/Test
```

La prueba usa PHP, Node y Chromium sin cargar `config.php`, conectar a la base
de datos ni enviar peticiones de red. Ejecuta el JavaScript de la plantilla real;
las pruebas de SpiderPermisos verifican los envíos manipulados en el servidor.

Para comprobar la privacidad de las pantallas de error sin cargar `config.php`,
conectar a la base de datos ni reconstruir plugins:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Test/Core/ErrorPageTest.php
```

La presentación compartida está en `Core/ErrorPage.php` y
`Core/View/Error/Standalone.html.php`. Solo una identidad autenticada en
`Session::get('user')`, habilitada y administradora puede recibir detalles técnicos;
`FS_DEBUG` y las cookies por sí solos no conceden ese acceso. Antes de autenticar al
usuario, la respuesta siempre es la pública. La misma restricción se aplica al
HTML, a JSON, a texto plano y al enlace de soporte.

Las excepciones de `DefaultError` y los errores fatales conservan el diagnóstico en
`MyFiles/crash_<hash>.json`; la referencia visible corresponde a los primeros doce
caracteres de ese hash. Los otros controladores conservan su política de registro.
La vista funciona sin Twig, Bootstrap ni peticiones a servicios externos y respeta
la preferencia `spider-theme`.

También cubre páginas no encontradas (404), acceso denegado (403) y el aviso de
sistema ya instalado (403), con mensajes e iconos propios. `Core/Base/Controller.php`
dirige las selecciones antiguas de `Error/AccessDenied` al controlador común antes
de cargar el menú, de modo que los permisos sobre registros también devuelvan 403.
Los controladores específicos de la API mantienen su contrato JSON actual.

Esta es una personalización del Core: al actualizar FacturaScripts, conservar
también la integración en `Core/Template/ErrorController.php`,
`Core/Error/DefaultError.php`, `Core/Error/PageNotFound.php`,
`Core/Error/AccessDenied.php`, `Core/Error/AlreadyInstalled.php`,
`Core/Base/Controller.php`, `Core/CrashReport.php` y `Core/Kernel.php`.

## Numeración de documentos

La [guía de numeración](DocumentNumbering.md) describe la lectura proyectada,
la migración explícita del orden numérico de `facturascli`, su reversión y las
pruebas en MariaDB desechable. El ejecutor `Test/run-document-numbering.py`
incluye equivalencia con el algoritmo anterior, errores, dos cajas simultáneas
y una comparación de rendimiento con 60.000 documentos sintéticos. No carga
`config.php`, `Test/bootstrap.php` ni bases de instancias.

## Índices secundarios de rendimiento

`Core/Table/logs.xml` y `Core/Table/facturascli.xml` declaran los índices de canal
y saldo por cliente. `MysqlTableIndexes` añade solo los que faltan, reconoce
BTREE equivalentes y conserva relaciones, unicidad y transacciones comerciales.
Un índice fallido queda pendiente para reintentar; no invalida todo el esquema.

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Test/Core/MysqlTableIndexesTest.php
python3 Test/run-table-indexes.py
SB_DEMO_TEST_PERFORMANCE_SCHEMA_ONLY=1 bash Plugins/SpiderBuilder/Test/run-demo-integration.sh
```

Las pruebas se ejecutan con datos ficticios en MariaDB aislado. Cubren creación
y actualización desde XML, duplicados y NULL, protección de relaciones, espera
de metadatos, rollback comercial y publicación/importación del esquema padre.
InnoDB puede reemplazar un índice auxiliar automático de una FK por el nuevo
compuesto. Para revertir ese caso, reponer primero el auxiliar y después retirar
el compuesto; la relación permanece vigente durante ambas operaciones.
# Selectores de trazabilidad

`node Test/Browser/TraceabilitySelectors.cjs` verifica con Chromium el widget PHP
y los scripts reales, sin configuración ni base de datos. Prueba ambos órdenes de
carga, separación de selectores normales y de lotes, cambios del padre, controles
de solo lectura, ausencia del padre y conservación de opciones. Cada interacción
debe producir una sola solicitud con la acción que le corresponde, sin consultar
una tabla vacía. `TRACEABILITY_SOURCE` permite comparar con la carpeta anterior
del plugin.
