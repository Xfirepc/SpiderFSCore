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
use FacturaScripts\Core\Lib\Inventory\Inventory;
use FacturaScripts\Core\Lib\Inventory\RefundQuantity;
use FacturaScripts\Core\Lib\Inventory\StockContribution;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Description of BusinessDocumentLine
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
abstract class BusinessDocumentLine extends ModelOnChangeClass
{
    use TaxRelationTrait;

    /**
     * Update stock status.
     *
     * @var int
     */
    public $actualizastock;

    /** Physical delivery already recorded by another source (e.g. Gym). */
    public $stockneutral = false;

    /** Explicit component parent; never identify a component by reference alone. */
    public $stockparent;

    /** Resolved ingredient quantities, stored when this line first affects inventory. */
    public $stockcomposition;
    public $stockpack;
    public $stockfactor;
    public $stockcancelmode;
    public $stocktracesource;
    public $stockreturnsource;
    public $stockcompositionsource;
    public $allowComponentDelete = false;

    public function lockInventorySource(): void
    {
        // A warehouse/status change and a line insert must share the header lock.
        $document = $this->getDocument();
        Inventory::requireSuccess(
            !empty($document->primaryColumnValue()) && (Inventory::sourceWasInserted($document) || !empty(self::$dataBase->select(
                'SELECT ' . $document::primaryColumn() . ' FROM ' . $document::tableName()
                . ' WHERE ' . $document::primaryColumn() . ' = '
                . self::$dataBase->var2str($document->primaryColumnValue()) . ' FOR UPDATE'
            ))),
            'El documento de origen ya no existe.'
        );
        parent::lockInventorySource();
    }

    /**
     * Quantity.
     *
     * @var float|int
     */
    public $cantidad;

    /**
     * Description of the line.
     *
     * @var string
     */
    public $descripcion;

    /** @var bool */
    private $disableUpdateStock = false;

    /** @var array */
    protected static $dont_copy_fields = ['idlinea', 'orden', 'servido'];

    /**
     * Percentage of discount.
     *
     * @var float|int
     */
    public $dtopor;

    /**
     * Percentage of second discount.
     *
     * @var float|int
     */
    public $dtopor2;

    /** @var string */
    public $excepcioniva;

    /**
     * Primary key.
     *
     * @var int
     */
    public $idlinea;

    /** @var int */
    public $idproducto;

    /**
     * % of IRPF of the line.
     *
     * @var float|int
     */
    public $irpf;

    /**
     * % of the related tax.
     *
     * @var float|int
     */
    public $iva;

    /**
     * Position of the line in the document. The higher down.
     *
     * @var int
     */
    public $orden;

    /**
     * Net amount without discounts.
     *
     * @var float|int
     */
    public $pvpsindto;

    /**
     * Net amount of the line, without taxes.
     *
     * @var float|int
     */
    public $pvptotal;

    /**
     * Price of the item, one unit.
     *
     * @var float|int
     */
    public $pvpunitario;

    /**
     * % surcharge of line equivalence.
     *
     * @var float|int
     */
    public $recargo;

    /**
     * Reference of the article.
     *
     * @var string
     */
    public $referencia;

    /**
     * Served.
     *
     * @var float|int
     */
    public $servido;

    /** @var bool */
    public $suplido;

    /**
     * Returns the parent document of this line.
     */
    abstract public function getDocument();

    /**
     * Returns the name of the column to store the document's identifier.
     */
    abstract public function documentColumn();

    public function clear()
    {
        parent::clear();
        $this->actualizastock = 0;
        $this->cantidad = 1.0;
        $this->descripcion = '';
        $this->dtopor = 0.0;
        $this->dtopor2 = 0.0;
        $this->irpf = 0.0;
        $this->orden = 0;
        $this->pvpsindto = 0.0;
        $this->pvptotal = 0.0;
        $this->pvpunitario = 0.0;
        $this->servido = 0.0;
        $this->suplido = false;

        // default tax
        $this->codimpuesto = Tools::settings('default', 'codimpuesto');
        $this->iva = $this->getTax()->iva;
        $this->recargo = $this->getTax()->recargo;
    }

