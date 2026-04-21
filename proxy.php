<?php

function getConfigFile() {
    $ruc = $_COOKIE['ruc'] ?? $_SERVER['HTTP_X_RUC'] ?? null;
    if ($ruc) {
        enforceActiveTenant($ruc);
        $configPath = __DIR__ . '/Config/config_' . $ruc . '.php';
        if (file_exists($configPath)) {
            return $configPath;
        }
    }

    return __DIR__ . '/config.php';
}

function enforceActiveTenant($ruc) {
    if (isTenantCheckBypassed()) {
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_\-]{1,30}$/', $ruc)) {
        return;
    }

    $active = getTenantActiveStatus($ruc);
    if ($active === null) {
        return;
    }
    if ($active === false) {
        renderSuspendedPage();
        exit;
    }
}

function isTenantCheckBypassed() {
    if (PHP_SAPI === 'cli') {
        global $argv;
        if (isset($argv[1]) && $argv[1] === '-cron') {
            return true;
        }
    }
    $url = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    return $url === '/deploy';
}

function getTenantActiveStatus($ruc) {
    $cacheKey = 'sb_tenant_active_' . $ruc;
    $hasApcu = function_exists('apcu_fetch');

    if ($hasApcu) {
        $hit = apcu_fetch($cacheKey, $success);
        if ($success) {
            if ($hit === -1) {
                return null;
            }
            return (bool) $hit;
        }
    }

    try {
        // sync with /config.php (BD central)
        $pdo = new PDO(
            'mysql:host=mysql;port=3306;dbname=main_spidercodeapp;charset=utf8mb4',
            'root',
            'FfQDeBEkny2N',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $stmt = $pdo->prepare('SELECT active FROM sb_installations WHERE cifnif = ? LIMIT 1');
        $stmt->execute([$ruc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            if ($hasApcu) {
                apcu_store($cacheKey, -1, 1800);
            }
            return null;
        }

        $active = (bool) $row['active'];
        if ($hasApcu) {
            apcu_store($cacheKey, $active ? 1 : 0, 1800);
        }
        return $active;
    } catch (Throwable $e) {
        error_log('[tenant-check] PDO error ruc=' . $ruc . ': ' . $e->getMessage());
        return null;
    }
}

function renderSuspendedPage() {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    include __DIR__ . '/suspended.php';
}
