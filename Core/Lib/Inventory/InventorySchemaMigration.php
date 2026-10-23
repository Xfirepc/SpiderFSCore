<?php

namespace FacturaScripts\Core\Lib\Inventory;

use FacturaScripts\Core\Base\DataBase;
use RuntimeException;

/**
 * Migración de mantenimiento para restricciones cuyo nombre ya existía.
 * No se invoca desde ventas ni modifica filas, cantidades o identidades antiguas.
 */
final class InventorySchemaMigration
{
    private const HISTORY_KEYS = [
        'ca_stocks_movimientos_almacenes' => ['codalmacen', 'almacenes', 'codalmacen'],
        'ca_stocks_movimientos_productos' => ['idproducto', 'productos', 'idproducto'],
        'ca_stocks_movimientos_variantes' => ['referencia', 'variantes', 'referencia'],
    ];

    /** @var DataBase */
    private $database;

    public function __construct(DataBase $database)
    {
        $this->database = $database;
    }

    /**
     * MariaDB necesita nombres distintos para sustituir una FK atómicamente.
     * El XML conserva su identidad lógica; el actualizador reconoce su nombre
     * físico migrado y no intenta eliminarla ni recrear la relación anterior.
     */
    public static function physicalConstraints(string $table, array $wanted, array $actual): array
    {
        if ($table !== 'stocks_movimientos') {
            return $wanted;
        }
        $names = array_column($actual, 'name');
        foreach ($wanted as $key => $constraint) {
            $name = $constraint['name'];
            $physicalName = $name . '_restrict_v2';
            if (isset(self::HISTORY_KEYS[$name]) && in_array($physicalName, $names, true)
                && !in_array($name, $names, true)) {
                $wanted[$key]['name'] = $physicalName;
            }
        }
        return $wanted;
    }

