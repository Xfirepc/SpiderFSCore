<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use DateTimeImmutable;
use FacturaScripts\Core\Lib\CustomerBalance;

class ReceivableMetrics extends DashboardQuery
{
    public function load(bool $summary = false): array
    {
        $result = ['balance' => 0.0, 'overdue' => 0.0, 'count' => 0, 'debtors' => [],
            'aging' => ['current' => 0.0, 'days30' => 0.0, 'days60' => 0.0, 'days90' => 0.0, 'older' => 0.0, 'undated' => 0.0],
            'list' => ['rows' => [], 'count' => 0, 'page' => $this->filters['page'], 'pages' => 1]];
        $sql = $this->candidates();
        $customers = [];
        $start = ($this->filters['page'] - 1) * self::PAGE_SIZE;
        $lastRows = [];
        $today = new DateTimeImmutable($this->today);
        // Solo se hidratan en lotes las facturas cuyo saldo agregado puede ser positivo.
        // Los recibos y pagos se precargan por lote, nunca por factura.
        for ($offset = 0; ; $offset += 200) {
            $invoices = $this->db->selectLimit($sql, 200, $offset);
            if (!$invoices) {
                break;
            }
            $groups = $this->receipts(array_column($invoices, 'idfactura'));
            foreach ($invoices as $invoice) {
                $invoice = CustomerBalance::build($invoice, $groups[$invoice['idfactura']] ?? [], $today);
                if ($invoice['balance'] < 0.005) {
                    continue;
                }
                $aging = CustomerBalance::aging($invoice);
                $invoice['overdue'] = array_sum($aging) - $aging['current'] - $aging['undated'];
                $invoice['due'] = '';
                $invoice['days'] = 0;
                foreach ($invoice['receipts'] as $receipt) {
                    if ($receipt['balance'] <= 0 || empty($receipt['vencimiento'])) {
                        continue;
                    }
                    if ($invoice['due'] === '' || $receipt['vencimiento'] < $invoice['due']) {
                        $invoice['due'] = $receipt['vencimiento'];
                    }
                    $invoice['days'] = max($invoice['days'], $receipt['days']);
                }
                unset($invoice['receipts']);
                $invoice['url'] = $this->access->can('EditFacturaCliente')
                    ? 'EditFacturaCliente?code=' . (int)$invoice['idfactura'] : '';
                foreach ($aging as $key => $amount) {
                    $result['aging'][$key] += $amount;
                }
                $result['balance'] += $invoice['balance'];
                $result['overdue'] += $invoice['overdue'];
                $customer = $invoice['codcliente'];
                if (!isset($customers[$customer])) {
                    $customers[$customer] = ['name' => $invoice['nombrecliente'], 'codcliente' => $customer,
                        'amount' => 0.0, 'overdue' => 0.0, 'invoices' => 0];
                }
                $customers[$customer]['amount'] += $invoice['balance'];
                $customers[$customer]['overdue'] += $invoice['overdue'];
                $customers[$customer]['invoices']++;
                $index = $result['count']++;
                if (!$summary && $index >= $start && $index < $start + self::PAGE_SIZE) {
                    $result['list']['rows'][] = $invoice;
                }
                $lastRows[] = $invoice;
                if (count($lastRows) > self::PAGE_SIZE) {
                    array_shift($lastRows);
                }
            }
            if (count($invoices) < 200) {
                break;
            }
        }
        usort($customers, static function (array $left, array $right): int {
            return ($right['amount'] <=> $left['amount']) ?: strcmp($left['codcliente'], $right['codcliente']);
        });
        $result['debtors'] = array_slice($customers, 0, 10);
        $result['balance'] = round($result['balance'], 2);
        $result['overdue'] = round($result['overdue'], 2);
        $result['list']['count'] = $result['count'];
        $result['list']['pages'] = max(1, (int)ceil($result['count'] / self::PAGE_SIZE));
        if ($this->filters['page'] > $result['list']['pages']) {
            $result['list']['page'] = $result['list']['pages'];
            $size = $result['count'] % self::PAGE_SIZE ?: self::PAGE_SIZE;
            $result['list']['rows'] = $summary ? [] : array_slice($lastRows, -$size);
        }
        return $result;
    }

    private function candidates(): string
    {
        $payments = '';
        $paid = 'CASE WHEN r.pagado = ' . $this->quote(true) . ' THEN r.importe ELSE 0 END';
        if ($this->db->tableExists('pagoscli')) {
            $valid = [];
            if ($this->has('pagoscli', 'idasientoreverso')) {
                $valid[] = 'COALESCE(p.idasientoreverso,0) = 0';
            }
            if ($this->has('pagoscli', 'estado')) {
                $valid[] = "COALESCE(p.estado,'') <> 'REVERTIDO'";
            }
            $condition = $valid ? implode(' AND ', $valid) : '1=1';
            $payments = ' LEFT JOIN (SELECT p.idrecibo, COUNT(*) AS records, '
                . 'ROUND(SUM(CASE WHEN ' . $condition . ' THEN p.importe ELSE 0 END),2) AS paid'
                . ' FROM pagoscli p GROUP BY p.idrecibo) pay ON pay.idrecibo = r.idrecibo';
            $paid = 'CASE WHEN pay.records > 0 THEN pay.paid ELSE ' . $paid . ' END';
        }
        $receiptTotals = 'SELECT r.idfactura, COUNT(*) AS records, SUM(' . $paid . ') AS paid'
            . ' FROM recibospagoscli r' . $payments . ' GROUP BY r.idfactura';
        // Esta subconsulta reproduce el cálculo compartido sin cargar facturas saldadas.
        $amount = 'CASE WHEN totals.records IS NULL AND f.pagada = ' . $this->quote(true)
            . ' THEN f.total ELSE COALESCE(totals.paid,0) END';
        return 'SELECT f.* FROM facturascli f LEFT JOIN (' . $receiptTotals . ') totals ON totals.idfactura = f.idfactura'
            . ' WHERE ' . $this->scope('facturascli', 'f', 'ListFacturaCliente')
            . ' AND ' . $this->notAnnulled('facturascli', 'f', 'invoice_status_annulled')
            . " AND f.coddivisa = 'USD' AND ROUND(f.total - (" . $amount . '),2) > 0'
            . $this->search(['f.codigo', 'f.numero2', 'f.nombrecliente', 'f.codcliente'])
            . ' ORDER BY f.fecha ASC, f.idfactura ASC';
    }

    private function receipts(array $ids): array
    {
        $list = implode(',', array_map('intval', $ids));
        $receipts = $this->db->select('SELECT r.* FROM recibospagoscli r WHERE r.idfactura IN (' . $list . ') ORDER BY r.numero, r.idrecibo');
        $payments = $this->db->tableExists('pagoscli')
            ? $this->db->select('SELECT p.* FROM pagoscli p INNER JOIN recibospagoscli r ON r.idrecibo = p.idrecibo'
                . ' WHERE r.idfactura IN (' . $list . ') ORDER BY p.idpago') : [];
        $paymentGroups = $result = [];
        foreach ($payments as $payment) {
            $paymentGroups[$payment['idrecibo']][] = $payment;
        }
        foreach ($receipts as $receipt) {
            $receipt['payments'] = $paymentGroups[$receipt['idrecibo']] ?? [];
            $result[$receipt['idfactura']][] = $receipt;
        }
        return $result;
    }
}
