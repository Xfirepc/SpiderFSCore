<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/** Consultas agregadas de lectura; los nombres SQL proceden solo del código del plugin. */
abstract class DashboardQuery
{
    public const PAGE_SIZE = 25;
    protected $db;
    protected $access;
    protected $company;
    protected $filters;
    protected $today;
    private $columns = [];

    public function __construct(DataBase $db, DashboardAccess $access, int $company, array $filters, string $today)
    {
        $this->db = $db;
        $this->access = $access;
        $this->company = $company;
        $this->filters = $filters;
        $this->today = $today;
    }

    protected function quote($value): string
    {
        return $this->db->var2str($value);
    }

    protected function has(string $table, string $column): bool
    {
        if (!isset($this->columns[$table])) {
            $this->columns[$table] = $this->db->tableExists($table) ? $this->db->getColumns($table) : [];
        }
        return array_key_exists($column, $this->columns[$table]);
    }

    protected function scope(string $table, string $alias, string $page, string $warehouse = 'codalmacen'): string
    {
        if (!$this->access->can($page)) {
            return '1 = 0';
        }
        $where = [$alias . '.idempresa = ' . $this->quote($this->company)];
        if ($warehouse !== '' && $this->filters['warehouses']) {
            $where[] = $alias . '.' . $warehouse . ' IN (' . implode(',', array_map([$this, 'quote'], $this->filters['warehouses'])) . ')';
        }
        $owner = $this->owner($table, $alias, $page);
        if ($owner !== '') {
            $where[] = $owner;
        }
        return implode(' AND ', $where);
    }

    protected function owner(string $table, string $alias, string $page): string
    {
        if (!$this->access->onlyOwner($page)) {
            return '';
        }
        $identity = $this->access->identity();
        $terms = [];
        $hasNick = $this->has($table, 'nick');
        if ($hasNick) {
            $terms[] = $alias . '.nick = ' . $this->quote($identity['nick']);
            $terms[] = $alias . '.nick IS NULL';
        }
        if ($this->has($table, 'codagente') && (!empty($identity['codagente']) || !$hasNick)) {
            $terms[] = $alias . '.codagente' . ($identity['codagente'] === null
                ? ' IS NULL' : ' = ' . $this->quote($identity['codagente']));
        }
        return $terms ? '(' . implode(' OR ', $terms) . ')' : '';
    }

    protected function notAnnulled(string $table, string $alias, string $setting): string
    {
        $status = $setting !== '' ? Tools::settings('facturacione', $setting, null) : null;
        if (!$this->has($table, 'idestado')) {
            return '1 = 1';
        }
        $terms = [];
        if ($status !== null && $status !== '') {
            $terms[] = '(' . $alias . '.idestado IS NULL OR ' . $alias . '.idestado <> ' . $this->quote($status) . ')';
        }
        // Las instalaciones antiguas pueden no tener todavía el ajuste de anulación.
        $terms[] = 'NOT EXISTS (SELECT 1 FROM estados_documentos ed WHERE ed.idestado = ' . $alias
            . ".idestado AND LOWER(ed.nombre) IN ('anulada','anulado','cancelada','cancelado'))";
        return implode(' AND ', $terms);
    }

    protected function search(array $fields): string
    {
        if ($this->filters['q'] === '') {
            return '';
        }
        $terms = [];
        foreach ($fields as $field) {
            $terms[] = $field . ' LIKE ' . $this->quote('%' . $this->filters['q'] . '%');
        }
        return ' AND (' . implode(' OR ', $terms) . ')';
    }

    protected function page(string $sql, string $pageKey = 'page'): array
    {
        $count = $this->db->select('SELECT COUNT(*) AS total FROM (' . $sql . ') dashboard_count');
        $total = (int)($count[0]['total'] ?? 0);
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = min($pages, $this->filters[$pageKey]);
        return ['rows' => $this->db->selectLimit($sql, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'count' => $total, 'page' => $page, 'pages' => $pages];
    }
}