    public function inspect(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            if (!in_array($table, ['stocks_movimientos', 'productos_lotes'], true)) {
                throw new RuntimeException('Tabla fuera del alcance de la migración de inventario.');
            }
            if (!$this->database->tableExists($table)) {
                throw new RuntimeException('Falta preparar la tabla ' . $table . '.');
            }

            $result[$table] = $table === 'stocks_movimientos'
                ? $this->inspectHistory()
                : $this->inspectLots();
        }
        return $result;
    }

    public function upgrade(array $tables): array
    {
        if ($this->database->inTransaction()) {
            throw new RuntimeException('La migración debe ejecutarse fuera de transacciones comerciales.');
        }

        $before = $this->inspect($tables);
        if (!array_filter($before, static function (array $row): bool {
            return !empty($row['clauses']);
        })) {
            return ['changed_tables' => [], 'before' => $before, 'after' => $before];
        }

        $session = $this->select('SELECT @@SESSION.foreign_key_checks AS fk, @@SESSION.lock_wait_timeout AS wait_timeout')[0];
        if ((int)$session['fk'] !== 1) {
            throw new RuntimeException('La sesión de mantenimiento debe iniciar con las relaciones activadas.');
        }
        $version = $this->select('SELECT VERSION() AS version')[0]['version'];
        if (stripos($version, 'MariaDB') === false) {
            throw new RuntimeException('Esta migración online está validada para MariaDB.');
        }

        $changed = [];
        $this->execute('SET SESSION lock_wait_timeout = 2');
        try {
            foreach ($before as $table => $row) {
                if (empty($row['clauses'])) {
                    continue;
                }
                if ($table === 'stocks_movimientos') {
                    // Se sustituyen las mismas relaciones existentes en un único ALTER.
                    // Sus columnas y destinos se verifican antes; las relaciones previas
                    // siguen protegiendo las escrituras de las demás sesiones. MariaDB
                    // exige esta opción SOLO en esta sesión para cambiar metadatos
                    // mediante INPLACE sin copiar la tabla ni bloquear escrituras.
                    $this->execute('SET SESSION foreign_key_checks = 0');
                }
                try {
                    $this->execute('ALTER TABLE ' . $table . ' ' . implode(', ', $row['clauses'])
                        . ', ALGORITHM=INPLACE, LOCK=NONE');
                    $changed[] = $table;
                } finally {
                    $this->execute('SET SESSION foreign_key_checks = 1');
                }
            }
        } finally {
            if (!$this->database->exec('SET SESSION foreign_key_checks = 1')) {
                $this->database->close();
                throw new RuntimeException('Se cerró la sesión de mantenimiento al restaurar sus validaciones.');
            }
            $this->execute('SET SESSION lock_wait_timeout = ' . (int)$session['wait_timeout']);
        }

        $after = $this->inspect($tables);
        foreach ($after as $table => $row) {
            if (!empty($row['clauses'])) {
                throw new RuntimeException('La restricción de ' . $table . ' no coincide tras la migración.');
            }
        }
        return ['changed_tables' => $changed, 'before' => $before, 'after' => $after];
    }

    private function inspectHistory(): array
    {
        $rows = $this->select("SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA,"
            . " k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE"
            . " FROM information_schema.KEY_COLUMN_USAGE k"
            . " JOIN information_schema.REFERENTIAL_CONSTRAINTS r"
            . " ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME"
            . " AND r.TABLE_NAME=k.TABLE_NAME"
            . " WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='stocks_movimientos'");
        $databaseName = $this->select('SELECT DATABASE() AS name')[0]['name'];
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['CONSTRAINT_NAME']][] = $row;
        }

        $clauses = [];
        foreach (self::HISTORY_KEYS as $name => $definition) {
            $physicalName = $name . '_restrict_v2';
            if (isset($keys[$name], $keys[$physicalName])) {
                throw new RuntimeException('Hay dos versiones de la relación ' . $name . '; requiere revisión manual.');
            }
            $actual = $keys[$physicalName] ?? ($keys[$name] ?? []);
            if (count($actual) !== 1
                || $actual[0]['COLUMN_NAME'] !== $definition[0]
                || $actual[0]['REFERENCED_TABLE_SCHEMA'] !== $databaseName
                || $actual[0]['REFERENCED_TABLE_NAME'] !== $definition[1]
                || $actual[0]['REFERENCED_COLUMN_NAME'] !== $definition[2]) {
                throw new RuntimeException('La relación ' . $name . ' necesita revisión manual; no se ha cambiado.');
            }

            // InnoDB puede publicar RESTRICT como su sinónimo NO ACTION.
            if (in_array($actual[0]['UPDATE_RULE'], ['RESTRICT', 'NO ACTION'], true)
                && in_array($actual[0]['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true)) {
                continue;
            }
            if (isset($keys[$physicalName])) {
                throw new RuntimeException('La relación migrada ' . $physicalName . ' no coincide; requiere revisión manual.');
            }
            $orphans = $this->select('SELECT 1 AS orphan FROM stocks_movimientos m LEFT JOIN '
                . $definition[1] . ' p ON p.' . $definition[2] . '=m.' . $definition[0]
                . ' WHERE m.' . $definition[0] . ' IS NOT NULL AND p.' . $definition[2] . ' IS NULL LIMIT 1');
            if ($orphans) {
                throw new RuntimeException('Hay movimientos sin referencia válida en ' . $name . '; no se reparan automáticamente.');
            }
            $clauses[] = 'DROP FOREIGN KEY ' . $name;
            $clauses[] = 'ADD CONSTRAINT ' . $physicalName . ' FOREIGN KEY (' . $definition[0]
                . ') REFERENCES ' . $definition[1] . ' (' . $definition[2] . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
        }
        return ['clauses' => $clauses, 'foreign_keys' => $rows];
    }

    private function inspectLots(): array
    {
        $rows = $this->select("SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_lotes'"
            . " AND INDEX_NAME='uniq_numserie_productos' ORDER BY SEQ_IN_INDEX");
        $columns = array_column($rows, 'COLUMN_NAME');
        if (!in_array($columns, [['referencia', 'numserie'], ['referencia', 'numserie', 'codalmacen']], true)) {
            throw new RuntimeException('El índice de lotes necesita revisión manual; no se ha cambiado.');
        }
        foreach ($rows as $row) {
            if ((int)$row['NON_UNIQUE'] !== 0 || null !== $row['SUB_PART']) {
                throw new RuntimeException('El índice de lotes no coincide con la definición esperada.');
            }
        }
        $warehouse = $this->select("SELECT COLUMN_NAME FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_lotes' AND COLUMN_NAME='codalmacen'");
        if (!$warehouse) {
            throw new RuntimeException('Falta preparar la columna de almacén de los lotes.');
        }

        return ['columns' => $columns, 'clauses' => count($columns) === 3 ? [] : [
            'DROP INDEX uniq_numserie_productos',
            'ADD CONSTRAINT uniq_numserie_productos UNIQUE (referencia,numserie,codalmacen)',
        ]];
    }

    private function select(string $sql): array
    {
        $rows = $this->database->select($sql);
        if (!is_array($rows)) {
            throw new RuntimeException('No se pudo comprobar el esquema de inventario.');
        }
        return $rows;
    }

    private function execute(string $sql): void
    {
        if (!$this->database->exec($sql)) {
            throw new RuntimeException('No se pudo actualizar una restricción de inventario; revise los logs antes de reintentar.');
        }
    }
}
