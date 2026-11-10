<?php

/**
 * This file is part of FacturaScripts
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseQueries;
use FacturaScripts\Core\Base\PluginDeploy;
use FacturaScripts\Core\Lib\Inventory\InventorySchemaMigration;
use FacturaScripts\Core\Lib\SchemaCheckLock;
use FacturaScripts\Core\Lib\TableSchemaCache;

/**
 * Actualiza la estructura de la base de datos.
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
final class DbUpdater
{
    const FILE_NAME = 'db-updater.json';

    /** @var array */
    private static $checkedTables;

    private static $checkedGeneration;
    private static $checkedDatabase;

    /** @var DataBase */
    private static $dataBase;

    /** @var DataBaseQueries */
    private static $sqlTool;

    public static function createTable(string $tableName, array $structure = [], string $sqlAfter = ''): bool
    {
        return self::withSchemaLock(static function () use ($tableName, $structure, $sqlAfter): bool {
            if (self::isTableChecked($tableName)) {
                return false;
            }
            return self::createTableUnlocked($tableName, $structure, $sqlAfter);
        });
    }

    private static function createTableUnlocked(string $tableName, array $structure, string $sqlAfter): bool
    {
        if (empty($structure)) {
            $filePath = self::getTableXmlLocation($tableName);
            $structure = self::readTableXml($filePath);
        }

        if (empty($structure['columns'])) {
            Tools::log()->critical('No se puede crear la tabla ' . $tableName . ': falta una definición XML válida. Reconstruya Dinamic.');
            return false;
        }

        if (self::db()->tableExists($tableName, [], true)) {
            return false;
        }

        $sql = self::sqlTool()->sqlCreateTable($tableName, $structure['columns'], $structure['constraints'] ?? []) . $sqlAfter;
        if (self::db()->exec($sql)) {
            self::save($tableName);

            Tools::log()->debug('table-checked', ['%tableName%' => $tableName]);
            return true;
        }

        // Do not cache failed creations. A later model instantiation must be
        // able to retry, otherwise foreign keys can fail because a referenced
        // table was not created during a previous partial update.
        return false;
    }

    public static function dropTable(string $tableName): bool
    {
        return self::withSchemaLock(static function () use ($tableName): bool {
            return self::dropTableUnlocked($tableName);
        });
    }

    private static function dropTableUnlocked(string $tableName): bool
    {
        if (false === self::db()->tableExists($tableName, [], true)) {
            return false;
        }

        $sql = self::sqlTool()->sqlDropTable($tableName);
        if (self::db()->exec($sql)) {
            self::rebuild();

            Tools::log()->debug('table-deleted', ['%tableName%' => $tableName]);
            return true;
        }

        self::rebuild();
        return false;
    }

    public static function getTableXmlLocation(string $tableName): string
    {
        $fileName = Tools::folder('Dinamic', 'Table', $tableName . '.xml');
        if (!is_file($fileName)) {
            // New tables may be loaded during plugin init before the usual rebuild.
            // Use the same plugin priority and XML extensions as a full deployment.
            $deploy = new PluginDeploy();
            $deploy->deployTable($tableName, Plugins::enabled());
        }
        if (Tools::config('debug') && false === file_exists($fileName)) {
            return Tools::folder('Core', 'Table', $tableName . '.xml');
        }

        return $fileName;
    }

    public static function isTableChecked(string $tableName): bool
    {
        if (null === self::$checkedTables || self::$checkedDatabase !== self::databaseName()
            || self::$checkedGeneration !== TableSchemaCache::generation()) {
            self::reloadCheckedTables();
        }
        return in_array($tableName, self::$checkedTables, true);
    }

    public static function readTableXml(string $filePath): array
    {
        $structure = [
            'columns' => [],
            'constraints' => []
        ];

        if (false === file_exists($filePath)) {
            Tools::log()->critical('file-not-found', ['%fileName%' => $filePath]);
            return $structure;
        }

        $xml = simplexml_load_string(file_get_contents($filePath, true));
        if (false === $xml) {
            Tools::log()->critical('error-reading-file', ['%fileName%' => $filePath]);
            return $structure;
        }

        // if no column, return empty structure
        if (false === isset($xml->column)) {
            return $structure;
        }

        foreach ($xml->column as $col) {
            $item = [
                'name' => (string)$col->name,
                'type' => (string)$col->type,
                'null' => $col->null && strtolower($col->null) === 'no' ? 'NO' : 'YES',
                'default' => $col->default === '' ? null : (string)$col->default
            ];

            if ($col->type == 'serial') {
                $item['null'] = 'NO';
                $item['default'] = null;
            }

            $structure['columns'][$item['name']] = $item;
        }

        if (isset($xml->constraint)) {
            foreach ($xml->constraint as $col) {
                $key = (string)$col->name;

                $structure['constraints'][$key] = [
                    'name' => $key,
                    'constraint' => (string)$col->type
                ];
            }
        }

        return $structure;
    }

    public static function rebuild(): void
    {
        TableSchemaCache::invalidate();
        self::$checkedTables = null;
    }

    public static function forgetDatabase(string $database): void
    {
        if ($database !== '') {
            TableSchemaCache::invalidate($database);
            self::$checkedTables = null;
        }
    }

    public static function updateTable(string $tableName, array $structure = []): bool
    {
        if (self::isTableChecked($tableName)) {
            return false;
        }

        return self::withSchemaLock(static function () use ($tableName, $structure): bool {
            // Otra solicitud puede haber terminado mientras esperábamos el bloqueo.
            if (self::isTableChecked($tableName)) {
                return false;
            }
            return self::updateTableUnlocked($tableName, $structure);
        });
    }

    private static function updateTableUnlocked(string $tableName, array $structure): bool
    {
        if (empty($structure)) {
            $filePath = self::getTableXmlLocation($tableName);
            $structure = self::readTableXml($filePath);
        }

        if (empty($structure['columns'])) {
            Tools::log()->critical('No se puede comprobar la tabla ' . $tableName . ': falta una definición XML válida. Reconstruya Dinamic.');
            return false;
        }

        // compare table columns and constraints against xml definition
        $dbCols = self::db()->getColumns($tableName);
        if (!$dbCols || self::db()->lastError() !== '') {
            Tools::log()->error('No se pudo leer la estructura de ' . $tableName . '. Se conserva pendiente su comprobación.');
            return false;
        }
        $dbCons = self::db()->getConstraints($tableName);
        if (self::db()->lastError() !== '') {
            Tools::log()->error('No se pudieron leer las restricciones de ' . $tableName . '. Se conserva pendiente su comprobación.');
            return false;
        }
        $sql = self::compareColumns($tableName, $structure['columns'], $dbCols) .
            self::compareConstraints($tableName, $structure['constraints'], $dbCons);
        if (!empty($sql)) {
            if (false === self::db()->exec($sql)) {
                // Mantener pendiente una actualización fallida para reintentar
                // al cargar el modelo; la tabla aún puede estar incompleta.
                return false;
            }
            self::save($tableName);

            Tools::log()->debug('table-checked', ['%tableName%' => $tableName]);
            return true;
        }

        self::save($tableName);

        Tools::log()->debug('table-checked', ['%tableName%' => $tableName]);
        return false;
    }

    private static function db(): DataBase
    {
        if (null === self::$dataBase) {
            self::$dataBase = new DataBase();
            self::$dataBase->connect();
        }

        return self::$dataBase;
    }

    private static function compareColumns(string $tableName, array $xmlCols, array $dbCols): string
    {
        $sql = '';

        foreach ($xmlCols as $xmlCol) {
            $column = self::searchInArray($dbCols, 'name', $xmlCol['name']);
            if (empty($column)) {
                $sql .= self::sqlTool()->sqlAlterAddColumn($tableName, $xmlCol);
                continue;
            }

            if (false === self::compareDataTypes($column['type'], $xmlCol['type'])) {
                $sql .= self::sqlTool()->sqlAlterModifyColumn($tableName, $xmlCol);
            }

            if ($column['default'] === null && $xmlCol['default'] !== '') {
                $sql .= self::sqlTool()->sqlAlterColumnDefault($tableName, $xmlCol);
            }

            if ($column['is_nullable'] !== $xmlCol['null']) {
                $sql .= self::sqlTool()->sqlAlterColumnNull($tableName, $xmlCol);
            }
        }

        return $sql;
    }

    private static function compareConstraints(string $tableName, array $xmlCons, array $dbCons): string
    {
        if (empty($xmlCons) || false === Tools::config('db_foreign_keys')) {
            return '';
        }

        $xmlCons = InventorySchemaMigration::physicalConstraints($tableName, $xmlCons, $dbCons);
        // Conservar las restricciones que siguen vigentes. Eliminar todas por
        // una sola obsoleta también retiraba índices y relaciones que no se
        // volvían a añadir, porque ya estaban presentes en la comparación.
        $sqlDelete = '';
        $sqlDeleteFK = '';

        foreach ($dbCons as $dbCon) {
            if ($dbCon['type'] === 'PRIMARY KEY') {
                // exclude primary key
                continue;
            }
            $column = self::searchInArray($xmlCons, 'name', $dbCon['name']);
            if (!empty($column)) {
                continue;
            }
            if ($dbCon['type'] === 'FOREIGN KEY') {
                // it is better to delete the foreign keys before the rest
                $sqlDeleteFK .= self::sqlTool()->sqlDropConstraint($tableName, $dbCon);
            } else {
                $sqlDelete .= self::sqlTool()->sqlDropConstraint($tableName, $dbCon);
            }
        }

        // add new constraints
        $sql = '';
        foreach ($xmlCons as $xmlCon) {
            // exclude primary keys because they have no name
            if (0 === strpos($xmlCon['constraint'], 'PRIMARY')) {
                continue;
            }

            $column = self::searchInArray($dbCons, 'name', $xmlCon['name']);
            if (empty($column)) {
                $sql .= self::sqlTool()->sqlAddConstraint($tableName, $xmlCon['name'], $xmlCon['constraint']);
            }
        }

        return $sqlDeleteFK . $sqlDelete . $sql;
    }

    private static function compareDataTypes(string $dbType, string $xmlType): bool
    {
        return self::db()->getEngine()->compareDataTypes($dbType, $xmlType);
    }

    private static function save(string $tableName): void
    {
        self::$checkedTables = TableSchemaCache::remember(self::databaseName(), $tableName, self::$checkedGeneration);
    }

    private static function databaseName(): string
    {
        return defined('FS_DB_NAME') ? FS_DB_NAME : 'default';
    }

    private static function reloadCheckedTables(): void
    {
        $snapshot = TableSchemaCache::read(self::databaseName());
        self::$checkedTables = $snapshot['tables'];
        self::$checkedGeneration = $snapshot['generation'];
        self::$checkedDatabase = self::databaseName();
    }

    private static function withSchemaLock(callable $operation): bool
    {
        $lock = new SchemaCheckLock(self::databaseName());
        $lock->acquire();
        try {
            self::reloadCheckedTables();
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private static function searchInArray(array $items, string $index, string $value): array
    {
        foreach ($items as $column) {
            if ($column[$index] === $value) {
                return $column;
            }
        }

        return [];
    }

    private static function sqlTool(): DataBaseQueries
    {
        if (null === self::$sqlTool) {
            self::$sqlTool = self::db()->getEngine()->getSQL();
        }

        return self::$sqlTool;
    }
}
