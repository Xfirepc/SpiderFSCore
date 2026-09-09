# Tests
Usamos phpunit para ejecutar los tests unitarios. Abra un terminal y ejecute el siguiente comando:
```bash
vendor/bin/phpunit
```

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
