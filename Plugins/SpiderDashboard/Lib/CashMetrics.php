<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use FacturaScripts\Dinamic\Model\TpvCaja;

class CashMetrics extends DashboardQuery
{
    public function load(bool $summary = false): array
    {
        $base = ' FROM tpvsneo_cajas c INNER JOIN tpvsneo t ON t.idtpv = c.idtpv WHERE '
            . $this->scope('tpvsneo', 't', 'EditTpvCaja');
        $owner = $this->owner('tpvsneo_cajas', 'c', 'EditTpvCaja');
        if ($owner !== '') {
            $base .= ' AND ' . $owner;
        }
        $base .= $this->search(['t.name', 'c.nick']);
        $fields = 'SELECT c.*, t.name AS terminal, t.codalmacen, t.coddivisa';
        $openSql = $fields . $base . ' AND c.fechafin IS NULL ORDER BY c.fechaini ASC, c.idcaja ASC';
        $open = $this->page($openSql, 'open_page');
        foreach ($open['rows'] as &$row) {
            $row = $this->openTotals($row);
        }
        unset($row);
        $lastOwner = $this->owner('tpvsneo_cajas', 'last_box', 'EditTpvCaja');
        $last = $this->db->select($fields . $base . ' AND c.fechafin IS NOT NULL'
            . ' AND c.idcaja = (SELECT last_box.idcaja FROM tpvsneo_cajas last_box WHERE last_box.idtpv = c.idtpv'
            . ' AND last_box.fechafin IS NOT NULL' . ($lastOwner !== '' ? ' AND ' . $lastOwner : '')
            . ' ORDER BY last_box.fechafin DESC, last_box.idcaja DESC LIMIT 1)'
            . ' ORDER BY c.fechafin DESC, c.idcaja DESC');
        foreach ($last as &$row) {
            $row['url'] = 'EditTpvCaja?code=' . (int)$row['idcaja'];
        }
        unset($row);
        $historySql = $fields . $base . ' AND c.fechafin >= ' . $this->quote($this->filters['from'] . ' 00:00:00')
            . ' AND c.fechafin < ' . $this->quote(date('Y-m-d', strtotime($this->filters['to'] . ' +1 day')) . ' 00:00:00')
            . ' ORDER BY c.fechafin DESC, c.idcaja DESC';
        $history = $summary ? ['rows' => [], 'count' => 0, 'page' => 1, 'pages' => 1] : $this->page($historySql);
        foreach ($history['rows'] as &$row) {
            $row['url'] = 'EditTpvCaja?code=' . (int)$row['idcaja'];
        }
        unset($row);
        return ['open' => $open, 'last' => $last, 'history' => $history];
    }

    protected function openTotals(array $row): array
    {
        // Reutiliza la lógica de efectivo/anticipos/movimientos, sin guardar ni cerrar.
        $box = new TpvCaja($row);
        $box->setTotals();
        foreach (['ingresos', 'totalcaja', 'totalmovi', 'totaltickets', 'numtickets'] as $key) {
            $row[$key] = $box->{$key};
        }
        $row['url'] = 'EditTpvCaja?code=' . (int)$row['idcaja'];
        return $row;
    }
}
