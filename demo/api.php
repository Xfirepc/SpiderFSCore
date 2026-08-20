<?php
declare(strict_types=1);

session_set_cookie_params([
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
    $received = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
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
    $baseUrl = demoApiUrl();
    $master = masterApiConfig();

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
    respond($decoded, $status > 0 ? $status : 502);
}

function masterApiConfig(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }

    $contents = (string)file_get_contents(__DIR__ . '/../config.php');
    $config = [];
    foreach (['database' => 'FS_DB_NAME', 'api_key' => 'FS_API_KEY'] as $key => $constant) {
        $pattern = '~define\(\s*([\'\"])' . preg_quote($constant, '~')
            . '\1\s*,\s*([\'\"])(.*?)\2\s*\)\s*;~s';
        if (!preg_match($pattern, $contents, $matches) || trim($matches[3]) === '') {
            respond(['error' => 'La instancia master no está configurada'], 503);
        }
        $config[$key] = stripcslashes($matches[3]);
    }

    return $config;
}

function demoApiUrl(): string
{
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($forwarded === 'https') {
            $scheme = 'https';
        }
    }

    $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    return $scheme . '://' . $host . '/api/3';
}

$action = (string)($_GET['action'] ?? '');
if ($action === 'licenses' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    hostRequest('GET', 'demoLicenses');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido'], 405);
}
enforceCsrf();
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
