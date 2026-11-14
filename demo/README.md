# Portal público DEMO

Este directorio es el BFF público. El navegador solo llama a `demo/api.php`;
la clave de FacturaScripts nunca se entrega al navegador.

La documentación completa del API, con ejemplos `curl` y PHP, está en
[API.md](API.md).

La maqueta para integrar el agente Hermes, sin instalarlo todavía, está en
[la guía de Hermes](../Plugins/SpiderBuilder/Doc/Hermes.md).

No se configura token API, URL de activación, URL de login ni nombre de base en
Settings ni en Docker. El BFF lee `FS_DB_NAME`, `FS_DEMO_API_KEY` y `FS_INTERNAL_URI`
del `config.php` del maestro y llama al endpoint `/api/3` mediante ese origen
interno. Nunca deriva el destino de las cabeceras Host del navegador. Las
URLs públicas se derivan de `default.site_url`. En Settings solo queda
`demo_mail_limit`.

En cada llamada interna el BFF envía `X-RUC: <FS_DB_NAME>` y
`X-Auth-Token: <FS_DEMO_API_KEY>`. Esta clave autentica únicamente la API de
FacturaScripts. La consulta al SRI usa la credencial del proveedor encapsulada
en `FiscalNum` y no requiere configuración por tenant. El proxy debe aplicar el
límite de tráfico y, si termina TLS, enviar `X-Forwarded-Proto`.

Reconstruir desde `/AdminPlugins` en el maestro prepara la API, las licencias y
una clave exclusiva del portal en `FS_DEMO_API_KEY`, asociada a un usuario técnico
sin administración. Solo permite GET de `demoLicenses` y GET/POST de
`demoRegistrations`. Una clave existente debe estar habilitada y restringida; no
se reutiliza `FS_API_KEY`. El catálogo se abre en `/demo/index.php`.

Tras confirmar el correo y elegir contraseña, el BFF guarda en sesión el token de
entrada inicial y envía un formulario POST a Login. El cliente confirma los datos
en `/Wizard` y comienza con caja y configuración propias, sin productos ni stock.
Los tokens de entrada no se entregan en consultas públicas de progreso.

Hermes utiliza otra clave, con GET de `demoLicenses` y GET/POST de `demoRequests`;
ese recurso no permite activar. Restrinja en el proxy el acceso público directo a `/api/3`:
el navegador debe utilizar exclusivamente `/demo/api.php`.

Las dos claves restringidas son **Portal DEMO** (`FS_DEMO_API_KEY` en el master)
y **Hermes** (`SPIDER_DEMO_API_TOKEN` en el perfil privado del agente).
`FS_API_KEY` es una tercera credencial opcional para acceso general a la API:
el Core le concede acceso total. No se necesita para este flujo ni se utiliza
como token del agente. Con `default.enable_api=true`, las claves restringidas
funcionan sin definir `FS_API_KEY`.
