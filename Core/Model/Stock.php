<?php

/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Inventory\Inventory;
use FacturaScripts\Core\Lib\Inventory\StockTotals;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Model\Base\ProductRelationTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Almacen as DinAlmacen;
use FacturaScripts\Dinamic\Model\Producto as DinProducto;
use FacturaScripts\Dinamic\Model\Variante as DinVariante;

/**
 * The quantity in inventory of an item in a particular warehouse.
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class Stock extends ModelClass
{
    use ModelTrait;
    use ProductRelationTrait;

    const MAX_DECIMALS = 3;

    private $inventoryOriginal = [];
    private $absoluteQuantity = false;
    private $requireNonNegative = false;
    private $saleAllowsNegative;

    public function loadFromData(array $data = [], array $exclude = [])
    {
        parent::loadFromData($data, $exclude);
        if (empty($this->inventoryOriginal) && !empty($data['idstock'])) {
            $this->inventoryOriginal = $this->toArray();
        }
    }

    public function loadFromCode($code, array $where = [], array $order = []): bool
    {
        if (!parent::loadFromCode($code, $where, $order)) {
            return false;
        }
        $this->inventoryOriginal = $this->toArray();
        return true;
    }

    /** Counts/imports replace the physical balance at the instant of application. */
    public function saveAbsolute(): bool
    {
        $this->absoluteQuantity = true;
        try {
            return $this->save();
        } finally {
            $this->absoluteQuantity = false;
        }
    }

    public function requireNonNegative(bool $required = true): void
    {
        $this->requireNonNegative = $required;
    }

    /** Apply the sold recipe's policy only to this ingredient write. */
    public function saveForSale(bool $allowNegative): bool
    {
        // Capture the model before installing the policy, including in an outer
        // transaction, so rollback cannot restore a temporary permission later.
        return Inventory::run(self::$dataBase, function () use ($allowNegative): bool {
            $previousPolicy = $this->saleAllowsNegative;
            $this->saleAllowsNegative = $allowNegative;
            try {
                return $this->save();
            } finally {
                $this->saleAllowsNegative = $previousPolicy;
            }
        }, $this, 'save');
    }

    /**
     * Quantity.
     *
     * @var float|int
     */
    public $cantidad;

    /**
     * Warehouse code.
     *
     * @var string
     */
    public $codalmacen;

    /**
     * Available. Is the quantity minus reserved.
     *
     * @var float|int
     */
    public $disponible;

    /**
     * Primary key.
     *
     * @var int
     */
    public $idstock;

    /**
     * Pending receipt. Merchandise pending receipt from the supplier.
     *
     * @var float|int
     */
    public $pterecibir;

    /**
     * Reference.
     *
     * @var string
     */
    public $referencia;

    /**
     * Reserved on customer orders.
     *
     * @var float|int
     */
    public $reservada;

    /**
     * Maximum stock.
     *
     * @var float|int
     */
    public $stockmax;

    /**
     * Minimum stock.
     *
     * @var float|int
     */
    public $stockmin;

    /**
     * @var string
     */
    public $ubicacion;

    public function clear()
    {
        parent::clear();
        $this->cantidad = 0.0;
        $this->codalmacen = Tools::settings('default', 'codalmacen');
        $this->disponible = 0.0;
        $this->pterecibir = 0.0;
        $this->reservada = 0.0;
        $this->stockmax = 0.0;
        $this->stockmin = 0.0;
        $this->inventoryOriginal = [];
    }

    public function delete(): bool
    {
        return Inventory::run(self::$dataBase, function (): bool {
            $rows = self::$dataBase->select('SELECT * FROM stocks WHERE idstock = '
                . self::$dataBase->var2str($this->idstock) . ' FOR UPDATE');
            if (!$rows) {
                return true;
            }
            foreach (['cantidad', 'reservada', 'pterecibir'] as $field) {
                if (abs((float)$rows[0][$field]) > 0.0005) {
                    Tools::log()->warning('Aplique un ajuste explícito y libere las reservas antes de eliminar el saldo.');
                    return false;
                }
            }
            if (!parent::delete()) {
                return false;
            }
            return (float) $rows[0]['cantidad'] !== 0.0
                ? $this->updateProductStock() : $this->writeProductStock();
        }, $this, 'delete');
    }

    public function install(): string
    {
        // needed dependencies
        new DinAlmacen();
        new DinProducto();
        new DinVariante();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idstock';
    }

    public function save(): bool
    {
        return Inventory::run(self::$dataBase, function (): bool {
            if (!$this->test()) {
                return false;
            }
            // The product lock also serializes creation of a previously absent stock row
            // and updates of product totals across variants and warehouses.
            $products = self::$dataBase->select('SELECT * FROM productos WHERE idproducto = '
                . self::$dataBase->var2str($this->idproducto) . ' FOR UPDATE');
            Inventory::requireSuccess(!empty($products), 'Producto de inventario inexistente.');
            $variants = self::$dataBase->select('SELECT idproducto FROM variantes WHERE referencia = '
                . self::$dataBase->var2str($this->referencia) . ' FOR UPDATE');
            Inventory::requireSuccess(
                count($variants) === 1 && $variants[0]['idproducto'] == $this->idproducto,
                'La referencia no pertenece al producto del saldo.'
            );
            foreach (['referencia', 'codalmacen', 'idproducto'] as $identity) {
                Inventory::requireSuccess(
                    !isset($this->inventoryOriginal[$identity])
                    || $this->inventoryOriginal[$identity] == $this->{$identity},
                    'Use una transferencia para cambiar la identidad o el almacén de un saldo.'
                );
            }
            $rows = self::$dataBase->select('SELECT * FROM stocks WHERE referencia = '
                . self::$dataBase->var2str($this->referencia) . ' AND codalmacen = '
                . self::$dataBase->var2str($this->codalmacen) . ' FOR UPDATE');
            Inventory::requireSuccess(count($rows) <= 1, 'Hay saldos duplicados; requieren revisión manual.');
            $current = $rows[0] ?? [];
            Inventory::requireSuccess(
                !$current || $current['idproducto'] == $this->idproducto,
                'El saldo histórico está vinculado a otro producto. Requiere revisión manual.'
            );
            $before = $after = [];
            foreach (['cantidad', 'reservada', 'pterecibir'] as $field) {
                $before[$field] = round((float)($current[$field] ?? 0), self::MAX_DECIMALS);
                $delta = round($this->{$field} - ($this->inventoryOriginal[$field] ?? 0), self::MAX_DECIMALS);
                $after[$field] = ($this->absoluteQuantity && $field === 'cantidad')
                    ? $this->cantidad : round($before[$field] + $delta, self::MAX_DECIMALS);
            }
            $delta = $after['cantidad'] - $before['cantidad'];
            foreach (['reservada', 'pterecibir'] as $field) {
                Inventory::requireSuccess(
                    $after[$field] >= min(0, $before[$field]),
                    'El movimiento generaría compromisos negativos. Revise las cantidades pendientes.'
                );
            }
            Inventory::requireSuccess(empty($products[0]['nostock']) || $before == $after
                || array_sum(array_map('abs', $after)) === 0.0, 'El producto no controla existencias.');
            if (
                !$this->absoluteQuantity && $delta < 0 && $after['cantidad'] < 0
                && ($this->requireNonNegative || !($this->saleAllowsNegative ?? !empty($products[0]['ventasinstock'])))
            ) {
                Tools::log()->warning('not-enough-stock', ['%reference%' => $this->referencia]);
                return false;
            }
            foreach (['stockmin', 'stockmax', 'ubicacion'] as $field) {
                if (isset($current[$field]) && $this->{$field} == ($this->inventoryOriginal[$field] ?? null)) {
                    $this->{$field} = $current[$field];
                }
            }
            $this->idstock = $current['idstock'] ?? null;
            foreach ($after as $field => $value) {
                $this->{$field} = $value;
            }
            if (!parent::save()) {
                return false;
            }
            // Compare persisted precision, not the rounded journal delta: normalizing
            // a legacy balance with extra decimals can still change the physical total.
            $physicalChanged = (float) ($current['cantidad'] ?? 0) !== (float) $this->cantidad;
            if (!($physicalChanged ? $this->updateProductStock() : $this->writeProductStock())) {
                return false;
            }
            Inventory::record($this, $before, $after);
            $this->inventoryOriginal = $this->toArray();
            return true;
        }, $this, $this->absoluteQuantity ? 'adjustment' : 'save');
    }

    public static function tableName(): string
    {
        return 'stocks';
    }

    /**
     * Transfer $qty unities of stock to $toWarehouse
     *
     * @param string $toWarehouse destination warehouse
     * @param float $qty quantity to move
     * @param ModelClass|null $source persisted document line identifying the transfer
     * @param bool $reversal compensate the lots allocated to that source
     *
     * @return bool
     */
    public function transferTo(string $toWarehouse, float $qty, ?ModelClass $source = null, bool $reversal = false): bool
    {
        return Inventory::run(self::$dataBase, function () use ($toWarehouse, $qty, $source, $reversal): bool {
            if ($toWarehouse === $this->codalmacen || $qty == 0) {
                return true;
            }
            $destination = new static();
            $where = [
                new DataBaseWhere('codalmacen', $toWarehouse),
                new DataBaseWhere('referencia', $this->referencia)
            ];
            if ($destination->loadFromCode('', $where)) {
                $destination->cantidad += $qty;
            } else {
                $destination->codalmacen = $toWarehouse;
                $destination->idproducto = $this->idproducto;
                $destination->referencia = $this->referencia;
                $destination->cantidad = $qty;
            }

            $this->cantidad -= $qty;
            $this->requireNonNegative($qty > 0);
            $destination->requireNonNegative($qty < 0);
            $done = strcmp($this->codalmacen, $toWarehouse) < 0
                ? $this->save() && $destination->save() : $destination->save() && $this->save();
            return $done && $this->pipeFalse('transferTo', $toWarehouse, $qty, $source, $reversal);
        }, $this, 'transfer');
    }

    public function test(): bool
    {
        foreach (['cantidad', 'reservada', 'pterecibir'] as $field) {
            if (!is_finite((float)$this->{$field})) {
                return false;
            }
        }
        $this->ubicacion = Tools::noHtml($this->ubicacion);

        $this->cantidad = round($this->cantidad, self::MAX_DECIMALS);
        $this->reservada = round($this->reservada, self::MAX_DECIMALS);
        $this->pterecibir = round($this->pterecibir, self::MAX_DECIMALS);
        $this->disponible = max([0, $this->cantidad - $this->reservada]);

        $this->referencia = Tools::noHtml($this->referencia);
        if (empty($this->idproducto)) {
            $variante = new DinVariante();
            $whereRef = [new DataBaseWhere('referencia', $this->referencia)];
            if ($variante->loadFromCode('', $whereRef)) {
                $this->idproducto = $variante->idproducto;
            }
        }

        return parent::test();
    }

    /**
     * Returns the total stock of the product.
     *
     * @param int $idproducto
     * @param string $referencia
     *
     * @return float
     */
    public function totalFromProduct(int $idproducto, string $referencia = ''): float
    {
        $sql = 'SELECT SUM(cantidad) AS total FROM ' . static::tableName()
            . ' WHERE idproducto = ' . self::$dataBase->var2str($idproducto);

        if (!empty($referencia)) {
            $sql .= ' AND referencia = ' . self::$dataBase->var2str($referencia);
        }

        $data = self::$dataBase->select($sql);
        return empty($data) ? 0.0 : round((float)$data[0]['total'], self::MAX_DECIMALS);
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return $this->getProducto()->url($type);
    }

    protected function updateProductStock(): bool
    {
        return $this->writeProductStock(StockTotals::get(self::$dataBase, $this->idproducto, $this->referencia));
    }

    private function writeProductStock(?array $totals = null): bool
    {
        // These are derived totals, not product edits: avoid invoking product and
        // variant save hooks recursively while their stock locks are held. Preserve
        // the product's update timestamp even for reservations or location changes.
        $sql = 'UPDATE ' . DinProducto::tableName() . ' SET actualizado = '
            . self::$dataBase->var2str(Tools::dateTime());
        if ($totals !== null) {
            $sql .= ', stockfis = ' . self::$dataBase->var2str($totals[0]);
        }
        $sql .= ' WHERE idproducto = ' . self::$dataBase->var2str($this->idproducto) . ';';

        if ($totals !== null) {
            $sql .= 'UPDATE ' . DinVariante::tableName() . ' SET stockfis = ' . self::$dataBase->var2str($totals[1])
                . ' WHERE referencia = ' . self::$dataBase->var2str($this->referencia) . ';';
        }

        return self::$dataBase->exec($sql);
    }
}