    public function disableUpdateStock(bool $value): void
    {
        $this->disableUpdateStock = $value;
    }

    /**
     * Returns the identifier of the document.
     *
     * @return int
     */
    public function documentColumnValue()
    {
        return $this->{$this->documentColumn()};
    }

    public static function dontCopyField(string $field): void
    {
        static::$dont_copy_fields[] = $field;
    }

    public static function dontCopyFields(): array
    {
        $more = [static::primaryColumn()];
        return array_merge(static::$dont_copy_fields, $more);
    }

    public function getDisableUpdateStock(): bool
    {
        return $this->disableUpdateStock;
    }

    /**
     * Returns the Equivalent Unified Discount.
     *
     * @return float
     */
    public function getEUDiscount()
    {
        $eud = 1.0;
        foreach ([$this->dtopor, $this->dtopor2] as $dto) {
            $eud *= 1 - $dto / 100;
        }

        return $eud;
    }

    public function getProducto(): Producto
    {
        $producto = new Producto();

        // for backward compatibility we must search by reference
        if (empty($this->idproducto) && !empty($this->referencia)) {
            $this->idproducto = $this->getVariante()->idproducto;
        }

        $producto->loadFromCode($this->idproducto);
        return $producto;
    }

    public function getVariante(): Variante
    {
        $variante = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variante->loadFromCode('', $where);
        return $variante;
    }

