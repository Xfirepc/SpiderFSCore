# Portal público DEMO

Este directorio es el BFF público. El navegador solo llama a `demo/api.php`;
la clave de FacturaScripts nunca se entrega al navegador.

La documentación completa del API, con ejemplos `curl` y PHP, está en
[API.md](API.md).

La maqueta para integrar el agente Hermes, sin instalarlo todavía, está en
[la guía de Hermes](../Plugins/SpiderBuilder/Doc/Hermes.md).

No se configura token API, URL de activación, URL de login ni nombre de base en
Settings ni en Docker. El BFF lee `FS_DB_NAME` y `FS_DEMO_API_KEY` del `config.php`
de la instancia master y llama al endpoint `/api/3` de la misma aplicación. Las
URLs públicas se derivan de `default.site_url`. En Settings solo queda
`demo_mail_limit`.

En cada llamada interna el BFF envía `X-RUC: <FS_DB_NAME>` y
`X-Auth-Token: <FS_DEMO_API_KEY>`. Esta clave autentica únicamente la API de
FacturaScripts. La consulta al SRI usa la credencial del proveedor encapsulada
en `FiscalNum` y no requiere configuración por tenant. El proxy debe aplicar el
límite de tráfico y, si termina TLS, enviar `X-Forwarded-Proto`.

La API debe habilitarse en `/EditSettings`. Crear una clave exclusiva del portal,
habilitada y sin acceso total, con GET de `demoLicenses` y GET/POST de
`demoRegistrations`; no conceder ningún otro recurso ni PUT/PATCH/DELETE.
Guardar ese token en `FS_DEMO_API_KEY` antes de actualizar el portal. Si falta,
el portal responde 503 y no usa `FS_API_KEY` como alternativa.

Hermes utiliza otra clave, con GET de `demoLicenses` y GET/POST de `demoRequests`;
ese recurso no permite activar. Restrinja en el proxy el acceso público directo a `/api/3`:
el navegador debe utilizar exclusivamente `/demo/api.php`.
