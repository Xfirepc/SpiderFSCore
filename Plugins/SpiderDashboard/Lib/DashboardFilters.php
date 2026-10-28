<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use DateTimeImmutable;
use InvalidArgumentException;

final class DashboardFilters
{
    public const SECTIONS = ['summary', 'sales', 'receivables', 'inventory', 'cash', 'ebilling'];

    public static function normalize(array $input, string $today): array
    {
        $result = ['from' => substr($today, 0, 7) . '-01', 'to' => $today, 'section' => 'summary',
            'q' => '', 'page' => 1, 'open_page' => 1, 'stock_state' => 'all', 'doc_type' => '',
            'sri_state' => '', 'environment' => '', 'product_order' => 'amount', 'warehouses' => []];
        foreach ($result as $key => $default) {
            if ($key !== 'warehouses' && isset($input[$key]) && !is_scalar($input[$key])) {
                throw new InvalidArgumentException('El filtro ' . $key . ' no es válido.');
            }
            if ($key !== 'warehouses' && isset($input[$key]) && is_scalar($input[$key])) {
                $result[$key] = trim((string)$input[$key]);
            }
        }
        foreach (['from', 'to'] as $key) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $result[$key]);
            if (!$date || $date->format('Y-m-d') !== $result[$key]) {
                throw new InvalidArgumentException('Introduce un rango de fechas válido.');
            }
        }
        if ($result['from'] > $result['to']) {
            throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
        }
        $result['section'] = in_array($result['section'], self::SECTIONS, true) ? $result['section'] : 'summary';
        $result['page'] = max(1, min(100000, (int)$result['page']));
        $result['open_page'] = max(1, min(100000, (int)$result['open_page']));
        $result['q'] = mb_substr($result['q'], 0, 100);
        $result['stock_state'] = in_array($result['stock_state'], ['all', 'out', 'below', 'low', 'high', 'above', 'invalid'], true)
            ? $result['stock_state'] : 'all';
        $result['product_order'] = $result['product_order'] === 'units' ? 'units' : 'amount';
        $result['environment'] = in_array($result['environment'], ['1', '2'], true) ? $result['environment'] : '';
        $result['sri_state'] = mb_substr($result['sri_state'], 0, 25);
        $result['doc_type'] = mb_substr($result['doc_type'], 0, 25);
        if (in_array($result['section'], ['summary', 'sales'], true)) {
            $result['q'] = '';
        }
        if ($result['section'] === 'summary') {
            $result['stock_state'] = 'all';
            $result['doc_type'] = $result['sri_state'] = $result['environment'] = '';
        }
        if (isset($input['warehouses']) && !is_array($input['warehouses'])) {
            throw new InvalidArgumentException('Selecciona los almacenes desde el filtro.');
        }
        foreach ($input['warehouses'] ?? [] as $code) {
            if (!is_scalar($code) || strlen((string)$code) > 4) {
                throw new InvalidArgumentException('El código de almacén no es válido.');
            }
            if ((string)$code !== '') {
                $result['warehouses'][] = (string)$code;
            }
        }
        $result['warehouses'] = array_values(array_unique($result['warehouses']));
        return $result;
    }

    public static function previous(array $filters): array
    {
        $from = new DateTimeImmutable($filters['from']);
        $to = new DateTimeImmutable($filters['to']);
        $days = (int)$from->diff($to)->days + 1;
        return ['from' => $from->modify('-' . $days . ' days')->format('Y-m-d'),
            'to' => $from->modify('-1 day')->format('Y-m-d')];
    }
}
