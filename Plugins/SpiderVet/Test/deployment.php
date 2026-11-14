<?php

require __DIR__ . '/bootstrap.php';

use FacturaScripts\Core\Base\PluginDeploy;
use FacturaScripts\Core\Base\MiniLog;

// Rebuild in the disposable filesystem using the actual native deployment code.
$deploy = new PluginDeploy();
$deploy->deploy(FS_FOLDER . '/Plugins/', ['SpiderVet']);
spl_autoload_unregister($vetTestAutoload);
spl_autoload_register(function ($class) {
    $prefix = 'FacturaScripts\\Dinamic\\';
    if (strpos($class, $prefix) === 0) {
        $file = FS_FOLDER . '/Dinamic/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

$classes = 0;
foreach (['Lib', 'Model', 'Controller'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        '/app/Plugins/SpiderVet/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen('/app/Plugins/SpiderVet/'));
        $class = 'FacturaScripts\\Dinamic\\' . str_replace('/', '\\', substr($relative, 0, -4));
        if (!class_exists($class)) {
            throw new RuntimeException('The native deployment cannot load ' . $class);
        }
        ++$classes;
    }
}

$deploy->initControllers();
foreach (['SpiderVet', 'PrintSVFicha', 'EditSVPaciente', 'ListSVPaciente', 'EditSVCita', 'ListSVCita',
    'EditSVRecepcion', 'ListSVRecepcion', 'EditSVConsulta', 'ListSVConsulta', 'EditSVPrueba', 'ListSVPrueba',
    'EditSVTratamiento', 'ListSVTratamiento', 'EditSVPeluqueria', 'ListSVPeluqueria'] as $page) {
    $rows = $database->select('SELECT * FROM pages WHERE name = ' . $database->var2str($page));
    if (count($rows) !== 1 || $rows[0]['menu'] !== 'spidervet') {
        throw new RuntimeException('The native menu did not register ' . $page);
    }
}
$errors = (new MiniLog())->read('', ['critical', 'error']);
if ($errors) {
    throw new RuntimeException(implode('; ', array_column($errors, 'message')));
}
echo 'SpiderVet deployment: ' . $classes . " dynamic classes and 16 pages verified.\n";
