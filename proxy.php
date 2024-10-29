<?php

function getConfigFile() {
    $ruc = $_COOKIE['ruc'] ?? $_SERVER['HTTP_X_RUC'] ?? null;
    if ($ruc) {
        $configPath = __DIR__ . '/Config/config_' . $ruc . '.php';
        if (file_exists($configPath)) {
            return $configPath;
        }
    }

    return __DIR__ . '/config.php';
}

