<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

/** Registro cerrado de documentos propios: nunca acepta nombres de clase o tabla del navegador. */
class ElectronicDocuments extends DashboardQuery
{
    public const TYPES = [
        'FacturaCliente' => ['facturascli', 'idfactura', 'ListFacturaCliente', 'EditFacturaCliente', 'Factura', 'invoice_status_annulled'],
        'NotaCreditoCliente' => ['notascreditocli', 'idnotacredito', 'ListNotaCreditoCliente', 'EditNotaCreditoCliente', 'Nota de crédito', 'nota_credito_status_annulled'],
        'NotaDebitoCliente' => ['notasdebitocli', 'idnotadebito', 'ListNotaDebitoCliente', 'EditNotaDebitoCliente', 'Nota de débito', 'nota_debito_status_annulled'],
        'AlbaranCliente' => ['albaranescli', 'idalbaran', 'ListAlbaranCliente', 'EditAlbaranCliente', 'Guía de remisión', 'guide_status_annulled'],
        'FacturaProveedor' => ['facturasprov', 'idfactura', 'ListFacturaProveedor', 'EditFacturaProveedor', 'Liquidación de compra', ''],
        'SFRetencionCompra' => ['sf_retenciones_compras', 'id', 'ListFacturaProveedor', 'EditFacturaProveedor', 'Retención', ''],
    ];

    public function load(bool $summary = false): array
    {
        $parts = [];
        $types = [];
        foreach (self::TYPES as $type => $definition) {
            $sql = $this->source($type);
            if ($sql !== '') {
                $types[$type] = $definition[4];
                $parts[] = $sql;
            }
        }
        if (!$parts) {
            return ['count' => 0, 'states' => [], 'types' => [], 'list' => ['rows' => [], 'count' => 0, 'page' => 1, 'pages' => 1]];
        }
        $base = ' FROM (' . implode(' UNION ALL ', $parts) . ') docs WHERE 1=1';
        $base .= $this->search(['docs.number', 'docs.subject', 'docs.codserie']);
        if ($this->filters['doc_type'] !== '') {
            $base .= ' AND docs.doc_type = ' . $this->quote($this->filters['doc_type']);
        }
        if ($this->filters['sri_state'] !== '') {
            $base .= ' AND docs.status = ' . $this->quote($this->filters['sri_state']);
        }
        if ($this->filters['environment'] !== '') {
            $base .= ' AND docs.env = ' . $this->quote($this->filters['environment']);
        }
        $states = $this->db->select('SELECT status, COUNT(*) AS count' . $base . ' GROUP BY status ORDER BY count DESC');
        $count = array_sum(array_column($states, 'count'));
        $sql = 'SELECT *' . $base . ' ORDER BY fecha ASC, doc_type ASC, doc_id ASC';
        $list = $summary ? ['rows' => $this->db->selectLimit($sql, 5), 'count' => $count, 'page' => 1, 'pages' => 1] : $this->page($sql);
        foreach ($list['rows'] as &$row) {
            $row = $this->decorate($row);
        }
        unset($row);
        return ['count' => $count, 'states' => $states, 'types' => $types, 'list' => $list];
    }

    public function find(string $type, int $id): ?array
    {
        $sql = $this->source($type, false);
        if ($sql === '') {
            return null;
        }
        $rows = $this->db->select('SELECT * FROM (' . $sql . ') docs WHERE docs.doc_id = ' . $id);
        return $rows ? $this->decorate($rows[0]) : null;
    }

