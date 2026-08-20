<?php

function getConfigFile()
{
    $ruc = getTenantSelector();
    if ($ruc === null) {
        return __DIR__ . '/config.php';
    }

    if (!preg_match('/^[A-Za-z0-9_\-]{1,30}$/', $ruc)) {
        renderTenantSelectionError(400);
        exit;
    }

    $configPath = __DIR__ . '/Config/config_' . $ruc . '.php';
    if (!is_file($configPath)) {
        renderTenantSelectionError(404);
        exit;
    }

    enforceActiveTenant($ruc);
    return $configPath;
}

function getTenantSelector()
{
    // El proxy inverso tiene prioridad. En producción debe eliminar cualquier
    // X-RUC recibido del cliente y establecer su propio valor de confianza.
    $candidates = [$_SERVER['HTTP_X_RUC'] ?? null, $_COOKIE['ruc'] ?? null];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return null;
}

function enforceActiveTenant($ruc)
{
    if (isTenantCheckBypassed()) {
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_\-]{1,30}$/', $ruc)) {
        return;
    }

    if (isset($_GET['sb_retry']) && function_exists('apcu_delete')) {
        apcu_delete('sb_tenant_active_' . $ruc);
        apcu_delete('sb_tenant_access_v2_' . $ruc);
    }

    $state = getTenantAccessState($ruc);
    if ($state === null) {
        return;
    }
    if (!empty($state['lookup_failed'])) {
        renderTenantUnavailable();
        exit;
    }

    // La fecha se evalúa en cada request, también cuando la fila proviene de
    // APCu. Así una caché creada un minuto antes del vencimiento nunca alarga
    // artificialmente una prueba.
    if (($state['mode'] ?? 'production') === 'demo'
        && !empty($state['trial_ends_at'])
        && strtotime($state['trial_ends_at']) <= time()) {
        $state['active'] = false;
        $state['suspension_reason'] = 'trial_expired';
        persistExpiredTrial($ruc);
        if (function_exists('apcu_store')) {
            apcu_store('sb_tenant_access_v2_' . $ruc, $state, 1800);
        }
    }

    if (empty($state['active'])) {
        renderSuspendedPage($state['suspension_reason'] ?? '');
        exit;
    }
}

function isTenantCheckBypassed()
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        if (isset($argv[1]) && $argv[1] === '-cron') {
            return true;
        }
    }
    $url = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    return $url === '/deploy';
}

function getTenantAccessState($ruc)
{
    applyHostTimezone();

    $cacheKey = 'sb_tenant_access_v2_' . $ruc;
    $hasApcu = function_exists('apcu_fetch');
    if ($hasApcu) {
        $hit = apcu_fetch($cacheKey, $success);
        if ($success) {
            return $hit === -1 ? null : $hit;
        }
    }

    try {
        $pdo = hostDatabaseConnection();
        $stmt = $pdo->prepare(
            'SELECT active, mode, trial_ends_at, suspension_reason'
            . ' FROM sb_installations WHERE cifnif = :ruc LIMIT 1'
        );
        $stmt->execute([':ruc' => $ruc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            if ($hasApcu) {
                apcu_store($cacheKey, -1, 1800);
            }
            return null;
        }

        $state = [
            'active' => (bool)$row['active'],
            'mode' => $row['mode'] ?: 'production',
            'trial_ends_at' => $row['trial_ends_at'],
            'suspension_reason' => $row['suspension_reason'],
        ];
        if ($hasApcu) {
            apcu_store($cacheKey, $state, 1800);
        }
        return $state;
    } catch (Throwable $e) {
        error_log('[tenant-check] PDO error ruc=' . $ruc . ': ' . $e->getMessage());
        return ['lookup_failed' => true];
    }
}

/**
 * Compatibilidad para integraciones antiguas que consultaban solo el booleano.
 */
function getTenantActiveStatus($ruc)
{
    $state = getTenantAccessState($ruc);
    if ($state === null) {
        return null;
    }
    if (!empty($state['lookup_failed'])) {
        return null;
    }
    if (($state['mode'] ?? 'production') === 'demo'
        && !empty($state['trial_ends_at'])
        && strtotime($state['trial_ends_at']) <= time()) {
        return false;
    }
    return (bool)$state['active'];
}

function persistExpiredTrial($ruc)
{
    try {
        $pdo = hostDatabaseConnection();
        $stmt = $pdo->prepare(
            'UPDATE sb_installations'
            . ' SET active = 0, status = 2, suspension_reason = :reason'
            . ' WHERE cifnif = :ruc AND mode = :mode'
            . ' AND trial_ends_at IS NOT NULL AND trial_ends_at <= :now'
        );
        $stmt->execute([
            ':reason' => 'trial_expired',
            ':ruc' => $ruc,
            ':mode' => 'demo',
            ':now' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('[tenant-check] could not persist trial expiration ruc=' . $ruc . ': ' . $e->getMessage());
    }
}

function hostDatabaseConnection()
{
    $config = loadHostDatabaseConfig();
    return new PDO(
        'mysql:host=' . $config['host'] . ';port=' . $config['port']
        . ';dbname=' . $config['name'] . ';charset=utf8mb4',
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/**
 * proxy.php corre antes de cargar las constantes de FacturaScripts. Lee la
 * configuración central sin ejecutarla, evitando duplicar credenciales en
 * código y sin contaminar las constantes del posterior config del tenant.
 */
function loadHostDatabaseConfig()
{
    $contents = (string)file_get_contents(__DIR__ . '/config.php');
    $map = [
        'host' => 'FS_DB_HOST',
        'port' => 'FS_DB_PORT',
        'name' => 'FS_DB_NAME',
        'user' => 'FS_DB_USER',
        'pass' => 'FS_DB_PASS',
    ];
    $result = [];
    foreach ($map as $key => $constant) {
        $pattern = '~define\(\s*([\'"])' . preg_quote($constant, '~')
            . '\1\s*,\s*([\'"])(.*?)\2\s*\)\s*;~s';
        if (!preg_match($pattern, $contents, $matches)) {
            throw new RuntimeException('No se pudo leer ' . $constant . ' de la configuración host');
        }
        $result[$key] = stripcslashes($matches[3]);
    }

    $timezonePattern = '~define\(\s*([\'"])FS_TIMEZONE\1\s*,\s*([\'"])(.*?)\2\s*\)\s*;~s';
    if (preg_match($timezonePattern, $contents, $timezoneMatches)) {
        $result['timezone'] = stripcslashes($timezoneMatches[3]);
    }

    return $result;
}

function applyHostTimezone()
{
    static $applied = false;
    if ($applied) {
        return;
    }

    $config = loadHostDatabaseConfig();
    if (!empty($config['timezone']) && in_array($config['timezone'], timezone_identifiers_list(), true)) {
        date_default_timezone_set($config['timezone']);
    }
    $applied = true;
}

function renderSuspendedPage($reason = '')
{
    $GLOBALS['sbSuspensionReason'] = $reason;
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    include __DIR__ . '/suspended.php';
}

function renderTenantSelectionError($status)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $status === 404
        ? 'La instalación solicitada no existe.'
        : 'El identificador de instalación no es válido.';
}

function renderTenantUnavailable()
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Retry-After: 30');
    echo 'No se pudo verificar temporalmente el acceso a la instalación. Intente nuevamente.';
}
