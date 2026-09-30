<?php

namespace FacturaScripts\Core\Lib\Inventory;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Model\Stock;

/** Compact, current stock rows owned by the database transaction holding their locks. */
final class StockTotals
{
    private static $rows = [];

    public static function clear(): void
    {
        self::$rows = [];
    }

    /** Called immediately after persistence, before model hooks can write another balance. */
    public static function record(Stock $stock, array $values = [], bool $deleted = false): void
    {
        $product = $values['idproducto'] ?? $stock->idproducto;
        if (!isset(self::$rows[$product])) {
            return;
        }

        if ($deleted) {
            unset(self::$rows[$product][$stock->idstock]);
            return;
        }

        self::$rows[$product][$stock->idstock] = [
            $values['referencia'] ?? $stock->referencia,
            (float) ($values['cantidad'] ?? $stock->cantidad),
        ];
    }

    public static function get(DataBase $db, int $product, string $reference): array
    {
        Inventory::requireSuccess($db->inTransaction(), 'Los totales requieren una transacción de inventario.');
        if (!isset(self::$rows[$product])) {
            // The ORM cannot project just these fields with a current locking read.
            // Keep the locks and original summation order under REPEATABLE READ.
            $rows = $db->select('SELECT idstock, cantidad, referencia FROM stocks WHERE idproducto = '
                . $db->var2str($product) . ' ORDER BY idstock FOR UPDATE');
            self::$rows[$product] = [];
            foreach ($rows as $row) {
                self::$rows[$product][$row['idstock']] = [$row['referencia'], (float) $row['cantidad']];
            }
        }

        // Re-sum persisted quantities instead of adding rounded deltas to a derived
        // total. This also preserves legacy precision and explicit stock identifiers.
        ksort(self::$rows[$product], SORT_NUMERIC);
        $total = $variant = 0.0;
        foreach (self::$rows[$product] as [$rowReference, $quantity]) {
            $total += $quantity;
            if ($rowReference === $reference) {
                $variant += $quantity;
            }
        }

        return [round($total, Stock::MAX_DECIMALS), round($variant, Stock::MAX_DECIMALS)];
    }
}
