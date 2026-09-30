<?php

namespace FacturaScripts\Core\Model;

use FacturaScripts\Core\Lib\Inventory\Inventory;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

/** Persistent request claim. A rolled back operation leaves no claim behind. */
class InventoryOperation extends ModelClass
{
    use ModelTrait;

    public $id;
    public $fingerprint;
    public $result;
    public $created;

    public static function primaryColumn(): string
    {
        return 'id';
    }
    public static function tableName(): string
    {
        return 'inventory_operations';
    }

    public function claim(string $id, array $payload): bool
    {
        Inventory::requireSuccess(self::$dataBase->inTransaction(), 'La operación requiere una transacción.');
        $fingerprint = hash('sha256', json_encode(self::normalize($payload), JSON_THROW_ON_ERROR));
        $key = hash('sha256', $id);
        // Claiming an absent key must be atomic; a load-then-save ORM sequence
        // would allow concurrent requests to create the same operation.
        $sql = 'INSERT INTO inventory_operations (id, fingerprint, created) VALUES ('
            . self::$dataBase->var2str($key) . ', ' . self::$dataBase->var2str($fingerprint) . ', '
            . self::$dataBase->var2str(Tools::dateTime()) . ')';
        $sql .= Tools::config('db_type') === 'postgresql' ? ' ON CONFLICT (id) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE id = id';
        if (!self::$dataBase->exec($sql)) {
            return false;
        }

        $rows = self::$dataBase->select('SELECT * FROM inventory_operations WHERE id = '
            . self::$dataBase->var2str($key) . ' FOR UPDATE');
        if (empty($rows) || !hash_equals($rows[0]['fingerprint'], $fingerprint)) {
            Tools::log()->warning('La operación ya se utilizó con otros datos. Recargue antes de continuar.');
            return false;
        }

        $this->loadFromData($rows[0]);
        return true;
    }

    public function complete(array $result): bool
    {
        $this->result = json_encode($result, JSON_THROW_ON_ERROR);
        return $this->save();
    }

    private static function normalize(array $payload): array
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $value = self::normalize($value);
            }
        }

        return $payload;
    }
}