    public static function message(string $raw, string $status): string
    {
        if (stripos($status, 'INTERNO') !== false) {
            return 'Revisa los datos del documento y la configuración de emisión en su pantalla de origen.';
        }
        $decoded = json_decode($raw, true);
        $messages = [];
        $collect = static function ($value) use (&$collect, &$messages): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (in_array($key, ['mensaje', 'informacionAdicional'], true) && is_scalar($item)) {
                    $messages[] = strip_tags((string)$item);
                } elseif (is_array($item)) {
                    $collect($item);
                }
            }
        };
        $collect($decoded);
        if (!$messages && !is_array($decoded) && $raw !== '' && strpos($raw, '<') === false
            && strpos($raw, '#0') === false && strpos($raw, 'Stack trace') === false) {
            $messages[] = strtok($raw, "\r\n");
        }
        return mb_substr(implode(' · ', array_unique($messages)), 0, 350);
    }

    private function source(string $type, bool $pending = true): string
    {
        if (!isset(self::TYPES[$type])) {
            return '';
        }
        [$table, $key, $page, $edit, $label, $setting] = self::TYPES[$type];
        if (!$this->access->can($page) || !$this->db->tableExists($table)) {
            return '';
        }
        $retention = $type === 'SFRetencionCompra';
        $purchase = $retention || $type === 'FacturaProveedor';
        $mainTable = $retention ? 'facturasprov' : $table;
        $doc = $retention ? 'r' : 'f';
        $subjectTable = $purchase ? 'proveedores' : 'clientes';
        $subjectKey = $purchase ? 'codproveedor' : 'codcliente';
        $name = !$purchase && $this->has($table, 'nombrecliente') ? 'f.nombrecliente' : 'subject.nombre';
        $number = $this->has($mainTable, 'numero2') ? "COALESCE(NULLIF(f.numero2,''),f.codigo)" : 'f.codigo';
        if ($retention) {
            $number = "COALESCE(NULLIF(r.codigo,'')," . $number . ')';
        }
        $from = $retention ? 'sf_retenciones_compras r INNER JOIN facturasprov f ON f.idfactura = r.idfactura' : $table . ' f';
        $from .= ' LEFT JOIN ' . $subjectTable . ' subject ON subject.' . $subjectKey . ' = f.' . $subjectKey
            . ' INNER JOIN series se ON se.codserie = ' . $doc . '.codserie'
            . ' LEFT JOIN sf_documents_signed e ON e.id = (SELECT MAX(latest.id) FROM sf_documents_signed latest'
            . ' WHERE latest.doc_type = ' . $this->quote($type) . ' AND latest.doc_id = ' . $doc . '.' . $key . ')';
        $where = $this->scope($mainTable, 'f', $page) . ' AND se.allow_fact = ' . $this->quote(true);
        if ($setting !== '') {
            $where .= ' AND ' . $this->notAnnulled($table, 'f', $setting);
        }
        if ($purchase) {
            $where .= ' AND ' . $this->notAnnulled('facturasprov', 'f', '');
        }
        if ($retention) {
            $owner = $this->owner($table, 'r', $page);
            if ($owner !== '') {
                $where .= ' AND ' . $owner;
            }
        }
        if ($type === 'FacturaProveedor') {
            // Una factura recibida nunca se convierte en una liquidación por compartir serie.
            $marked = $this->has($table, 'tipocomprobantesri') ? "f.tipocomprobantesri = '03' OR " : '';
            $where .= ' AND (' . $marked . 'EXISTS (SELECT 1 FROM sf_documents_signed own_doc'
                . " WHERE own_doc.doc_type = 'FacturaProveedor' AND own_doc.doc_id = f.idfactura"
                . " AND SUBSTRING(own_doc.key_access,9,2) = '03'))";
        }
        if ($this->has('sf_documents_signed', 'canceled')) {
            $where .= ' AND (e.canceled IS NULL OR e.canceled = ' . $this->quote(false) . ')';
        }
        $authorized = 'EXISTS (SELECT 1 FROM sf_documents_signed authorized WHERE authorized.doc_type = '
            . $this->quote($type) . ' AND authorized.doc_id = ' . $doc . '.' . $key
            . " AND (authorized.verified = " . $this->quote(true) . " OR authorized.status = 'AUTORIZADO'))";
        if ($pending) {
            $where .= ' AND NOT ' . $authorized;
        }
        return 'SELECT ' . $doc . '.' . $key . ' AS doc_id, ' . $this->quote($type) . ' AS doc_type,'
            . ' ' . $doc . '.fecha AS fecha, ' . $number . ' AS number, ' . $name . ' AS subject, '
            . $doc . '.codserie, f.codalmacen, f.idempresa, f.nick, '
            . ($this->has($mainTable, 'codagente') ? 'f.codagente' : 'NULL') . ' AS codagente, '
            . ($retention ? 'f.idfactura' : 'NULL') . ' AS parent_id, '
            . 'e.id AS electronic_id, e.env, e.verified, e.message, '
            . "COALESCE(e.status,'SIN GENERAR') AS status, (CASE WHEN " . $authorized . ' THEN 1 ELSE 0 END) AS authorized'
            . ' FROM ' . $from . ' WHERE ' . $where;
    }

    private function decorate(array $row): array
    {
        $definition = self::TYPES[$row['doc_type']];
        $row['label'] = $definition[4];
        $row['can_authorize'] = empty($row['authorized']) && $this->access->can($definition[3], true)
            && $this->access->owns($definition[3], $row);
        $row['message'] = self::message((string)($row['message'] ?? ''), $row['status']);
        $row['url'] = $this->access->can($definition[3]) ? $definition[3] . '?code='
            . (int)($row['parent_id'] ?: $row['doc_id']) : '';
        if ($row['url'] !== '' && $row['doc_type'] === 'SFRetencionCompra') {
            $row['url'] .= '&activetab=retencionciones';
        } elseif ($row['url'] !== '' && $row['doc_type'] === 'FacturaProveedor') {
            $row['url'] .= '&activetab=liquidacion';
        }
        return $row;
    }
}
