<?php

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Tools;
use RuntimeException;

/** Migración explícita del orden numérico; nunca se ejecuta al guardar una venta. */
final class DocumentNumberingSchema
{
    const TABLE = 'facturascli';
    const COLUMN = 'fs_numero_orden';
    const YEAR_INDEX = 'idx_fs_numero_ejercicio';
    const SERIES_INDEX = 'idx_fs_numero_serie';

    // El prefijo conserva el orden de CAST sin provocar errores de truncamiento
    // en modo estricto para números manuales como "12A", que el Core admite.
    private const EXPRESSION = "CAST(COALESCE(NULLIF(REGEXP_SUBSTR(numero, '^[[:space:]]*[+-]?[0-9]+'), ''), '0') AS UNSIGNED)";

    private const INDEXES = [
        self::YEAR_INDEX => ['idempresa', 'codserie', 'codejercicio', self::COLUMN],
        self::SERIES_INDEX => ['idempresa', 'codserie', self::COLUMN],
    ];

    private static $available = [];
    private $database;

    public function __construct(DataBase $database)
    {
        $this->database = $database;
    }

    /** Solo se conserva metadato durante la petición, nunca números ni saldos. */
    public static function available(BusinessDocument $document): bool
    {
        if (strtolower(FS_DB_TYPE) !== 'mysql' || $document::tableName() !== self::TABLE) {
            return false;
        }
        $field = $document->getModelFields()[self::COLUMN] ?? [];
        if (!preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $field['extra'] ?? '')) {
            return false;
        }

