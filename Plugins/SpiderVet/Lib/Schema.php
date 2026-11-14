<?php

namespace FacturaScripts\Plugins\SpiderVet\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\MysqlEngine;
use FacturaScripts\Core\DbUpdater;
use RuntimeException;

class Schema
{
    /** Match the existing customer key's collation before creating the new FK. */
    public static function preparePatient(VetRecord $model): void
    {
        if (DbUpdater::isTableChecked($model->tableName())) {
            return;
        }
        $db = new DataBase();
        $db->connect();
        if (!($db->getEngine() instanceof MysqlEngine) || $db->tableExists($model->tableName())) {
            return;
        }
        $columns = $db->select("SHOW FULL COLUMNS FROM clientes WHERE Field = 'codcliente'");
        $collation = $columns[0]['Collation'] ?? '';
        if (!preg_match('/^([a-zA-Z0-9]+)_[a-zA-Z0-9_]+$/D', $collation, $matches)) {
            throw new RuntimeException('No se pudo determinar la intercalación del código de cliente.');
        }
        $structure = DbUpdater::readTableXml(DbUpdater::getTableXmlLocation($model->tableName()));
        $structure['columns']['codcliente']['type'] = 'varchar(10) CHARACTER SET '
            . $matches[1] . ' COLLATE ' . $collation;
        if (!DbUpdater::createTable($model->tableName(), $structure)) {
            throw new RuntimeException('No se pudo crear la tabla de pacientes de SpiderVet.');
        }
    }
}
