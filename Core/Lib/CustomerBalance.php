<?php

namespace FacturaScripts\Core\Lib;

use DateTimeImmutable;

/** Cálculo de lectura compartido por el estado de cuenta y los indicadores de cartera. */
final class CustomerBalance
{
    public static function build(array $invoice, array $receipts, DateTimeImmutable $today): array
    {
        $invoice['paid'] = $invoice['overdue'] = $invoice['current'] = 0.0;
        $invoice['receipts'] = [];
        foreach ($receipts as $receipt) {
            $receipt['payments'] = $receipt['payments'] ?? [];
            $paid = 0.0;
            foreach ($receipt['payments'] as &$payment) {
                $payment['reversed'] = !empty($payment['idasientoreverso']) || ($payment['estado'] ?? '') === 'REVERTIDO';
                if (!$payment['reversed']) {
                    $paid += (float)$payment['importe'];
                }
            }
            unset($payment);
            // Los recibos anteriores al historial de pagos conservan su marca original.
            if (!$receipt['payments'] && self::isTrue($receipt['pagado'] ?? false)) {
                $paid = (float)$receipt['importe'];
            }
            $receipt['paid'] = round($paid, 2);
            $receipt['balance'] = round((float)$receipt['importe'] - $paid, 2);
            $receipt['status'] = self::status((float)$receipt['importe'], $paid);
            $receipt['days'] = 0;
            if (!empty($receipt['vencimiento']) && $receipt['balance'] > 0) {
                $due = new DateTimeImmutable($receipt['vencimiento']);
                $receipt['days'] = max(0, (int)$due->diff($today)->format('%r%a'));
                $invoice[$receipt['days'] > 0 ? 'overdue' : 'current'] += $receipt['balance'];
            }
            $invoice['paid'] += $receipt['paid'];
            $invoice['receipts'][] = $receipt;
        }
        if (!$receipts && self::isTrue($invoice['pagada'] ?? false)) {
            $invoice['paid'] = (float)$invoice['total'];
        }
        $invoice['paid'] = round($invoice['paid'], 2);
        $invoice['total'] = (float)$invoice['total'];
        $invoice['balance'] = round($invoice['total'] - $invoice['paid'], 2);
        $invoice['status'] = self::status($invoice['total'], $invoice['paid']);
        return $invoice;
    }

    /** Distribuye solamente deuda positiva; los ajustes nunca aumentan la cartera. */
    public static function aging(array $invoice): array
    {
        $buckets = ['current' => 0.0, 'days30' => 0.0, 'days60' => 0.0,
            'days90' => 0.0, 'older' => 0.0, 'undated' => 0.0];
        $remaining = max(0.0, round($invoice['balance'], 2));
        $receipts = $invoice['receipts'];
        usort($receipts, static function (array $left, array $right): int {
            return strcmp(!empty($left['vencimiento']) ? $left['vencimiento'] : '9999-12-31',
                !empty($right['vencimiento']) ? $right['vencimiento'] : '9999-12-31');
        });
        foreach ($receipts as $receipt) {
            $amount = min($remaining, max(0.0, $receipt['balance']));
            $days = $receipt['days'];
            $bucket = empty($receipt['vencimiento']) ? 'undated' : 'current';
            if ($days > 0) {
                $bucket = $days <= 30 ? 'days30' : ($days <= 60 ? 'days60' : ($days <= 90 ? 'days90' : 'older'));
            }
            $buckets[$bucket] += $amount;
            $remaining = round($remaining - $amount, 2);
        }
        $buckets['undated'] += $remaining;
        return $buckets;
    }

    private static function status(float $amount, float $paid): string
    {
        if (abs($amount - $paid) < 0.005 || ($amount > 0 && $paid >= $amount) || ($amount < 0 && $paid <= $amount)) {
            return 'Pagada';
        }
        return abs($paid) >= 0.005 ? 'Parcial' : 'Pendiente';
    }

    private static function isTrue($value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
