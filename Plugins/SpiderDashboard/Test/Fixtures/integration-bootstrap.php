<?php

// Solo se habilita con la variable explícita de pruebas y una base nueva de nombre fijo.
if (getenv('SPIDER_DASHBOARD_TEST_DB') !== '1') {
    throw new RuntimeException('La base de pruebas debe habilitarse explícitamente.');
}
define('FS_DB_HOST', '127.0.0.1');
define('FS_DB_PORT', '3306');
define('FS_DB_USER', 'root');
define('FS_DB_PASS', '');
define('FS_DB_NAME', 'spider_dashboard_tests');
define('FS_DB_TYPE', 'mysql');
define('FS_DB_FOREIGN_KEYS', false);
define('FS_FOLDER', sys_get_temp_dir() . '/spider-dashboard-fixture-' . getmypid());
define('FS_ITEM_LIMIT', 50);
define('FS_NF0', 2);
define('FS_DEBUG', false);
define('FS_LANG', 'es_EC');
mkdir(FS_FOLDER, 0777, true);
require dirname(__DIR__, 4) . '/vendor/autoload.php';
