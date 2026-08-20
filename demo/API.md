# API de aprovisionamiento DEMO

Esta API permite consultar los productos DEMO, registrar una empresa, verificar
el correo y aprovisionar un tenant. Está implementada sobre la API v3 de
FacturaScripts.

El portal incluido en `demo/` actúa como BFF: selecciona la instancia master
mediante `X-RUC` y autentica la llamada interna con `X-Auth-Token`. Ninguna de
estas credenciales llega al navegador.

## URL base y autenticación

```text
https://HOST-FACTURASCRIPTS/api/3
```

Todas las peticiones directas al API envían la base de datos master y la clave
general configurada en FacturaScripts:

```http
X-RUC: base_de_datos_master
X-Auth-Token: CLAVE_FS_API_KEY
Accept: application/json
```

Las peticiones con JSON incluyen también:

```http
Content-Type: application/json
```

El RUC de la empresa que se registra se envía en el cuerpo JSON; no debe usarse
como valor de `X-RUC` en este flujo. La clave tiene los permisos generales de
`FS_API_KEY`, por lo que el proxy debe impedir que clientes públicos accedan
directamente a `/api/3`. El navegador llama únicamente a `/demo/api.php`.

## Configuración requerida

En **SpiderBuilder → APIs → Aprovisionamiento DEMO** solo se configura:

| Campo | Ejemplo | Uso |
| --- | --- | --- |
| `demo_mail_limit` | `3` | Máximo de correos por RUC o email durante una hora. |

La instancia master debe definir en `config.php`:

```php
define('FS_DB_NAME', 'base_de_datos_master');
define('FS_API_KEY', 'clave-api-vigente');
```

`FS_API_KEY` autentica tanto las llamadas internas del BFF como la consulta al
SRI; no se duplica en Settings, Docker ni variables de entorno. Las URLs se
generan automáticamente desde `default.site_url`:

- Activación: `https://tu-dominio.com/demo/index.php`.
- Acceso: `https://tu-dominio.com/login?action=check-ruc&ruc=...`.
- API interna: `https://tu-dominio.com/api/3`.
- Host: la base actual `FS_DB_NAME`, que debe ser la base host.

El BFF lee la clave solo en el servidor y llama a esta misma instalación usando
`X-RUC` y `X-Auth-Token`. El control principal de tráfico/antiabuso se realiza
en el proxy; el portal mantiene además CSRF, idempotencia y un límite local
básico.

También deben estar configurados el correo saliente, los datos MySQL usados por
SpiderBuilder y el schema base `MyFiles/SpiderBuilder/main_db.sql`.

El recurso debe ejecutarse en la base master. Si `X-RUC` selecciona una base
tenant, el recurso responde `403`; una cookie `ruc` también se rechaza.

## Resumen de endpoints

| Método | Ruta | Descripción |
| --- | --- | --- |
| `GET` | `/demoLicenses` | Lista licencias DEMO activas. |
| `POST` | `/demoRegistrations` | Valida RUC y envía el enlace de activación. |
| `POST` | `/demoRegistrations/activate` | Verifica el token y crea el tenant. |
| `GET` | `/demoRegistrations/{public_id}` | Consulta el estado seguro. |
| `POST` | `/demoRegistrations/{public_id}/resend` | Rota y reenvía el enlace. |

Las respuestas normales usan este sobre:

```json
{
  "data": {}
}
```

Los errores del recurso usan:

```json
{
  "error": "Descripción segura del error"
}
```

Los errores de autenticación o permisos generados por FacturaScripts usan
`{"status":"error","message":"..."}`.

## 1. Consultar licencias

```http
GET /api/3/demoLicenses
```

Ejemplo:

```bash
curl --fail-with-body \
  -H "X-RUC: ${SPIDER_HOST_DB}" \
  -H "X-Auth-Token: ${SPIDER_API_KEY}" \
  -H "Accept: application/json" \
  "${SPIDER_API_URL}/demoLicenses"
```

Respuesta `200`:

```json
{
  "data": [
    {
      "id": 12,
      "name": "SpiderCode Comercial DEMO",
      "description": "Producto de evaluación para empresas comerciales",
      "modules": [
        {"code": "COMERCIAL", "name": "Comercial"},
        {"code": "CONTABILIDAD", "name": "Contabilidad"}
      ]
    }
  ]
}
```

Solo aparecen licencias con `active=true`, `type=demo`, roles existentes y al
menos una página válida por rol. `modules` se deriva de los roles asociados a
la licencia. Las referencias a páginas obsoletas se omiten y se muestran como
advertencias administrativas; un rol queda inválido si no conserva ninguna
página efectiva.

## 2. Crear un registro

```http
POST /api/3/demoRegistrations
Idempotency-Key: register-UUID
```

Cuerpo:

```json
{
  "ruc": "1790012345001",
  "email": "ventas@empresa.com",
  "phone": "+593 99 000 0000",
  "license_id": 12,
  "username": "ventas"
}
```

