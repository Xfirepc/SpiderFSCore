<?php
declare(strict_types=1);

use FacturaScripts\Plugins\SpiderBuilder\Lib\DemoPortalConfig;

require_once __DIR__ . '/../Plugins/SpiderBuilder/Lib/DemoPortalConfig.php';

session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    $raw = trim((string)file_get_contents('php://input'));
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['error' => 'Solicitud JSON no válida'], 400);
    }
    return $data;
}

function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function enforceCsrf(): void
{
    $received = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['demo_csrf'] ?? '');
    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        respond(['error' => 'La sesión del formulario caducó. Recarga la página.'], 403);
    }
}

function enforceIpRate(string $ip): void
{
    $directory = sys_get_temp_dir() . '/spiderbuilder-demo-rate';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        respond(['error' => 'No se pudo validar el límite de solicitudes'], 503);
    }
    $file = $directory . '/' . hash('sha256', $ip) . '.json';
    $handle = fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        respond(['error' => 'No se pudo validar el límite de solicitudes'], 503);
    }
    $contents = stream_get_contents($handle);
    $attempts = json_decode((string)$contents, true);
    $attempts = is_array($attempts) ? $attempts : [];
    $cutoff = time() - 3600;
    $attempts = array_values(array_filter($attempts, static function ($timestamp) use ($cutoff): bool {
        return is_int($timestamp) && $timestamp >= $cutoff;
    }));
    if (count($attempts) >= 5) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(['error' => 'Alcanzaste el límite de intentos. Prueba nuevamente en una hora.'], 429);
    }
    $attempts[] = time();
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function hostRequest(string $method, string $path, array $data = [], string $idempotency = ''): void
{
    $master = masterApiConfig();
    // La API usa el origen privado configurado, nunca un Host enviado por el cliente.
    $baseUrl = $master['api_url'];
    // Las consultas de progreso y la activación pueden compartir sesión.
    // No retener su bloqueo mientras otra petición prepara la base de datos.
    session_write_close();

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-RUC: ' . $master['database'],
        'X-Auth-Token: ' . $master['api_key'],
    ];
    if ($idempotency !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotency;
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
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error !== '' || $raw === false) {
        respond(['error' => 'No pudimos contactar el servicio. Reintenta con la misma solicitud.'], 503);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        respond(['error' => 'El servicio devolvió una respuesta no válida'], 502);
    }
    if ($status >= 200 && $status < 300 && isset($decoded['data']['entry_token'])) {
        session_start();
        session_regenerate_id(true);
        $_SESSION['demo_entry_token'] = $decoded['data']['entry_token'];
        unset($decoded['data']['entry_token']);
        $decoded['data']['continue_url'] = './api.php?action=enter';
        session_write_close();
    }
    respond($decoded, $status > 0 ? $status : 502);
}

function masterApiConfig(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }

    $contents = (string)file_get_contents(__DIR__ . '/../config.php');
    try {
        $config = DemoPortalConfig::fromContents($contents);
        $config['api_url'] = DemoPortalConfig::internalApiUrl($contents);
    } catch (RuntimeException $exception) {
        error_log('[demo-portal] ' . $exception->getMessage());
        respond(['error' => 'El portal de pruebas no está disponible en este momento.'], 503);
    }

    return $config;
}

$action = (string)($_GET['action'] ?? '');
if ($action === 'licenses' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    hostRequest('GET', 'demoLicenses');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido'], 405);
}
enforceCsrf();
if ($action === 'enter') {
    $token = (string)($_SESSION['demo_entry_token'] ?? '');
    unset($_SESSION['demo_entry_token']);
    session_write_close();
    if ($token === '') {
        respond(['error' => 'El acceso inicial ya fue utilizado. Inicia sesión normalmente.'], 403);
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Entrando a SpiderCode</title><body><p>Estamos abriendo tu espacio…</p><form id="demo-entry" method="post" action="/login?action=demo-entry"><input type="hidden" name="entry_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"><button type="submit">Continuar</button></form><script src="./enter.js" defer></script></body></html>';
    exit;
}
$data = input();
$idempotency = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

switch ($action) {
    case 'register':
        $ip = clientIp();
        enforceIpRate($ip);
        unset($data['captcha']);
        hostRequest('POST', 'demoRegistrations', $data, $idempotency);
        break;

    case 'activate':
        if (empty($_SESSION['demo_entry_binding'])) {
            $_SESSION['demo_entry_binding'] = hash('sha256', random_bytes(32));
        }
        $data['entry_binding'] = $_SESSION['demo_entry_binding'];
        hostRequest('POST', 'demoRegistrations/activate', $data, $idempotency);
        break;

    case 'status':
        $publicId = (string)($data['public_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
            respond(['error' => 'Solicitud no válida'], 422);
        }
        hostRequest('GET', 'demoRegistrations/' . rawurlencode($publicId));
        break;

    case 'resend':
        $publicId = (string)($data['public_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
            respond(['error' => 'Solicitud no válida'], 422);
        }
        hostRequest('POST', 'demoRegistrations/' . rawurlencode($publicId) . '/resend');
        break;

    default:
        respond(['error' => 'Ruta no encontrada'], 404);
}
