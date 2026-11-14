# Recuperación de contraseña

Desde `/login?action=recover-password` se ofrece recuperar por correo o consultar
la ayuda para contactar al administrador. La ayuda no crea solicitudes ni envía
avisos administrativos.

La recuperación utiliza el SMTP de la empresa y `default.site_url` como URL pública
completa, incluida la subruta. En producción debe ser HTTPS. No se deriva el dominio
de cabeceras enviadas por el visitante. Los correos no llevan CC/BCC ni se archivan.
La respuesta pública finaliza antes de consultar la cuenta y enviar SMTP en PHP-FPM.

El enlace dura 30 minutos, admite un solo uso y sustituye al enlace anterior.
Cambiar el correo, cambiar la contraseña o deshabilitar la cuenta impide usarlo.
Los cambios se guardan con bloqueo de usuario y token dentro de una transacción;
el nuevo `logkey` invalida las sesiones anteriores. No se inicia sesión automáticamente.

El token viaja en el fragmento `#token=…`; JavaScript lo pasa al formulario POST y
lo retira de la barra de direcciones. La pantalla no carga analítica ni scripts
externos. Los límites son 3 solicitudes por cuenta y 10 por IP cada 15 minutos,
con contadores separados por base de datos y protegidos con `flock`.

`proxy.php` admite un selector explícito `recovery_ruc` únicamente en el login y
las acciones de recuperación, conservando la comprobación de instalación activa.
Un selector vacío identifica la instalación principal. Las demás rutas mantienen
la prioridad habitual de la cookie. Se retira el antiguo cambio público que pedía
la contraseña de la base de datos; `EditUser` sigue disponible para el administrador.

## Pruebas aisladas

```bash
Test/Security/run-recovery-integration.sh
```

Usa MariaDB y Mailpit en contenedores sin red externa. No carga `config.php`,
`Test/bootstrap.php` ni conecta a las bases de las instancias. Comprueba el esquema
XML real, recuperación por usuario/correo, confirmación y longitud, invalidación,
consumo único, rollback, CSRF, selector RUC, aislamiento entre bases, límites,
SMTP fallido, ausencia de CC/BCC e historial, y concurrencia entre procesos.

Para conservar el HTML renderizado y revisarlo en móvil/escritorio:

```bash
RECOVERY_VISUAL_OUTPUT=/tmp/recovery-visual Test/Security/run-recovery-integration.sh
```

## Activación

Reconstruir desde AdminPlugins después de actualizar Core y las personalizaciones
de Login en SpiderBuilder y SpiderThemeClassic. El modelo crea `password_resets`
mediante el esquema XML de FacturaScripts en cada base al usar la recuperación;
lo hace antes de iniciar la transacción. No editar `Dinamic` manualmente.

Conservar esta personalización al actualizar FacturaScripts: controlador Login,
servicio `Security/PasswordRecovery`, modelo y tabla PasswordReset, correo
PasswordRecoveryMail, vistas Login/Recovery y el selector específico de `proxy.php`.