| Campo | Requerido | Validación |
| --- | --- | --- |
| `ruc` | Sí | Exactamente 13 dígitos y existente en el servicio fiscal. |
| `email` | Sí | Email válido, máximo 100 caracteres. |
| `phone` | Sí | Entre 7 y 30 caracteres; admite números, espacios, `+`, `-`, puntos y paréntesis. |
| `license_id` | Sí | ID devuelto por `demoLicenses`. |
| `username` | No | Preferencia inicial, máximo 50 caracteres. Se confirma durante la activación. |

Respuesta `200`:

```json
{
  "data": {
    "public_id": "9c55fbe7c96f4bdab324cdf4b7c21f41",
    "status": "pending_email",
    "step": "email_pending",
    "email": "ve****@empresa.com",
    "token_expires_at": "2026-07-30 14:25:00",
    "suggested_username": "ventas"
  }
}
```

El token no se devuelve en la API. Se envía al correo dentro de un enlace válido
durante 24 horas.

### Idempotencia del registro

`Idempotency-Key` es obligatorio. Debe contener entre 8 y 128 caracteres usando
solo letras, números, `.`, `_`, `:`, o `-`.

- Guarde la clave antes de enviar la petición.
- Si hay timeout o se pierde la conexión, repita exactamente el mismo cuerpo y
  la misma clave.
- La misma clave con otros datos devuelve `409`.
- Una solicitud exitosa repetida devuelve el registro existente y no crea otro.

Ejemplo:

```bash
export SPIDER_API_URL="https://host.example.com/api/3"
# Credenciales server-to-server de la instancia master. El RUC real va en JSON.
export SPIDER_HOST_DB="base_de_datos_master"
export SPIDER_API_KEY="clave-api-vigente"
export REGISTER_KEY="register-$(uuidgen)"

curl --fail-with-body \
  -X POST \
  -H "X-RUC: ${SPIDER_HOST_DB}" \
  -H "X-Auth-Token: ${SPIDER_API_KEY}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: ${REGISTER_KEY}" \
  --data '{
    "ruc": "1790012345001",
    "email": "ventas@empresa.com",
    "phone": "+593 99 000 0000",
    "license_id": 12,
    "username": "ventas"
  }' \
  "${SPIDER_API_URL}/demoRegistrations"
```

## 3. Consultar el estado

```http
GET /api/3/demoRegistrations/{public_id}
```

Ejemplo:

```bash
curl --fail-with-body \
  -H "X-RUC: ${SPIDER_HOST_DB}" \
  -H "X-Auth-Token: ${SPIDER_API_KEY}" \
  -H "Accept: application/json" \
  "${SPIDER_API_URL}/demoRegistrations/9c55fbe7c96f4bdab324cdf4b7c21f41"
```

Mientras espera la activación devuelve los mismos campos seguros del registro.
Cuando está completado añade la instalación:

```json
{
  "data": {
    "public_id": "9c55fbe7c96f4bdab324cdf4b7c21f41",
    "status": "completed",
    "step": "deployed",
    "email": "ve****@empresa.com",
    "token_expires_at": "2026-07-30 14:25:00",
    "suggested_username": "ventas",
    "installation": {
      "id": 48,
      "ruc": "1790012345001",
      "name": "EMPRESA DE EJEMPLO S.A.",
      "mode": "demo"
    },
    "trial_ends_at": "2026-08-29 14:29:10",
    "username": "ventas",
    "login_url": "https://app.example.com/login?ruc=1790012345001"
  }
}
```

Estados posibles:

| Estado | Significado |
| --- | --- |
| `pending_email` | Enlace enviado, pendiente de activación. |
| `provisioning` | Aprovisionamiento en curso. |
| `completed` | Tenant creado y activado. |
| `failed` | Fallo recuperable; se puede reintentar la misma activación. |
| `expired` | Enlace o solicitud caducada. |
| `released` | Registro liberado por un sysadmin. |

Pasos posibles: `email_pending`, `email_verified`, `installation_created`,
`license_configured`, `menu_configured`, `database_ready` y `deployed`.

## 4. Reenviar el enlace

```http
POST /api/3/demoRegistrations/{public_id}/resend
```

No requiere cuerpo:

```bash
curl --fail-with-body \
  -X POST \
  -H "X-RUC: ${SPIDER_HOST_DB}" \
  -H "X-Auth-Token: ${SPIDER_API_KEY}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{}' \
  "${SPIDER_API_URL}/demoRegistrations/9c55fbe7c96f4bdab324cdf4b7c21f41/resend"
```

El enlace anterior queda invalidado y el nuevo vuelve a ser válido durante 24
horas. Se puede reenviar un registro `pending_email` y también recuperar un
registro `failed` que falló justo después de verificar el correo, siempre que
todavía no exista una instalación parcial. El límite host predeterminado es de
3 correos por RUC o email durante una hora.

## 5. Activar y crear el tenant

```http
POST /api/3/demoRegistrations/activate
Idempotency-Key: activate-UUID
```

Cuerpo:

```json
{
  "token": "TOKEN_RECIBIDO_EN_EL_ENLACE",
  "username": "ventas",
  "password": "Una-clave-segura-de-12-o-mas"
}
```

