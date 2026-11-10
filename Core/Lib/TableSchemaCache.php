<?php

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Tools;
use RuntimeException;

/** Conserva las comprobaciones de todos los tenants bajo un bloqueo estable. */
final class TableSchemaCache
{
    public static function generation(): string
    {
        return self::locked(static function ($handle, array $state): string {
            return $state['generation'];
        });
    }

    public static function read(string $database): array
    {
        return self::locked(static function ($handle, array $state) use ($database): array {
            $map = self::readMap();
            return ['generation' => $state['generation'], 'tables' => $map[$database] ?? []];
        });
    }

    /** Un proceso anterior a un rebuild no puede publicar comprobaciones viejas. */
    public static function remember(string $database, string $table, string $generation): ?array
    {
        return self::locked(static function ($handle, array $state) use ($database, $table, $generation): ?array {
            if (!hash_equals($state['generation'], $generation)) {
                return null;
            }
            $map = self::readMap();
            $tables = $map[$database] ?? [];
            if (!in_array($table, $tables, true)) {
                $tables[] = $table;
            }
            $map[$database] = $tables;
            self::writeMap($map);
            return $tables;
        });
    }

    /** Sin base indicada, invalida todas las comprobaciones del esquema compartido. */
    public static function invalidate(?string $database = null): void
    {
        self::locked(static function ($handle, array $state) use ($database): void {
            $state = ['generation' => bin2hex(random_bytes(16)), 'pending' => true, 'database' => $database];
            // Si el proceso termina aquí, el siguiente lector completa la invalidación.
            self::writeState($handle, $state);
            self::finishInvalidation($handle, $state);
        });
    }

    private static function locked(callable $operation)
    {
        $folder = Tools::folder('MyFiles');
        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new RuntimeException('No se puede crear la carpeta de comprobaciones del esquema.');
        }
        $path = $folder . '/.db-updater.lock';
        $handle = @fopen($path, 'x+');
        $created = is_resource($handle);
        if (!$created) {
            $handle = @fopen($path, 'c+');
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('No se puede abrir el bloqueo de comprobaciones del esquema.');
        }
        if ($created) {
            @chmod($path, 0666);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No se puede bloquear la caché de comprobaciones del esquema.');
            }
            $state = self::readState($handle, $created);
            return $operation($handle, $state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function readState($handle, bool $created): array
    {
        rewind($handle);
        $state = json_decode(stream_get_contents($handle), true);
        if (!is_array($state) || !is_string($state['generation'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $state['generation'])
            || !is_bool($state['pending'] ?? null)
            || ($state['pending'] && (!array_key_exists('database', $state)
                || ($state['database'] !== null && !is_string($state['database']))))) {
            $state = ['generation' => bin2hex(random_bytes(16)), 'pending' => !$created, 'database' => null];
            self::writeState($handle, $state);
        }
        if ($state['pending']) {
            $state = self::finishInvalidation($handle, $state);
        }
        return $state;
    }

    private static function finishInvalidation($handle, array $state): array
    {
        $map = $state['database'] === null ? [] : self::readMap();
        if ($state['database'] !== null) {
            unset($map[$state['database']]);
        }
        self::writeMap($map);
        $state['pending'] = false;
        self::writeState($handle, $state);
        return $state;
    }

    private static function readMap(): array
    {
        $path = Tools::folder('MyFiles', 'db-updater.json');
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('No se puede leer la caché de comprobaciones del esquema.');
        }
        $map = json_decode($contents, true);
        if (!is_array($map)) {
            throw new RuntimeException('La caché de comprobaciones del esquema contiene JSON inválido.');
        }
        // La lista antigua carece de identidad de tenant y debe comprobarse de nuevo.
        if ($map && array_keys($map) === range(0, count($map) - 1)) {
            return [];
        }
        foreach ($map as $tables) {
            if (!is_array($tables) || count(array_filter($tables, 'is_string')) !== count($tables)) {
                throw new RuntimeException('La caché de comprobaciones del esquema tiene un formato inválido.');
            }
        }
        return $map;
    }

    private static function writeMap(array $map): void
    {
        $path = Tools::folder('MyFiles', 'db-updater.json');
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $contents = json_encode($map, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new RuntimeException('No se pudo guardar la caché de comprobaciones del esquema.');
            }
            if (!chmod($temporary, 0666) || !rename($temporary, $path)) {
                throw new RuntimeException('No se pudo publicar la caché de comprobaciones del esquema.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function writeState($handle, array $state): void
    {
        $contents = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (fwrite($handle, $contents) !== strlen($contents)
            || !ftruncate($handle, strlen($contents)) || !fflush($handle)) {
            throw new RuntimeException('No se pudo guardar la generación de comprobaciones del esquema.');
        }
    }
}
