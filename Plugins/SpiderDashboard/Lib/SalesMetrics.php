<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use DateTimeImmutable;
use FacturaScripts\Core\Plugins;

class SalesMetrics extends DashboardQuery
{
    private const DOCUMENTS = [
        ['facturascli', 'idfactura', 'FacturaCliente', 'ListFacturaCliente', 'invoice_status_annulled', 1, 'lineasfacturascli'],
        ['notascreditocli', 'idnotacredito', 'NotaCreditoCliente', 'ListNotaCreditoCliente', 'nota_credito_status_annulled', -1, 'lineasnotacreditocli'],
        ['notasdebitocli', 'idnotadebito', 'NotaDebitoCliente', 'ListNotaDebitoCliente', 'nota_debito_status_annulled', 1, 'lineasnotadebitocli'],
    ];

    public function load(bool $summary = false): array
    {
        $previous = DashboardFilters::previous($this->filters);
        $range = $this->totals($this->filters['from'], $this->filters['to']);
        $before = $this->totals($previous['from'], $previous['to']);
        $clients = $this->customers();
        return ['today' => $this->totals($this->today, $this->today),
            'month' => $this->totals(substr($this->today, 0, 7) . '-01', $this->today),
            'range' => $range, 'previous' => $before, 'previous_dates' => $previous,
            'change' => abs($before['adjusted']) >= 0.005
                ? round(($range['adjusted'] - $before['adjusted']) / abs($before['adjusted']) * 100, 1) : null,
            'series' => $this->series(), 'customers' => $clients['rows'],
            'consumer' => $clients['consumer'], 'products' => $this->products()];
    }

    private function sources(): array
    {
        $result = [];
        foreach (self::DOCUMENTS as $source) {
            if ($source[2] !== 'FacturaCliente' && !Plugins::isEnabled('SpiderFact')) {
                continue;
            }
            if ($this->access->can($source[3]) && $this->db->tableExists($source[0])) {
                $result[] = $source;
            }
        }
        return $result;
    }

    private function documents(string $from, string $to): string
    {
        $parts = [];
        foreach ($this->sources() as $source) {
            [$table, $key, $type, $page, $setting, $sign] = $source;
            $parts[] = 'SELECT f.fecha, f.total, f.neto, f.codcliente, f.cifnif, f.nombrecliente, '
                . $this->quote($type) . ' AS type, ' . $sign . ' AS sign FROM ' . $table . ' f WHERE '
                . $this->scope($table, 'f', $page) . ' AND ' . $this->notAnnulled($table, 'f', $setting)
                . " AND f.coddivisa = 'USD' AND f.fecha >= " . $this->quote($from) . ' AND f.fecha <= ' . $this->quote($to);
        }
        return $parts ? implode(' UNION ALL ', $parts)
            : "SELECT NULL AS fecha, 0 AS total, 0 AS neto, '' AS codcliente, '' AS cifnif, '' AS nombrecliente, '' AS type, 0 AS sign WHERE 1=0";
    }

    private function totals(string $from, string $to): array
    {
        // La agregación evita cargar documentos y líneas completos para cada indicador.
        $rows = $this->db->select('SELECT '
            . "SUM(CASE WHEN type='FacturaCliente' THEN total ELSE 0 END) AS billed, "
            . "SUM(CASE WHEN type='FacturaCliente' THEN neto ELSE 0 END) AS net, "
            . "SUM(CASE WHEN type='FacturaCliente' THEN 1 ELSE 0 END) AS invoices, "
            . "SUM(CASE WHEN type='NotaCreditoCliente' THEN total ELSE 0 END) AS credits, "
            . "SUM(CASE WHEN type='NotaDebitoCliente' THEN total ELSE 0 END) AS debits, "
            . 'SUM(total * sign) AS adjusted FROM (' . $this->documents($from, $to) . ') sales');
        $result = [];
        foreach (['billed', 'net', 'invoices', 'credits', 'debits', 'adjusted'] as $key) {
            $result[$key] = round((float)($rows[0][$key] ?? 0), 2);
        }
        $result['ticket'] = $result['invoices'] > 0 ? round($result['billed'] / $result['invoices'], 2) : 0;
        return $result;
    }

