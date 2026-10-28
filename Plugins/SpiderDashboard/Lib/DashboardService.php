<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Lib;

use DomainException;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Plugins;
use InvalidArgumentException;

class DashboardService
{
    private $db;
    private $access;
    private $company;
    private $filters;
    private $today;

    public function __construct(DataBase $db, DashboardAccess $access, int $company, array $filters, string $today)
    {
        $this->db = $db;
        $this->access = $access;
        $this->company = $company;
        $this->filters = $filters;
        $this->today = $today;
    }

    public function warehouses(): array
    {
        $rows = $this->db->select('SELECT codalmacen, nombre, activo FROM almacenes WHERE idempresa = '
            . $this->db->var2str($this->company) . ' ORDER BY nombre, codalmacen');
        $codes = array_map('strval', array_column($rows, 'codalmacen'));
        if (array_diff($this->filters['warehouses'], $codes)) {
            throw new InvalidArgumentException('Uno de los almacenes seleccionados no pertenece a la empresa actual.');
        }
        return $rows;
    }

    public function sections(): array
    {
        $result = ['summary' => 'Resumen'];
        if ($this->access->can('ListFacturaCliente') || $this->access->can('ListNotaCreditoCliente') || $this->access->can('ListNotaDebitoCliente')) {
            $result['sales'] = 'Ventas';
        }
        if ($this->access->can('ListFacturaCliente') && $this->db->tableExists('recibospagoscli')) {
            $result['receivables'] = 'Cartera';
        }
        if ($this->access->can('ListProducto') && $this->db->tableExists('stocks')) {
            $result['inventory'] = 'Inventario';
        }
        if (Plugins::isEnabled('SpiderTPV') && $this->access->can('EditTpvCaja')
            && $this->db->tableExists('tpvsneo') && $this->db->tableExists('tpvsneo_cajas')) {
            $result['cash'] = 'Caja';
        }
        if (Plugins::isEnabled('SpiderFact') && $this->db->tableExists('sf_documents_signed')) {
            foreach (ElectronicDocuments::TYPES as $definition) {
                if ($this->access->can($definition[2]) && $this->db->tableExists($definition[0])) {
                    $result['ebilling'] = 'Facturación electrónica';
                    break;
                }
            }
        }
        return $result;
    }

    public function load(string $section): array
    {
        $this->warehouses();
        $sections = $this->sections();
        if (!isset($sections[$section])) {
            throw new DomainException('Tu usuario no tiene acceso a esta sección del dashboard.');
        }
        $classes = ['sales' => SalesMetrics::class, 'receivables' => ReceivableMetrics::class,
            'inventory' => InventoryMetrics::class, 'cash' => CashMetrics::class, 'ebilling' => ElectronicDocuments::class];
        $result = [];
        foreach ($classes as $key => $class) {
            if (!isset($sections[$key]) || ($section !== 'summary' && $section !== $key)) {
                continue;
            }
            $provider = new $class($this->db, $this->access, $this->company, $this->filters, $this->today);
            $result[$key] = $provider->load($section === 'summary');
        }
        return $result;
    }

    public function electronic(): ElectronicDocuments
    {
        $this->warehouses();
        if (!isset($this->sections()['ebilling'])) {
            throw new DomainException('No tienes acceso a los comprobantes electrónicos.');
        }
        return new ElectronicDocuments($this->db, $this->access, $this->company, $this->filters, $this->today);
    }
}
