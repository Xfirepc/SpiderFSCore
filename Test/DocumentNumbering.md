# Numeración de documentos

`BusinessDocumentCode` lee únicamente número, ejercicio, fecha y hora. Conserva
la ventana de 1.000 documentos si se usan huecos y lee uno en caso contrario.
La normalización de fechas y las reglas del contador son las del algoritmo previo.

## Índices para facturas de clientes

La migración explícita añade a `facturascli` la columna calculada virtual
`fs_numero_orden` y los índices no únicos `idx_fs_numero_ejercicio`
(empresa/serie/ejercicio/orden) e `idx_fs_numero_serie` (empresa/serie/orden).
No sustituye el número original ni sus índices únicos.

La expresión extrae el prefijo numérico antes de convertirlo. Conserva el orden
de `CAST(numero AS UNSIGNED)` y evita errores de truncamiento en modo estricto
para números manuales como `12A`, `+12`, `12.5` y `1e2`, admitidos por el validador
heredado. El ORM omite columnas calculadas en validación de obligatoriedad,
INSERT y UPDATE, también cuando se copia un documento.

La disponibilidad se comprueba por metadatos durante la petición. Sin columna
e índices válidos se usa la lectura ligera con CAST. No se ejecuta DDL desde
las ventas. Los demás modelos de documentos conservan esa ruta compatible.

## Mantenimiento por base

Desde `html`, indicando expresamente la configuración de una base:

```bash
php Tools/numbering-schema.php --config=/ruta/tenant.php
php Tools/numbering-schema.php --config=/ruta/tenant.php --apply
php Tools/numbering-schema.php --config=/ruta/tenant.php --rollback
```

Sin acción solo inspecciona. `--root=/ruta/html` permite indicar la carpeta si
la configuración no define FS_FOLDER. La herramienta es exclusiva de CLI y no
imprime credenciales. No elige una configuración automáticamente ni modifica
otras bases.

La instalación está validada con MariaDB 10.7/InnoDB: añade la columna con
`ALGORITHM=INSTANT, LOCK=NONE` y construye los índices con
`ALGORITHM=INPLACE, LOCK=NONE`. No mantiene un bloqueo de escritura durante la
validación ni la construcción de índices. Obtiene el bloqueo de esquema de
DbUpdater y limita la espera de metadatos a dos segundos, restaurando la sesión
al terminar. Las instalaciones anteriores con la misma columna `STORED` o
`PERSISTENT` se reconocen y conservan, sin reconstruirlas.

La retirada requiere una ventana de mantenimiento y transacciones terminadas:
utiliza un bloqueo de escritura y `ALGORITHM=COPY, LOCK=SHARED`. Ambas acciones
se rechazan dentro de una transacción comercial.

Antes de instalar la columna, publicar el código completo y renovar PHP/OPcache
para que el ORM ya trate los campos calculados como solo lectura. Ejecutar luego
la instalación en línea y comprobar el esquema físico antes de darla por terminada.
Si se retira el código, eliminar primero la optimización con la herramienta nueva.

Se rechazan formatos históricos fuera de 1–12 dígitos y equivalentes numéricos
duplicados por empresa/serie/ejercicio. Requieren revisión manual: no se reparan
ni renumeran documentos. Tampoco se sustituyen columnas o índices ajenos.
La retirada se detiene si otros índices utilizan la columna auxiliar.

Ambas operaciones son idempotentes. La comparación ordinaria con el XML conserva
la columna y los índices auxiliares sin ALTER repetidos. Se invalidan metadatos
del modelo; los números no se almacenan en cachés persistentes.

## Transacciones y pruebas

Con los índices instalados, el guardado incluye contador y documento en la misma
operación de Inventory y respeta transacciones exteriores. Se prepara el modelo
de secuencia antes de abrir una transacción propia. Se bloquea primero la secuencia
y se leen las cuatro columnas con `DbQuery::getForUpdate()`: una caja con un
snapshot anterior ve las facturas confirmadas por la otra. Un fallo de lectura o
persistencia aborta la operación; el contador se revierte si falla la factura.

```bash
python3 Test/run-document-numbering.py all \
  --php-image=spidercode/erp-hotfix-test:20261110 \
  --output=/tmp/pruebas-numeracion
```

Puede indicarse otra imagen mediante `--php-image` o NUMBERING_PHP_IMAGE; necesita
mysqli y PDO MySQL. La validación de esta entrega usa PHP 7.4 y MariaDB 10.7.
El ejecutor crea contenedores sin red externa ni puertos publicados, incluye los
plugins del workspace ignorados por Git y omite config.php/MyFiles reales.
Los contenedores se eliminan también si falla una prueba.

Los modos equivalence, invariants, cli y concurrency/concurrency-no-gaps comprueban
huecos, fechas, ceros iniciales, límites, ejercicios, errores, reversión y dos
cajas con snapshots previos. El fixture aísla numeración y persistencia real del
Core: no emite al SRI ni ejecuta operaciones comerciales reales.
El modo foreign-keys verifica la migración y retirada con relaciones entrantes,
salientes y autorreferencias, conservando líneas, importes y AUTO_INCREMENT.

Los modos benchmark y benchmark-no-gaps usan 60.000 documentos sintéticos, dos
lecturas de calentamiento y cinco medidas. Registran primera lectura, mediana,
memoria, contadores y EXPLAIN del SQL real. `--baseline --baseline-dir=/copia`
permite comparar esos modos y equivalence con los archivos conservados antes
de modificar el workspace, sin sustituir sus cambios locales por git HEAD.

Los resultados son de laboratorio. Antes de publicar deben planificarse migración
por tenant, actualización de PHP/OPcache y validación funcional de TPV, impresión
y SRI. Conservar estas personalizaciones del Core al actualizar FacturaScripts.

Referencias: [columnas calculadas](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns)
y [FOR UPDATE](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update).
