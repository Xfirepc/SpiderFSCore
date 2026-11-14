<?php

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\MysqlQueries;
use RuntimeException;
use SimpleXMLElement;

/** Índices secundarios aditivos de los XML para MySQL/MariaDB. Nunca retira claves. */
final class MysqlTableIndexes
{
    private $database;

    public function __construct(DataBase $database)
    {
        $this->database = $database;
    }

    public static function readXml(SimpleXMLElement $xml): array
    {
        $indexes = [];
        foreach ($xml->index as $index) {
            $name = (string)$index['name'];
            self::validateIdentifier($name);
            if (isset($indexes[$name])) {
                throw new RuntimeException('El XML repite el índice ' . $name . '.');
            }
            $columns = [];
            foreach ($index->column as $column) {
                $field = (string)$column['name'];
                self::validateIdentifier($field);
                if (in_array($field, $columns, true)) {
                    throw new RuntimeException('El índice ' . $name . ' repite una columna.');
                }
                $columns[] = $field;
            }
            if (!$columns) {
                throw new RuntimeException('El índice ' . $name . ' necesita columnas.');
            }
            $indexes[$name] = $columns;
        }
        return $indexes;
    }

    /** Acepta un BTREE equivalente o más amplio, con las mismas columnas iniciales. */
    public static function missing(array $wanted, array $rows): array
    {
        $actual = [];
        foreach ($rows as $row) {
            $actual[$row['Key_name']][(int)$row['Seq_in_index']] = $row;
        }
        $missing = [];
        foreach ($wanted as $name => $columns) {
            self::validateIdentifier($name);
            foreach ($columns as $column) {
                self::validateIdentifier($column);
            }
            if (!$columns) {
                throw new RuntimeException('El índice ' . $name . ' necesita columnas.');
            }
            $covered = false;
            foreach ($actual as $parts) {
                $matches = true;
                foreach (array_values($columns) as $offset => $column) {
                    $part = $parts[$offset + 1] ?? [];
                    if (($part['Column_name'] ?? null) !== $column
                        || ($part['Sub_part'] ?? null) !== null
                        || strtoupper($part['Index_type'] ?? '') !== 'BTREE'
                        || strtoupper($part['Ignored'] ?? 'NO') === 'YES'
                        || strtoupper($part['Visible'] ?? 'YES') === 'NO') {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            if (isset($actual[$name])) {
                throw new RuntimeException('El índice ' . $name . ' existe con otra definición; no se sustituye.');
            }
            $missing[$name] = $columns;
        }
        return $missing;
    }

    /** No invalida toda la caché ni modifica filas, unicidad o relaciones existentes. */
    public function ensure(string $table, array $indexes): bool
    {
        if (!$indexes || !($this->database->getEngine()->getSQL() instanceof MysqlQueries)) {
            return false;
        }
        self::validateIdentifier($table);
        if (!$this->inspect($table, $indexes)) {
            return false;
        }
        if ($this->database->inTransaction()) {
            throw new RuntimeException('El índice de ' . $table . ' queda pendiente hasta salir de la transacción.');
        }

        $lock = new SchemaCheckLock((string)$this->select('SELECT DATABASE() AS name')[0]['name']);
        $lock->acquire();
        $wait = null;
        try {
            $missing = $this->inspect($table, $indexes);
            if (!$missing) {
                return false;
            }
            $wait = (int)$this->select('SELECT @@SESSION.lock_wait_timeout AS value')[0]['value'];
            $this->execute('SET SESSION lock_wait_timeout=2');
            foreach ($missing as $name => $columns) {
                // DDL explícito: el ORM no expresa índices online sin cambiar los datos.
                $this->execute('ALTER TABLE ' . $this->database->escapeColumn($table)
                    . ' ADD INDEX ' . $this->database->escapeColumn($name) . ' ('
                    . implode(',', array_map([$this->database, 'escapeColumn'], $columns))
                    . '), ALGORITHM=INPLACE, LOCK=NONE');
            }
            if ($this->inspect($table, $indexes)) {
                throw new RuntimeException('No se confirmaron los índices de ' . $table . '.');
            }
            return true;
        } finally {
            try {
                if ($wait !== null && !$this->database->exec('SET SESSION lock_wait_timeout=' . $wait)) {
                    $this->database->close();
                    throw new RuntimeException('Se cerró la conexión al no restaurar la espera de metadatos.');
                }
            } finally {
                $lock->release();
            }
        }
    }

    private function inspect(string $table, array $indexes): array
    {
        return self::missing($indexes, $this->select('SHOW INDEX FROM ' . $this->database->escapeColumn($table)));
    }

    private function select(string $sql): array
    {
        $rows = $this->database->select($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('No se pudieron leer los metadatos de los índices.');
        }
        return $rows;
    }

    private function execute(string $sql): void
    {
        if (!$this->database->exec($sql)) {
            throw new RuntimeException('No se pudo añadir un índice online; se conserva pendiente.');
        }
    }

    private static function validateIdentifier(string $value): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value)) {
            throw new RuntimeException('El XML contiene un nombre de índice o columna no válido.');
        }
    }
}