    public function install(): string
    {
        // needed dependencies
        new Impuesto();
        new Producto();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    public function save(): bool
    {
        try {
            return Inventory::run(self::$dataBase, function (): bool {
                if (!RefundQuantity::validate(self::$dataBase, $this)) {
                    return false;
                }
                if (
                    $this->idlinea && (($this->previousData[$this->documentColumn()] ?? $this->documentColumnValue()) != $this->documentColumnValue()
                    || (!empty($this->previousData['stockparent']) && $this->previousData['stockparent'] != $this->stockparent))
                ) {
                    Tools::log()->warning('La línea conserva su documento y su pack de origen. Cree una línea nueva para cambiar de origen.');
                    return false;
                }
                if ($this->stockcancelmode !== null) {
                    $this->actualizastock = (int)$this->stockcancelmode;
                    $this->servido = 0;
                }
                if (!empty($this->stockparent)) {
                    $rows = self::$dataBase->select('SELECT * FROM ' . static::tableName()
                        . ' WHERE idlinea = ' . self::$dataBase->var2str($this->stockparent)
                        . ' AND ' . $this->documentColumn() . ' = '
                        . self::$dataBase->var2str($this->documentColumnValue()) . ' FOR UPDATE');
                    Inventory::requireSuccess(!empty($rows), 'No existe la línea padre del componente.');
                    if (!empty($this->idlinea)) {
                        $desired = $this->toArray();
                        if (!$this->loadFromCode($this->idlinea)) {
                            return false;
                        }
                        foreach (['pvpunitario', 'dtopor', 'descripcion', 'orden'] as $field) {
                            $this->{$field} = $desired[$field];
                        }
                    }
                    $this->cantidad = $rows[0]['cantidad'] * $this->stockfactor;
                    $this->servido = $rows[0]['servido'] * $this->stockfactor;
                    $this->actualizastock = (int)$rows[0]['actualizastock'];
                }
                return parent::save();
            }, $this, 'save');
        } finally {
            $this->disableUpdateStock(false);
        }
    }

    public function setPriceWithTax(float $price): void
    {
        $newPrice = (100 * $price) / (100 + $this->getTax()->iva);
        $this->pvpunitario = round($newPrice, Producto::ROUND_DECIMALS);
    }

    public function delete(): bool
    {
        if ($this->stockparent && !$this->allowComponentDelete) {
            Tools::log()->warning('El componente pertenece a un pack. Modifique o elimine la línea padre.');
            return false;
        }
        if ((int)$this->stockcancelmode === -1) {
            Tools::log()->warning('La línea registra consumo o merma. Registre una devolución física o un ajuste explícito.');
            return false;
        }
        return Inventory::run(self::$dataBase, function (): bool {
            return RefundQuantity::validateSource(self::$dataBase, $this, true)
                && parent::delete();
        }, $this, 'delete');
    }

    /**
     * Transfers the line stock from one warehouse to another.
     *
     * @param string $fromCodalmacen
     * @param string $toCodalmacen
     *
     * @return bool
     */
    public function transfer($fromCodalmacen, $toCodalmacen): bool
    {
        return Inventory::run(self::$dataBase, function () use ($fromCodalmacen, $toCodalmacen): bool {
            if ($fromCodalmacen === $toCodalmacen) {
                return true;
            }
            if (empty($this->referencia) || $this->getProducto()->nostock || !empty($this->stockneutral)) {
                return $this->pipeFalse('transfer', $fromCodalmacen, $toCodalmacen, $this->getDocument());
            }
            $fromStock = $this->stockForWarehouse($fromCodalmacen, $this->referencia, $this->idproducto);
            $this->applyStockChanges($fromStock, $this->previousData['actualizastock'], $this->previousData['cantidad'] * -1, $this->previousData['servido'] * -1);
            if (!$fromStock->save()) {
                return false;
            }

            $toStock = $this->stockForWarehouse(
                $toCodalmacen,
                $this->referencia,
                $this->idproducto ?? $this->getProducto()->idproducto
            );

            $this->applyStockChanges($toStock, $this->actualizastock, $this->cantidad, $this->servido);
            if ($toStock->save()) {
                return $this->pipeFalse('transfer', $fromCodalmacen, $toCodalmacen, $this->getDocument());
            }

            return false;
        }, $this, 'transfer');
    }

    /**
     * Returns True if there is no errors on properties values.
     *
     * @return bool
     */
    public function test(): bool
    {
        if (empty($this->codimpuesto)) {
            $this->codimpuesto = null;
        }

        if ($this->servido < 0 && $this->cantidad >= 0) {
            $this->servido = 0.0;
        }
        if (
            !is_finite((float)$this->cantidad) || !is_finite((float)$this->servido)
            || ($this->cantidad >= 0 && $this->servido > $this->cantidad + 0.0005)
            || ($this->cantidad < 0 && ($this->servido > 0 || $this->servido < $this->cantidad - 0.0005))
        ) {
            Tools::log()->warning('La cantidad servida debe estar dentro de la cantidad de la línea.');
            return false;
        }

        $this->descripcion = Tools::noHtml($this->descripcion);
        $this->referencia = Tools::noHtml($this->referencia);

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        $name = str_replace('Linea', '', $this->modelClassName());
        return $type === 'new' ? 'Edit' . $name : parent::url($type, 'List' . $name . '?activetab=List');
    }

    /**
     * Apply stock modifications according to $mode.
     *
     * @param Stock $stock
     * @param int $mode
     * @param float $quantity
     * @param float $served
     */
    private function applyStockChanges(Stock $stock, int $mode, float $quantity, float $served): void
    {
        $contribution = StockContribution::fromLine([
            'actualizastock' => $mode,
            'cantidad' => $quantity,
            'servido' => $served,
        ]);
        foreach ($contribution as $field => $delta) {
            $stock->{$field} += $delta;
        }
    }

    /**
     * This method is called before save (update) in the database this record
     * data when some field value has changed.
     *
     * @param string $field
     *
     * @return bool
     */
    protected function onChange($field)
    {
        switch ($field) {
            case 'actualizastock':
            case 'cantidad':
            case 'servido':
            case 'referencia':
            case 'idproducto':
            case 'stockneutral':
                return $this->updateStock() && parent::onChange($field);
        }

        return parent::onChange($field);
    }

    /**
     * This method is called after this record is deleted from database.
     */
    protected function onDelete()
    {
        $this->cantidad = 0.0;
        $this->servido = 0.0;
        Inventory::requireSuccess($this->updateStock());
        parent::onDelete();
    }

    protected function saveInsert(array $values = []): bool
    {
        return $this->updateStock() && parent::saveInsert($values);
    }

    protected function setPreviousData(array $fields = [])
    {
        $more = ['actualizastock', 'cantidad', 'servido', 'referencia', 'idproducto', 'stockneutral', 'stockparent', $this->documentColumn()];
        parent::setPreviousData(array_merge($more, $fields));

        if (null === $this->previousData['actualizastock']) {
            $this->previousData['actualizastock'] = 0;
        }

        if (null === $this->previousData['cantidad']) {
            $this->previousData['cantidad'] = 0.0;
        }
    }

    private function stockForWarehouse(string $warehouse, string $reference, ?int $productId): Stock
    {
        $stock = new Stock();
        if (
            Inventory::catalogRead('productos', $productId) !== null
            && Inventory::catalogRead('variantes', $reference) !== null
        ) {
            // Both catalogue locks are already held. Read all matching raw rows
            // to retain duplicate detection and driver types for save hooks.
            $rows = self::$dataBase->select('SELECT * FROM stocks WHERE referencia = '
                . self::$dataBase->var2str($reference) . ' AND codalmacen = '
                . self::$dataBase->var2str($warehouse) . ' FOR UPDATE');
            Inventory::requireSuccess(count($rows) <= 1, 'Hay saldos duplicados; requieren revisión manual.');
            if ($rows) {
                $stock->loadFromData($rows[0]);
                Inventory::rememberStockRow($rows[0]);
                return $stock;
            }
            $stock->codalmacen = $warehouse;
            $stock->referencia = $reference;
            $stock->idproducto = $productId;
            return $stock;
        }
        $where = [
            new DataBaseWhere('codalmacen', $warehouse),
            new DataBaseWhere('referencia', $reference),
        ];
        if (!$stock->loadFromCode('', $where)) {
            $stock->codalmacen = $warehouse;
            $stock->referencia = $reference;
            $stock->idproducto = $productId;
        }
        return $stock;
    }

    /**
     * Updates stock according to line data and $codalmacen warehouse.
     *
     * @return bool
     */
    protected function updateStock(): bool
    {
        if ($this->disableUpdateStock) {
            return true;
        } elseif (empty($this->actualizastock) && empty($this->previousData['actualizastock'])) {
            return true;
        }

        $doc = $this->getDocument();
        $oldReference = $this->previousData['referencia'] ?? $this->referencia;
        Inventory::setContextDocument($this, $doc);
        $references = array_unique(array_filter([$oldReference, $this->referencia]));
        sort($references, SORT_STRING);
        foreach ($references as $reference) {
            $variant = new Variante();
            if (!$variant->loadFromCode('', [new DataBaseWhere('referencia', $reference)])) {
                return false;
            }
            $product = $variant->getProducto();
            if ($product->nostock) {
                continue;
            }
            $stock = $this->stockForWarehouse($doc->codalmacen, $reference, $variant->idproducto);
            if ($reference === $oldReference && empty($this->previousData['stockneutral'])) {
                $this->applyStockChanges(
                    $stock,
                    (int)$this->previousData['actualizastock'],
                    -(float)$this->previousData['cantidad'],
                    -(float)($this->previousData['servido'] ?? 0)
                );
            }
            if ($reference === $this->referencia && empty($this->stockneutral)) {
                $this->applyStockChanges($stock, (int)$this->actualizastock, $this->cantidad, $this->servido);
            }
            if (!$stock->save()) {
                return false;
            }
        }
        $this->disableUpdateStock = true;
        return $this->pipeFalse('updateStock', $doc);
    }
}
