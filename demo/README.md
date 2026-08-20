# Portal público DEMO

Este directorio es el BFF público. El navegador solo llama a `demo/api.php`;
la clave de FacturaScripts nunca se entrega al navegador.

La documentación completa del API, con ejemplos `curl` y PHP, está en
[API.md](API.md).

No se configura token API, URL de activación, URL de login ni nombre de base en
Settings ni en Docker. El BFF lee `FS_DB_NAME` y `FS_API_KEY` del `config.php`
de la instancia master y llama al endpoint `/api/3` de la misma aplicación. Las
URLs públicas se derivan de `default.site_url`. En Settings solo queda
`demo_mail_limit`.

En cada llamada interna el BFF envía `X-RUC: <FS_DB_NAME>` y
`X-Auth-Token: <FS_API_KEY>`. La misma `FS_API_KEY` es usada por la consulta al
SRI; no existe una segunda clave de servicio. El proxy debe aplicar el límite
de tráfico y, si termina TLS, enviar `X-Forwarded-Proto`.

La API de FacturaScripts debe estar activa y `FS_API_KEY` debe contener la clave
vigente del sistema. Restrinja en el proxy el acceso público directo a `/api/3`:
el navegador debe utilizar exclusivamente `/demo/api.php`.