        $key = (string)Tools::config('db_name') . ':' . self::TABLE;
        if (!array_key_exists($key, self::$available)) {
            $schema = new self(new DataBase());
            self::$available[$key] = $schema->inspect()['ready'];
        }
        return self::$available[$key];
    }

    public static function reset(): void
    {
        self::$available = [];
    }

    public function inspect(): array
    {
        if (strtolower(FS_DB_TYPE) !== 'mysql') {
            throw new RuntimeException('El índice de numeración requiere MySQL/MariaDB.');
        }
        $table = $this->select("SELECT ENGINE FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . self::TABLE . "'");
        if (!$table || strcasecmp($table[0]['ENGINE'], 'InnoDB') !== 0) {
            throw new RuntimeException('Falta facturascli en InnoDB; no se ha modificado el esquema.');
        }

        $columns = $this->select("SELECT COLUMN_NAME,COLUMN_TYPE,EXTRA,GENERATION_EXPRESSION"
            . " FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . self::TABLE . "'");
        $byName = array_column($columns, null, 'COLUMN_NAME');
        foreach (['idempresa', 'codserie', 'codejercicio', 'numero', 'fecha', 'hora'] as $name) {
            if (!isset($byName[$name])) {
                throw new RuntimeException('Falta la columna de numeración ' . $name . '.');
            }
        }

        $column = $byName[self::COLUMN] ?? null;
        $conflicts = [];
        if ($column && !$this->matchesColumn($column)) {
            $conflicts[] = 'La columna auxiliar existente no coincide con la definición calculada.';
        }
        $indexes = $this->select("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . self::TABLE . "' AND INDEX_NAME IN ('"
            . self::YEAR_INDEX . "','" . self::SERIES_INDEX . "') ORDER BY INDEX_NAME,SEQ_IN_INDEX");
        $actual = [];
        foreach ($indexes as $index) {
            $actual[$index['INDEX_NAME']][] = $index;
        }
        $missing = [];
        foreach (self::INDEXES as $name => $fields) {
            if (!isset($actual[$name])) {
                $missing[] = $name;
            } elseif (array_column($actual[$name], 'COLUMN_NAME') !== $fields
                || array_filter($actual[$name], static function (array $row): bool {
                    return (int)$row['NON_UNIQUE'] !== 1 || $row['SUB_PART'] !== null;
                })) {
                $conflicts[] = 'El índice ' . $name . ' tiene otra definición.';
            }
        }

        return ['ready' => $column && !$missing && !$conflicts, 'column' => $column,
            'indexes' => $actual, 'missing_indexes' => $missing, 'conflicts' => $conflicts];
    }

    public function upgrade(): array
    {
        return $this->change(false);
    }

    /** Conserva todos los números, documentos e índices únicos originales. */
    public function rollback(): array
    {
        return $this->change(true);
    }

    private function change(bool $rollback): array
    {
        if ($this->database->inTransaction()) {
            throw new RuntimeException('La migración de numeración debe ejecutarse fuera de transacciones comerciales.');
        }
        $name = $this->select('SELECT DATABASE() AS name')[0]['name'];
        $lock = new SchemaCheckLock($name);
        $lock->acquire();
        $lockedTable = false;
        $wait = null;
        try {
            $before = $this->inspect();
            if ($before['conflicts']) {
                throw new RuntimeException(implode(' ', $before['conflicts']));
            }
            if ((!$rollback && $before['ready'])
                || ($rollback && !$before['column'] && !$before['indexes'])) {
                return ['changed' => false, 'before' => $before, 'after' => $before];
            }

            $wait = (int)$this->select('SELECT @@SESSION.lock_wait_timeout AS value')[0]['value'];
            $this->execute('SET SESSION lock_wait_timeout=2');
            // La instalación online permite ventas también durante la validación.
            // La retirada sí requiere una ventana de mantenimiento explícita.
            if ($rollback) {
                $this->execute('LOCK TABLES ' . self::TABLE . ' WRITE');
                $lockedTable = true;
            }
            $current = $this->inspect();
            if ($current['conflicts']) {
                throw new RuntimeException(implode(' ', $current['conflicts']));
            }
            $clauses = [];
            $changed = false;
            if ($rollback) {
                $dependent = $this->select("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS"
                    . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . self::TABLE . "'"
                    . " AND COLUMN_NAME='" . self::COLUMN . "' AND INDEX_NAME NOT IN ('"
                    . self::YEAR_INDEX . "','" . self::SERIES_INDEX . "')");
                if ($dependent) {
                    throw new RuntimeException('Hay índices ajenos que utilizan la columna auxiliar; no se han retirado.');
                }
                foreach (array_keys($current['indexes']) as $index) {
                    $clauses[] = 'DROP INDEX ' . $index;
                }
                if ($current['column']) {
                    $clauses[] = 'DROP COLUMN ' . self::COLUMN;
                }
                if ($clauses) {
                    $this->execute('ALTER TABLE ' . self::TABLE . ' ' . implode(', ', $clauses)
                        . ', ALGORITHM=COPY, LOCK=SHARED');
                    $changed = true;
                }
            } else {
                $this->validateNumbers();
                if (!$current['column']) {
                    // El campo virtual evita copiar todas las facturas y sus índices existentes.
                    $this->execute('ALTER TABLE ' . self::TABLE . ' ADD COLUMN ' . self::COLUMN
                        . ' BIGINT UNSIGNED GENERATED ALWAYS AS (' . self::EXPRESSION . ') VIRTUAL'
                        . ', ALGORITHM=INSTANT, LOCK=NONE');
                    $changed = true;
                }
                foreach ($current['missing_indexes'] as $index) {
                    $clauses[] = 'ADD INDEX ' . $index . ' (' . implode(',', self::INDEXES[$index]) . ')';
                }
                if ($clauses) {
                    $this->execute('ALTER TABLE ' . self::TABLE . ' ' . implode(', ', $clauses)
                        . ', ALGORITHM=INPLACE, LOCK=NONE');
                    $changed = true;
                }
            }
            $after = $this->inspect();
            if ((!$rollback && !$after['ready']) || ($rollback && ($after['column'] || $after['indexes']))) {
                throw new RuntimeException('No se pudo verificar el esquema final de numeración.');
            }
            $this->invalidateMetadata($name);
            return ['changed' => $changed, 'before' => $before, 'after' => $after];
        } finally {
            try {
                $restored = !$lockedTable || $this->database->exec('UNLOCK TABLES');
                if ($restored && $wait !== null) {
                    $restored = $this->database->exec('SET SESSION lock_wait_timeout=' . $wait);
                }
                if (!$restored) {
                    $this->database->close();
                    throw new RuntimeException('Se cerró la conexión al no poder restaurar la sesión de mantenimiento.');
                }
            } finally {
                $lock->release();
            }
        }
    }

    private function matchesColumn(array $column): bool
    {
        $type = preg_replace('/\(\d+\)/', '', strtolower($column['COLUMN_TYPE']));
        $expression = $this->normalizeExpression($column['GENERATION_EXPRESSION'] ?? '');
        $expected = $this->normalizeExpression(self::EXPRESSION);
        return $type === 'bigint unsigned' && $expression === $expected
            && (bool)preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $column['EXTRA']);
    }

    private function normalizeExpression(string $expression): string
    {
        // El espacio dentro de un literal puede cambiar el significado del
        // patrón. Solo se normalizan identificadores y sintaxis fuera de él.
        $parts = preg_split("/('(?:[^'\\\\]|\\\\.|'')*')/", $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        $normalized = '';
        foreach ($parts as $part) {
            $normalized .= isset($part[0]) && $part[0] === "'"
                ? $part : preg_replace('/[\s`()]/', '', strtolower($part));
        }
        return $normalized;
    }

    private function validateNumbers(): void
    {
        if ($this->select("SELECT 1 AS invalid FROM " . self::TABLE
            . " WHERE numero IS NULL OR numero NOT REGEXP '^[0-9]{1,12}$' LIMIT 1")) {
            throw new RuntimeException('Hay números con otro formato; requieren revisión manual y no se han cambiado.');
        }
        if ($this->select('SELECT 1 AS duplicate FROM ' . self::TABLE
            . ' GROUP BY idempresa,codserie,codejercicio,CAST(numero AS UNSIGNED) HAVING COUNT(*)>1 LIMIT 1')) {
            throw new RuntimeException('Hay números equivalentes duplicados; requieren revisión manual y no se han cambiado.');
        }
    }

    private function invalidateMetadata(string $database): void
    {
        self::reset();
        FacturaCliente::resetModelFields();
        if (preg_match('/^(\d{13})_/', $database, $match)) {
            Cache::delete('model-fields-FacturaCliente', '-' . $match[1]);
        }
    }

    private function select(string $sql): array
    {
        $rows = $this->database->select($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('No se pudieron leer los metadatos de numeración: ' . $this->database->lastError());
        }
        return $rows;
    }

    private function execute(string $sql): void
    {
        if (!$this->database->exec($sql)) {
            throw new RuntimeException('No se pudo completar la migración de numeración: ' . $this->database->lastError());
        }
    }
}
