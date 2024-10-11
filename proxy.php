<?php

// Buscar la variable de Cookies con el RUC de la DB

function getConfigFile() {
    $configFile = __DIR__ . '/config.php';

    if (!empty($_COOKIE['ruc'])) {
        $configFile = __DIR__ . '/Config/config_' . $_COOKIE['ruc'] . '.php';
    }

    return $configFile;
}


