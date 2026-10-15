<?php

    /**
     * This file is part of FacturaScripts
     * Copyright (C) 2013-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

    namespace FacturaScripts\Core\Model\Base;

    use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Lib\Inventory\Inventory;
use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\CodeModel;
use RuntimeException;

    /**
     * The class from which all models inherit, connects to the database,
     * check the structure of the table and if necessary create or adapt.
     *
     * @author Carlos García Gómez <carlos@facturascripts.com>
     */
abstract class ModelClass extends ModelCore
{
    private $inventoryLoaded = [];

    public function clear()
    {
        $this->inventoryLoaded = [];
        parent::clear();
    }

    public function loadFromData(array $data = [], array $exclude = [])
    {
        parent::loadFromData($data, $exclude);
        if (empty($this->inventoryLoaded) && !empty($data[static::primaryColumn()])) {
            $this->inventoryLoaded = $this->toArray();
        }
    }

    /** Reject a stale editor instead of repeating the old contribution. */
    public function lockInventorySource(): void
    {
        if (empty($this->primaryColumnValue())) {
            return;
        }
        $rows = self::$dataBase->select('SELECT * FROM ' . static::tableName()
            . ' WHERE ' . static::primaryColumn() . ' = '
            . self::$dataBase->var2str($this->primaryColumnValue()) . ' FOR UPDATE');
        Inventory::requireSuccess(!empty($rows), 'El origen del inventario ya no existe. Recargue el documento.');
        $current = clone $this;
        $current->loadFromData($rows[0]);
        $row = $current->toArray();
        foreach ($this->inventoryLoaded as $field => $value) {
            if (
                in_array(static::tableName(), ['productos', 'variantes'], true)
                && in_array($field, ['stockfis', 'actualizado'], true)
            ) {
                $this->{$field} = $row[$field];
                continue;
            }
            if (array_key_exists($field, $row) && $row[$field] != $value) {
                throw new RuntimeException('El documento cambió en otra operación. Recárguelo antes de guardar.');
            }
        }
    }

    public function inventoryRemember(): void
    {
        $this->inventoryLoaded = $this->toArray();
    }
    /**
     * Returns all models that correspond to the selected filters.
     *
     * @param array $where filters to apply to model records.
     * @param array $order fields to use in the sorting. For example ['code' => 'ASC']
     * @param int $offset
     * @param int $limit
     *
     * @return static[]
     */
    public static function all(array $where = [], array $order = [], int $offset = 0, int $limit = 50): array
    {
        $lockLines = Inventory::active() && (is_subclass_of(static::class, BusinessDocumentLine::class)
            || in_array(static::tableName(), ['serviciosat_trabajos', 'stocks_lineasconteos', 'stocks_lineastransferencias'], true));
        return self::readModels($where, $order, $offset, $limit, $lockLines);
    }

    /**
     * Load current rows and lock them until the caller's transaction ends.
     *
     * @return static[]
     */
    public static function allForUpdate(array $where = [], array $order = [], int $offset = 0, int $limit = 50): array
    {
        return self::readModels($where, $order, $offset, $limit, true);
    }

    private static function readModels(array $where, array $order, int $offset, int $limit, bool $forUpdate): array
    {
        // si todavía no se ha comprobado la tabla o conectado a la base de datos, inicializamos la clase
        if (!DbUpdater::isTableChecked(static::tableName()) || is_null(self::$dataBase) || !self::$dataBase->connected()) {
            new static();
        }

        $modelList = [];
        $sql = 'SELECT * FROM ' . static::tableName() . DataBaseWhere::getSQLWhere($where) . self::getOrderBy($order);
        if ($forUpdate) {
            Inventory::requireSuccess(self::$dataBase->inTransaction(), 'La lectura con bloqueo requiere una transacción.');
        }
        $rows = $forUpdate ? self::$dataBase->select($sql
            . ($limit > 0 ? ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset : '') . ' FOR UPDATE')
            : self::$dataBase->selectLimit($sql, $limit, $offset);
        foreach ($rows as $row) {
            $modelList[] = new static($row);
        }

        return $modelList;
    }

    /**
     * Allows to use this model as source in CodeModel special model.
     *
     * @param string $fieldCode
     *
     * @return CodeModel[]
     */
    public function codeModelAll(string $fieldCode = ''): array
    {
        $results = [];
        $field = empty($fieldCode) ? static::primaryColumn() : $fieldCode;

        $sql = 'SELECT DISTINCT ' . $field . ' AS code, ' . $this->primaryDescriptionColumn() . ' AS description '
            . 'FROM ' . static::tableName() . ' ORDER BY 2 ASC';
        foreach (self::$dataBase->selectLimit($sql, CodeModel::ALL_LIMIT) as $d) {
            $results[] = new CodeModel($d);
        }

        return $results;
    }

