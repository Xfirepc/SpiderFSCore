<?php

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Tools;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** Prepara modelos en varias pasadas para respetar las dependencias entre tablas. */
final class ModelSchemaPreparation
{
    public function prepare(): array
    {
        $database = new DataBase();
        if (!$database->connect()) {
            throw new RuntimeException('No se pudo conectar para preparar los modelos.');
        }
        $pending = [];
        $failed = [];
        $definitions = [];
        foreach (glob(FS_FOLDER . '/Dinamic/Model/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $class = 'FacturaScripts\\Dinamic\\Model\\' . $name;
            try {
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                $constructor = $reflection->getConstructor();
                if (!$reflection->isSubclassOf(ModelClass::class) || !$reflection->isInstantiable()
                    || ($constructor && $constructor->getNumberOfRequiredParameters() > 0)) {
                    continue;
                }
                $table = $class::tableName();
                $definitions[$class] = ['table' => $table, 'parents' => $this->references($table)];
                $pending[$class] = $name;
            } catch (Throwable $error) {
                $failed[$name] = $name . ': ' . mb_substr($error->getMessage(), 0, 120);
            }
        }
        $ok = 0;
        $available = array_fill_keys($database->getTables(), true);
        while ($pending) {
            $retry = [];
            $errors = [];
            foreach ($pending as $class => $name) {
                try {
                    $table = $definitions[$class]['table'];
                    $missing = array_diff($definitions[$class]['parents'], array_keys($available));
                    if ($missing) {
                        throw new RuntimeException('Faltan tablas requeridas: ' . implode(', ', $missing));
                    }
                    if (method_exists($class, 'resetModelFields')) {
                        $class::resetModelFields();
                    }
                    new $class();
                    // Comprobar los metadatos reales: un constructor puede registrar
                    // un fallo de DDL sin lanzar una excepción ni crear su tabla.
                    $rows = $database->select('SELECT TABLE_NAME FROM information_schema.TABLES'
                        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $database->var2str($table));
                    if (!$rows) {
                        unset($available[$table]);
                        throw new RuntimeException('No se pudo preparar la tabla ' . $table);
                    }
                    $available[$table] = true;
                    $ok++;
                } catch (Throwable $error) {
                    $retry[$class] = $name;
                    $errors[$name] = $name . ': ' . mb_substr($error->getMessage(), 0, 120);
                }
            }
            if (count($retry) === count($pending)) {
                $failed += $errors;
                break;
            }
            $pending = $retry;
        }
        return [$ok, array_values($failed)];
    }

    private function references(string $table): array
    {
        $path = FS_FOLDER . '/Dinamic/Table/' . $table . '.xml';
        if (!Tools::config('db_foreign_keys', false) || !is_file($path)) {
            return [];
        }
        $structure = DbUpdater::readTableXml($path);
        $parents = [];
        foreach ($structure['constraints'] as $constraint) {
            if (preg_match('/FOREIGN KEY\s*\([^)]*\)\s+REFERENCES\s+`?([a-zA-Z0-9_]+)`?\s*\(/i',
                $constraint['constraint'], $match) && $match[1] !== $table) {
                $parents[] = $match[1];
            }
        }
        return array_values(array_unique($parents));
    }
}
