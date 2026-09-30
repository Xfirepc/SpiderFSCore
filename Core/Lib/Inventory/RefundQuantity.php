<?php

namespace FacturaScripts\Core\Lib\Inventory;

use FacturaScripts\Dinamic\Model\LineaFacturaCliente;
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor;

/** Serialize credits on the original line, regardless of the controller used. */
final class RefundQuantity
{
    public static function validate($db, $line): bool
    {
        if (!self::validateSource($db, $line)) {
            return false;
        }
        if (empty($line->idlinearect) || (float) $line->cantidad == 0) {
            return true;
        }

        $supplier = strpos($line->modelClassName(), 'Proveedor') !== false;
        $document = $line->getDocument();
        if (
            $line::tableName() === 'lineasnotadebitocli'
            || ($supplier && ($document->tipocomprobantesri ?? '') === '05')
        ) {
            $line->stockneutral = true;
            $line->stockcancelmode = 0;
            $line->actualizastock = 0;
            return true;
        }

        $positiveCredit = $line::tableName() === 'lineasnotacreditocli';
        $invoiceTable = $supplier ? 'lineasfacturasprov' : 'lineasfacturascli';
        $original = self::lockedInvoiceLine($db, $invoiceTable, $line->idlinearect);
        if (
            !$original || (float) $original['cantidad'] <= 0
            || ($positiveCredit ? $line->cantidad < 0 : $line->cantidad > 0)
        ) {
            return false;
        }
        Inventory::requireSuccess(
            $line->referencia === $original['referencia'],
            'La devolución debe conservar la referencia de la línea original.'
        );
        if (empty($line->idlinea)) {
            foreach (['stockcomposition', 'stockpack', 'stockneutral'] as $field) {
                if (array_key_exists($field, $original)) {
                    $line->{$field} = $original[$field];
                }
            }
            $sourceClass = $supplier ? LineaFacturaProveedor::class : LineaFacturaCliente::class;
            $line->stockcompositionsource = new $sourceClass($original);
        }

        $stockMode = $line->stockcancelmode ?? $line->actualizastock;
        Inventory::requireSuccess(
            !empty($line->stockneutral) || abs((int) $stockMode) !== 1
            || abs((int) ($original['actualizastock'] ?? 0)) === 1,
            'El documento original no tiene una entrega física activa. No se puede devolver ese stock otra vez.'
        );
        if (!empty($document->idfacturarect) && $document->idfacturarect != $original['idfactura']) {
            return false;
        }

        $usedQuantity = 0.0;
        foreach (self::lockedCredits($db, $invoiceTable, $line->idlinearect) as $credit) {
            if ($credit['source_table'] === $line::tableName() && $credit['idlinea'] == $line->idlinea) {
                continue;
            }
            $usedQuantity += abs((float) $credit['cantidad']);
        }
        Inventory::requireSuccess(
            $usedQuantity + abs((float) $line->cantidad) <= (float) $original['cantidad'] + 0.0005,
            'La devolución supera la cantidad pendiente de devolver de la línea original.'
        );
        return true;
    }

    public static function validateSource($db, $line, bool $deleting = false): bool
    {
        $invoiceTable = $line::tableName();
        if (empty($line->idlinea) || !in_array($invoiceTable, ['lineasfacturascli', 'lineasfacturasprov'], true)) {
            return true;
        }
        $original = self::lockedInvoiceLine($db, $invoiceTable, $line->idlinea);
        if (!$original) {
            return false;
        }

        $creditedQuantity = 0.0;
        $physicallyReturned = false;
        foreach (self::lockedCredits($db, $invoiceTable, $line->idlinea) as $credit) {
            $creditedQuantity += abs((float) $credit['cantidad']);
            $physicallyReturned = $physicallyReturned || (empty($credit['stockneutral'])
                && abs((int) ($credit['actualizastock'] ?? 0)) === 1);
        }
        if ($creditedQuantity <= 0.0005) {
            return true;
        }

        Inventory::requireSuccess(
            !$deleting && $line->referencia === $original['referencia']
            && $line->cantidad + 0.0005 >= $creditedQuantity,
            'La línea original tiene devoluciones vinculadas. Revise esas devoluciones antes de reducirla, sustituirla o eliminarla.'
        );
        Inventory::requireSuccess(
            !$physicallyReturned || (empty($line->stockneutral)
                && (int) $line->actualizastock === (int) ($original['actualizastock'] ?? 0)),
            'Anule primero la devolución física vinculada antes de anular la entrega original.'
        );
        return true;
    }

    private static function lockedInvoiceLine($db, string $table, int $lineId): ?array
    {
        // Current-row locks serialize every credit route on the same original line.
        $rows = $db->select('SELECT * FROM ' . $table . ' WHERE idlinea = '
            . $db->var2str($lineId) . ' FOR UPDATE');
        return $rows[0] ?? null;
    }

    private static function lockedCredits($db, string $invoiceTable, int $lineId): iterable
    {
        $tables = [$invoiceTable];
        if ($invoiceTable === 'lineasfacturascli') {
            $tables[] = 'lineasnotacreditocli';
        }
        foreach ($tables as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }
            // Read optional plugin history even when its model is not loaded. Both
            // positive SpiderFact notes and negative Core credits need row locks.
            $quantityFilter = $table === 'lineasnotacreditocli' ? ' > 0' : ' < 0';
            $rows = $db->select('SELECT * FROM ' . $table . ' WHERE idlinearect = '
                . $db->var2str($lineId) . ' AND cantidad' . $quantityFilter . ' FOR UPDATE');
            foreach ($rows as $credit) {
                $credit['source_table'] = $table;
                yield $credit;
            }
        }
    }
}