| Campo | Requerido | Validación |
| --- | --- | --- |
| `token` | Sí | Token URL-safe recibido por correo. |
| `username` | No | De 3 a 50 caracteres: letras, números, `_`, `@`, `+`, `.`, `-`. No puede ser `admin`. |
| `password` | Sí | Entre 12 y 72 caracteres. |

Si `username` se omite se usa la sugerencia del registro. La contraseña no se
guarda en texto claro ni se envía por correo.

La operación es síncrona y puede tardar hasta 600 segundos. El cliente debe usar
un timeout igual o superior. Si pierde la respuesta debe repetir el mismo token,
usuario, contraseña e `Idempotency-Key`.

Respuesta `200`:

```json
{
  "data": {
    "installation": {
      "id": 48,
      "ruc": "1790012345001",
      "name": "EMPRESA DE EJEMPLO S.A.",
      "mode": "demo"
    },
    "trial_ends_at": "2026-08-29 14:29:10",
    "username": "ventas",
    "login_url": "https://app.example.com/login?ruc=1790012345001"
  }
}
```

Mientras el token siga vigente, la misma activación completada y repetida con
la misma clave devuelve el resultado existente. El token con otra clave
devuelve `409`.

## Flujo recomendado del backend/BFF

1. Consultar `demoLicenses` y mostrarlas al usuario.
2. Aplicar el límite por IP en el BFF y en el proxy.
3. Generar y persistir una clave de idempotencia para el registro.
4. Enviar `demoRegistrations`.
5. Mostrar el email enmascarado y guardar `public_id`.
6. El usuario abre el enlace de su correo.
7. Consultar el estado usando `public_id`.
8. Solicitar usuario y contraseña.
9. Generar y persistir otra clave de idempotencia para la activación.
10. Ejecutar `activate` con timeout de 600 segundos.
11. Mostrar `login_url`, `username` y `trial_ends_at`.

## Configurar una licencia DEMO

En el host, cree una `SBLicense` con `type=demo` y `active=true`, asocie uno o
más roles en la pestaña **Roles** y revise que cada `RoleAccess` apunte a una
página existente. Las páginas con `showonmenu=false` también pueden formar
parte del módulo: serán accesibles por URL, pero no aparecerán en el menú.

La licencia solo se publica cuando todos sus roles existen y tienen páginas
válidas. Tras corregir roles o permisos, vuelva a activar/guardar la licencia
y use la sincronización de tenants en modo `license` para propagar el cambio;
los tenants en modo `manual` no se sobrescriben.

El BFF incluido expone al navegador `/demo/api.php?action=licenses`,
`register`, `status`, `resend` y `activate`. Además aplica CSRF y un límite
predeterminado de 5 registros por IP y hora; el proxy debe completar la
protección y limitación de tráfico.

## Errores habituales

| HTTP | Causa habitual |
| --- | --- |
| `400` | JSON inválido o `Idempotency-Key` ausente/no válido. |
| `401` | Clave API ausente, deshabilitada o incorrecta. |
| `403` | Permiso insuficiente, petición ejecutada en un tenant o cookie `ruc`. |
| `404` | Token o `public_id` no encontrados. |
| `405` | Método o ruta no permitidos. |
| `409` | RUC ya usado, solicitud vigente, token reutilizado, idempotencia con otros datos o licencia modificada. |
| `410` | Token caducado o registro liberado. |
| `422` | RUC, email, teléfono, licencia, usuario o contraseña inválidos. |
| `429` | Límite de correos alcanzado. |
| `500` | Error interno no recuperable. |
| `503` | SRI, correo o aprovisionamiento temporalmente no disponibles. |

No se debe decidir la lógica únicamente por el texto del error. Use primero el
código HTTP y conserve el mensaje para mostrarlo o registrarlo de forma segura.

## Ejemplo PHP para consumir el API

```php
<?php

function spiderDemoRequest(
    string $method,
    string $path,
    string $hostDatabase,
    string $apiKey,
    array $body = [],
    string $idempotencyKey = ''
): array {
    $baseUrl = 'https://host.example.com/api/3';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-RUC: ' . $hostDatabase,
        'X-Auth-Token: ' . $apiKey,
    ];
    if ($idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $curl = curl_init($baseUrl . '/' . ltrim($path, '/'));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($method !== 'GET') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error !== '') {
        throw new RuntimeException($error);
    }
    $response = json_decode((string)$raw, true);
    if (!is_array($response)) {
        throw new RuntimeException('Respuesta JSON no válida');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException($response['error'] ?? $response['message'] ?? 'Error del API');
    }

    return $response['data'] ?? [];
}
```

## Reglas operativas importantes

- Cada RUC puede completar una sola prueba histórica.
- Una solicitud pendiente caducada puede reemplazarse.
- La licencia debe continuar activa, ser DEMO y no haber cambiado antes de la
  activación.
- El token y la contraseña nunca aparecen en respuestas de estado.
- El correo final incluye usuario, URL y vencimiento, pero nunca la contraseña.
- Un fallo del correo de bienvenida no elimina el tenant.
- Al vencer el mes, el tenant se suspende con `trial_expired`; su base y
  configuración se conservan.
