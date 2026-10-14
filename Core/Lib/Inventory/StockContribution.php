<?php

namespace FacturaScripts\Core\Lib\Inventory;

/** The physical, reserved and pending contribution of one commercial line. */
final class StockContribution
{
    public const FIELDS = ['cantidad', 'reservada', 'pterecibir'];

    public static function fromLine(array $line): array
    {
        $contribution = array_fill_keys(self::FIELDS, 0.0);
        if (!empty($line['stockneutral'])) {
            return $contribution;
        }

        $quantity = (float) ($line['cantidad'] ?? 0);
        $served = (float) ($line['servido'] ?? 0);
        if ($quantity < 0 && $served < $quantity) {
            $served = $quantity;
        }

        $pending = $quantity - $served;
        $mode = (int) ($line['actualizastock'] ?? 0);
        switch ($mode) {
            case 1:
            case -1:
                $contribution['cantidad'] = $mode * $pending;
                break;

            case -2:
                $contribution['reservada'] = $pending;
                break;

            case 2:
                $contribution['pterecibir'] = $pending;
                break;
        }

        return $contribution;
    }
}
