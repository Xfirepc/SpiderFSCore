<?php

function getConfigFile() {

    if (!empty($_COOKIE['ruc'])) {
        return __DIR__ . '/Config/config_' . $_COOKIE['ruc'] . '.php';
    }

    if (!empty($_SERVER['HTTP_X_RUC'])) {
        return __DIR__ . '/Config/config_' . $_SERVER['HTTP_X_RUC'] . '.php';
    }

    return __DIR__ . '/config.php';;
}