    /**
     * Allows to use this model as source in CodeModel special model.
     *
     * @param string $query
     * @param string $fieldCode
     * @param DataBaseWhere[] $where
     *
     * @return CodeModel[]
     */
    public function codeModelSearch(string $query, string $fieldCode = '', array $where = []): array
    {
        $field = empty($fieldCode) ? static::primaryColumn() : $fieldCode;
        $fields = $field . '|' . $this->primaryDescriptionColumn();
        $where[] = new DataBaseWhere($fields, mb_strtolower($query, 'UTF8'), 'LIKE');
        return CodeModel::all(static::tableName(), $field, $this->primaryDescriptionColumn(), false, $where);
    }

    /**
     * Returns the number of records in the model that meet the condition.
     *
     * @param DataBaseWhere[] $where filters to apply to model records.
     *
     * @return int
     */
    public function count(array $where = []): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM ' . static::tableName();

        if ($where) {
            $data = self::$dataBase->select($sql . DataBaseWhere::getSQLWhere($where));
            return empty($data) ? 0 : (int)$data[0]['total'];
        }
        $data = self::$dataBase->select($sql);
        $count = empty($data) ? 0 : (int)$data[0]['total'];
        return $count;
    }

    /**
     * Remove the model data from the database.
     *
     * @return bool
     */
    public function delete(): bool
    {
        return Inventory::model(self::$dataBase, $this, function (): bool {
            return $this->deleteRecord();
        }, 'delete');
    }

    private function deleteRecord(): bool
    {
        if (null === $this->primaryColumnValue()) {
            return true;
        }

        if ($this->pipeFalse('deleteBefore') === false) {
            return false;
        }

        $sql = 'DELETE FROM ' . static::tableName() . ' WHERE ' . static::primaryColumn()
            . ' = ' . self::$dataBase->var2str($this->primaryColumnValue()) . ';';

        if (false === self::$dataBase->exec($sql)) {
            return false;
        }

        Inventory::modelPersisted($this, [], true);
        Cache::deleteMulti('model-' . $this->modelClassName() . '-');
        Cache::deleteMulti('join-model-');
        Cache::deleteMulti('table-' . static::tableName() . '-');

        WorkQueue::send(
            'Model.' . $this->modelClassName() . '.Delete',
            $this->primaryColumnValue(),
            $this->toArray()
        );

        return $this->pipeFalse('delete');
    }

    /**
     * Returns true if the model data is stored in the database.
     *
     * @return bool
     */
    public function exists(): bool
    {
        if ($this instanceof Stock && Inventory::stockWasRead($this->primaryColumnValue())) {
            return true;
        }
        $sql = 'SELECT 1 FROM ' . static::tableName() . ' WHERE ' . static::primaryColumn()
            . ' = ' . self::$dataBase->var2str($this->primaryColumnValue());

        // A prior FOR UPDATE can discover a row newer than the transaction snapshot.
        // Use the same current read when deciding whether to insert or update it.
        if (Inventory::active()) {
            $sql .= ' FOR UPDATE';
        }

        return !empty($this->primaryColumnValue()) && self::$dataBase->select($sql);
    }

    /**
     * Returns the model whose primary column corresponds to the value $cod
     *
     * @param string $code
     *
     * @return static|false
     */
    public function get($code)
    {
        $data = $this->getRecord($code);
        return empty($data) ? false : new static($data[0]);
    }

    /**
     * Fill the class with the registry values
     * whose primary column corresponds to the value $cod, or according to the condition
     * where indicated, if value is not reported in $cod.
     * Initializes the values of the class if there is no record that
     * meet the above conditions.
     * Returns True if the record exists and False otherwise.
     *
     * @param string $code
     * @param array $where
     * @param array $order
     *
     * @return bool
     */
    public function loadFromCode($code, array $where = [], array $order = []): bool
    {
        $data = $this->getRecord($code, $where, $order);
        if (empty($data)) {
            $this->clear();
            return false;
        }

        $this->loadFromData($data[0]);
        $this->inventoryRemember();
        return true;
    }

    /**
     * Returns the following code for the reported field or the primary key of the model.
     *
     * @param string $field
     * @param array $where
     *
     * @return int
     */
    public function newCode(string $field = '', array $where = [])
    {
        // if not field value take PK Field
        if (empty($field)) {
            $field = static::primaryColumn();
        }

        // get fields list
        $modelFields = $this->getModelFields();

        // Set Cast to Integer if field it's not
        if (false === in_array($modelFields[$field]['type'], ['integer', 'int', 'serial'])) {
            // Set Where to Integers values only
            $where[] = new DataBaseWhere($field, '^-?[0-9]+$', 'REGEXP');
            $field = self::$dataBase->getEngine()->getSQL()->sql2Int($field);
        }

        // Search for new code value
        $sqlWhere = DataBaseWhere::getSQLWhere($where);
        $sql = 'SELECT MAX(' . $field . ') as cod FROM ' . static::tableName() . $sqlWhere . ';';
        $data = self::$dataBase->select($sql);
        return empty($data) ? 1 : 1 + (int)$data[0]['cod'];
    }

    /**
     * Returns the name of the column that describes the model, such as name, description...
     *
     * @return string
     */
    public function primaryDescriptionColumn(): string
    {
        $fields = $this->getModelFields();
        return isset($fields['descripcion']) ? 'descripcion' : static::primaryColumn();
    }

    /**
     * Descriptive identifier for humans of the data record
     *
     * @return string
     */
    public function primaryDescription()
    {
        $field = $this->primaryDescriptionColumn();
        return $this->{$field} ?? (string)$this->primaryColumnValue();
    }

    /**
     * Stores the model data in the database.
     *
     * @return bool
     */
    public function save(): bool
    {
        return Inventory::model(self::$dataBase, $this, function (): bool {
            if (!$this->saveRecord()) {
                return false;
            }
            $this->inventoryRemember();
            return true;
        }, 'save');
    }

    private function saveRecord(): bool
    {
        if ($this->pipeFalse('saveBefore') === false) {
            return false;
        }

        if (false === $this->test()) {
            return false;
        }

        $done = $this->exists() ? $this->saveUpdate() : $this->saveInsert();
        if (false === $done) {
            return false;
        }

        WorkQueue::send(
            'Model.' . $this->modelClassName() . '.Save',
            $this->primaryColumnValue(),
            $this->toArray()
        );

        return $this->pipeFalse('save');
    }

    /**
     * Returns true if there are no errors in the values of the model properties.
     * It runs inside the save method.
     *
     * @return bool
     */
    public function test(): bool
    {
        if ($this->pipeFalse('testBefore') === false) {
            return false;
        }

        // comprobamos que los campos no nulos tengan algún valor asignado
        $fields = $this->getModelFields();
        if (empty($fields)) {
            return false;
        }
        $return = true;
        foreach ($fields as $key => $value) {
            if ($key == static::primaryColumn()) {
                $this->{$key} = empty($this->{$key}) ? null : $this->{$key};
            } elseif (null === $value['default'] && $value['is_nullable'] === 'NO' && $this->{$key} === null) {
                Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => $key, '%tableName%' => static::tableName()]);
                $return = false;
            }
        }
        if (false === $return) {
            return false;
        }

        return $this->pipeFalse('test');
    }

    public function totalSum(string $field, array $where = []): float
    {
        $sql = 'SELECT SUM(' . self::$dataBase->escapeColumn($field) . ') AS total FROM ' . static::tableName();

        if ($where) {
            $data = self::$dataBase->select($sql . DataBaseWhere::getSQLWhere($where));
            return empty($data) ? 0 : (float)$data[0]['total'];
        }

        $key = 'model-' . $this->modelClassName() . '-' . $field . '-total-sum';
        $sum = Cache::get($key);
        if (is_null($sum)) {
            $data = self::$dataBase->select($sql);
            $sum = empty($data) ? 0 : (float)$data[0]['total'];
            Cache::set($key, $sum);
        }

        return $sum;
    }

    /**
     * Returns the url where to see / modify the data.
     *
     * @param string $type
     * @param string $list
     *
     * @return string
     */
    public function url(string $type = 'auto', string $list = 'List'): string
    {
        $value = $this->primaryColumnValue();
        $model = $this->modelClassName();

        $return = $this->pipe('url', $type, $list);
        if ($return) {
            return $return;
        }

        switch ($type) {
            case 'edit':
                return is_null($value) ? 'Edit' . $model : 'Edit' . $model . '?code=' . rawurlencode($value);

            case 'list':
                return $list . $model;

            case 'new':
                return 'Edit' . $model;
        }

        // default
        return empty($value) ? $list . $model : 'Edit' . $model . '?code=' . rawurlencode($value);
    }

    /**
     * Insert the model data in the database.
     *
     * @param array $values
     *
     * @return bool
     */
    protected function saveInsert(array $values = []): bool
    {
        if ($this->pipeFalse('saveInsertBefore') === false) {
            return false;
        }

        $insertFields = [];
        $insertValues = [];
        foreach ($this->getModelFields() as $field) {
            if (isset($this->{$field['name']})) {
                $fieldName = $field['name'];
                $fieldValue = $values[$fieldName] ?? $this->{$fieldName};

                $insertFields[] = self::$dataBase->escapeColumn($fieldName);
                $insertValues[] = self::$dataBase->var2str($fieldValue);
            }
        }

        $sql = 'INSERT INTO ' . static::tableName() . ' (' . implode(',', $insertFields)
            . ') VALUES (' . implode(',', $insertValues) . ');';
        if (false === self::$dataBase->exec($sql)) {
            return false;
        }

        $autoInserted = $this->primaryColumnValue() === null;
        if ($autoInserted) {
            $this->{static::primaryColumn()} = self::$dataBase->generatedId();
        } else {
            self::$dataBase->updateSequence(static::tableName(), $this->getModelFields());
        }

        Inventory::modelPersisted($this, $values, false, $autoInserted);
        Cache::deleteMulti('model-' . $this->modelClassName() . '-');
        Cache::deleteMulti('join-model-');
        Cache::deleteMulti('table-' . static::tableName() . '-');

        WorkQueue::send(
            'Model.' . $this->modelClassName() . '.Insert',
            $this->primaryColumnValue(),
            $this->toArray()
        );

        return $this->pipeFalse('saveInsert');
    }

    /**
     * Update the model data in the database.
     *
     * @param array $values
     *
     * @return bool
     */
    protected function saveUpdate(array $values = []): bool
    {
        if ($this->pipeFalse('saveUpdateBefore') === false) {
            return false;
        }

        $sql = 'UPDATE ' . static::tableName();
        $coma = ' SET';

        foreach ($this->getModelFields() as $field) {
            if ($field['name'] !== static::primaryColumn()) {
                $fieldName = $field['name'];
                $fieldValue = $values[$fieldName] ?? $this->{$fieldName};
                $sql .= $coma . ' ' . self::$dataBase->escapeColumn($fieldName) . ' = ' . self::$dataBase->var2str($fieldValue);
                $coma = ', ';
            }
        }

        $sql .= ' WHERE ' . static::primaryColumn() . ' = ' . self::$dataBase->var2str($this->primaryColumnValue()) . ';';
        if (false === self::$dataBase->exec($sql)) {
            return false;
        }

        Inventory::modelPersisted($this, $values);
        Cache::deleteMulti('model-' . $this->modelClassName() . '-');
        Cache::deleteMulti('join-model-');
        Cache::deleteMulti('table-' . static::tableName() . '-');

        WorkQueue::send(
            'Model.' . $this->modelClassName() . '.Update',
            $this->primaryColumnValue(),
            $this->toArray()
        );

        return $this->pipeFalse('saveUpdate');
    }

    /**
     * Convert an array of filters order by in string.
     *
     * @param array $order
     *
     * @return string
     */
    private static function getOrderBy(array $order): string
    {
        $result = '';
        $coma = ' ORDER BY ';
        foreach ($order as $key => $value) {
            $result .= $coma . $key . ' ' . $value;
            $coma = ', ';
        }

        return $result;
    }

    /**
     * Read the record whose primary column corresponds to the value $cod
     * or the first that meets the indicated condition.
     *
     * @param string $code
     * @param array $where
     * @param array $order
     *
     * @return array
     */
    private function getRecord($code, array $where = [], array $order = []): array
    {
        $sqlWhere = empty($where) ?
            ' WHERE ' . static::primaryColumn() . ' = ' . self::$dataBase->var2str($code) :
            DataBaseWhere::getSQLWhere($where);
        $sql = 'SELECT * FROM ' . static::tableName() . $sqlWhere . self::getOrderBy($order);
        if (
            (!empty($code) || !empty($where)) && Inventory::active() && Inventory::applies($this)
            && !($this instanceof Stock)
        ) {
            // MySQL repeatable-read snapshots may predate the acquired source lock.
            $rows = self::$dataBase->select($sql . ' LIMIT 1 FOR UPDATE');
            if ($rows) {
                Inventory::rememberCatalogRead(static::tableName(), $rows[0]);
            }
            return $rows;
        }
        return empty($code) && empty($where) ? [] : self::$dataBase->selectLimit($sql, 1);
    }
}