    private function series(): array
    {
        $from = new DateTimeImmutable($this->filters['from']);
        $to = new DateTimeImmutable($this->filters['to']);
        $daily = $from->diff($to)->days <= 90;
        $expression = $daily ? 'fecha' : 'SUBSTRING(fecha,1,7)';
        $rows = $this->db->select('SELECT ' . $expression . ' AS period, SUM(total * sign) AS amount FROM ('
            . $this->documents($this->filters['from'], $this->filters['to']) . ') sales GROUP BY ' . $expression . ' ORDER BY period');
        $amounts = array_column($rows, 'amount', 'period');
        $labels = $values = [];
        $cursor = $daily ? $from : $from->modify('first day of this month');
        while ($cursor <= $to) {
            $key = $cursor->format($daily ? 'Y-m-d' : 'Y-m');
            $labels[] = $key;
            $values[] = round((float)($amounts[$key] ?? 0), 2);
            $cursor = $cursor->modify($daily ? '+1 day' : '+1 month');
        }
        return ['labels' => $labels, 'values' => $values, 'daily' => $daily];
    }

    private function customers(): array
    {
        $base = $this->documents($this->filters['from'], $this->filters['to']);
        $sql = 'SELECT codcliente, MAX(nombrecliente) AS name, SUM(total * sign) AS amount FROM (' . $base . ') sales';
        $rows = $this->db->selectLimit($sql . " WHERE COALESCE(cifnif,'') <> '9999999999999'"
            . ' GROUP BY codcliente HAVING SUM(total * sign) > 0 ORDER BY amount DESC, codcliente ASC', 10);
        $consumer = $this->db->select('SELECT SUM(total * sign) AS amount FROM (' . $base
            . ") sales WHERE cifnif = '9999999999999'");
        foreach ($rows as &$row) {
            $row['url'] = $this->access->can('EditCliente') ? 'EditCliente?code=' . rawurlencode($row['codcliente']) : '';
        }
        unset($row);
        return ['rows' => $rows, 'consumer' => (float)($consumer[0]['amount'] ?? 0)];
    }

    private function products(): array
    {
        $parts = [];
        foreach ($this->sources() as $source) {
            [$table, $key, $type, $page, $setting, $sign, $lines] = $source;
            if (!$this->db->tableExists($lines)) {
                continue;
            }
            // Las notas de débito ajustan el importe, sin representar nuevas unidades vendidas.
            $units = $type === 'NotaDebitoCliente' ? '0' : 'l.cantidad * ' . $sign;
            $parts[] = 'SELECT l.idproducto, l.referencia, l.descripcion, '
                . $units . ' AS units, l.pvptotal * (100 - f.dtopor1) / 100'
                . ' * (100 - f.dtopor2) / 100 * ' . $sign . ' AS amount FROM ' . $lines . ' l'
                . ' INNER JOIN ' . $table . ' f ON f.' . $key . ' = l.' . $key
                . ' WHERE ' . $this->scope($table, 'f', $page) . ' AND ' . $this->notAnnulled($table, 'f', $setting)
                . " AND f.coddivisa = 'USD' AND f.fecha >= " . $this->quote($this->filters['from'])
                . ' AND f.fecha <= ' . $this->quote($this->filters['to']) . ' AND l.idproducto IS NOT NULL'
                . ($this->has($lines, 'stockparent') ? ' AND (l.stockparent IS NULL OR l.stockparent = 0)' : '');
        }
        if (!$parts) {
            return [];
        }
        $order = $this->filters['product_order'] === 'units' ? 'units' : 'amount';
        $rows = $this->db->selectLimit('SELECT idproducto, referencia, MAX(descripcion) AS name, '
            . 'SUM(units) AS units, SUM(amount) AS amount FROM (' . implode(' UNION ALL ', $parts)
            . ') products GROUP BY idproducto, referencia HAVING SUM(' . $order . ') > 0 ORDER BY ' . $order . ' DESC, referencia ASC', 10);
        foreach ($rows as &$row) {
            $row['url'] = $this->access->can('EditProducto') ? 'EditProducto?code=' . (int)$row['idproducto'] : '';
        }
        unset($row);
        return $rows;
    }
}
