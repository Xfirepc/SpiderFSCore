<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

class InventoryMetrics extends DashboardQuery
{
    public const LABELS = ['out' => 'Sin existencias', 'below' => 'Bajo mínimo', 'low' => 'Cerca del mínimo',
        'high' => 'Cerca del máximo', 'above' => 'Máximo alcanzado', 'invalid' => 'Revisar límites'];

    public function load(bool $summary = false): array
    {
        $conditions = $this->conditions();
        $flags = [];
        foreach ($conditions as $key => $condition) {
            $flags[] = 'SUM(CASE WHEN ' . $condition . ' THEN 1 ELSE 0 END) AS ' . $key . '_count';
        }
        $flags[] = 'SUM(CASE WHEN (' . implode(') OR (', $conditions) . ') THEN 1 ELSE 0 END) AS alerts_count';
        $base = ' FROM stocks s INNER JOIN productos p ON p.idproducto = s.idproducto'
            . ' INNER JOIN almacenes w ON w.codalmacen = s.codalmacen WHERE '
            . $this->scope('almacenes', 'w', 'ListProducto') . ' AND COALESCE(p.nostock,0) = 0'
            . $this->search(['s.referencia', 'p.descripcion', 'w.nombre']);
        $owner = $this->owner('productos', 'p', 'ListProducto');
        if ($owner !== '') {
            $base .= ' AND ' . $owner;
        }
        $counts = $this->db->select('SELECT COUNT(*) AS records, ' . implode(', ', $flags) . $base);
        $state = $this->filters['stock_state'];
        $condition = $conditions[$state] ?? '(' . implode(' OR ', $conditions) . ')';
        $sql = 'SELECT s.*, p.descripcion AS name, w.nombre AS warehouse' . $base . ' AND ' . $condition
            . ' ORDER BY CASE WHEN s.cantidad <= 0 THEN 0 WHEN s.stockmin > 0 AND s.disponible <= s.stockmin THEN 1 ELSE 2 END,'
            . ' s.disponible ASC, s.idstock ASC';
        $page = $summary ? ['rows' => $this->db->selectLimit($sql, 5), 'count' => 0, 'page' => 1, 'pages' => 1] : $this->page($sql);
        foreach ($page['rows'] as &$row) {
            $row['alerts'] = self::classify($row);
            $row['percent'] = (float)$row['stockmax'] > 0
                ? min(100, max(0, (float)$row['cantidad'] / (float)$row['stockmax'] * 100)) : null;
            $row['url'] = $this->access->can('EditProducto') ? 'EditProducto?code=' . (int)$row['idproducto'] : '';
        }
        unset($row);
        return ['counts' => $counts[0] ?? [], 'list' => $page, 'labels' => self::LABELS];
    }

    public static function classify(array $stock): array
    {
        $quantity = (float)$stock['cantidad'];
        $available = (float)$stock['disponible'];
        $min = (float)$stock['stockmin'];
        $max = (float)$stock['stockmax'];
        $flags = ['out' => $quantity <= 0, 'below' => $min > 0 && $available <= $min,
            'low' => $min > 0 && $available > $min && $available <= $min * 1.2,
            'high' => $max > 0 && $quantity >= $max * 0.8 && $quantity < $max,
            'above' => $max > 0 && $quantity >= $max, 'invalid' => $min > 0 && $max > 0 && $min >= $max];
        return array_keys(array_filter($flags));
    }

    private function conditions(): array
    {
        return ['out' => 's.cantidad <= 0', 'below' => 's.stockmin > 0 AND s.disponible <= s.stockmin',
            'low' => 's.stockmin > 0 AND s.disponible > s.stockmin AND s.disponible <= s.stockmin * 1.2',
            'high' => 's.stockmax > 0 AND s.cantidad >= s.stockmax * 0.8 AND s.cantidad < s.stockmax',
            'above' => 's.stockmax > 0 AND s.cantidad >= s.stockmax',
            'invalid' => 's.stockmin > 0 AND s.stockmax > 0 AND s.stockmin >= s.stockmax'];
    }
}
