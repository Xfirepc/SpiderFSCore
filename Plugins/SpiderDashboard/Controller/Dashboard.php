<?php

namespace FacturaScripts\Plugins\SpiderDashboard\Controller;

use DomainException;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\DocumentElectronic;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardAccess;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardFilters;
use FacturaScripts\Plugins\SpiderDashboard\Lib\DashboardService;
use FacturaScripts\Plugins\SpiderDashboard\Lib\ElectronicDocuments;
use FacturaScripts\Plugins\SpiderFact\Lib\DocumentValidator;
use InvalidArgumentException;
use Throwable;

class Dashboard extends Controller
{
    public $filters = [];
    public $sections = [];
    public $warehouses = [];
    public $initialData = [];
    public $dashboardError = '';
    public $dashboardToday;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'dashboard';
        $data['icon'] = 'fas fa-chart-line';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);
        $response->headers->set('Cache-Control', 'private, no-store');
        $this->dashboardToday = date('Y-m-d');
        $this->title = 'Mi negocio · ' . $this->empresa->nombrecorto;
        $input = $this->request->query->all();
        $action = isset($input['action']) && is_string($input['action']) ? $input['action'] : '';
        try {
            $this->filters = DashboardFilters::normalize($input, $this->dashboardToday);
            $access = new DashboardAccess($user, $permissions->onlyOwnerData);
            $service = new DashboardService($this->dataBase, $access, (int)$this->empresa->idempresa, $this->filters, $this->dashboardToday);
            $this->sections = $service->sections();
            $this->warehouses = $service->warehouses();
            if ($action === 'authorize-document') {
                $this->authorize($service);
                return;
            }
            $this->initialData = $service->load($this->filters['section']);
            if ($action === 'data') {
                $this->json(['ok' => true, 'data' => $this->initialData, 'section' => $this->filters['section'],
                    'updated' => date('H:i:s'), 'token' => $this->multiRequestProtection->newToken()]);
                return;
            }
        } catch (InvalidArgumentException $exception) {
            $this->dashboardError = $exception->getMessage();
            if ($action !== '') {
                $this->json(['ok' => false, 'message' => $this->dashboardError], 400);
                return;
            }
        } catch (DomainException $exception) {
            if ($action !== '') {
                $this->json(['ok' => false, 'message' => $exception->getMessage()], 403);
                return;
            }
            $this->dashboardError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('SpiderDashboard: ' . $exception->getMessage());
            $this->dashboardError = 'No se pudo cargar la información. Actualiza el panel o revisa el registro del sistema.';
            if ($action !== '') {
                $this->json(['ok' => false, 'message' => $this->dashboardError], 500);
                return;
            }
        }
        if (!$this->filters) {
            $this->filters = DashboardFilters::normalize([], $this->dashboardToday);
        }
        AssetManager::addCss('Dinamic/Assets/CSS/SpiderDashboard.css?v=1');
        AssetManager::addJs('node_modules/chart.js/dist/Chart.min.js');
        AssetManager::addJs('Dinamic/Assets/JS/SpiderDashboard.js?v=1');
    }

    private function authorize(DashboardService $service): void
    {
        if (!$this->request->isMethod('POST')) {
            $this->json(['ok' => false, 'message' => 'La autorización requiere un formulario POST.'], 405);
            return;
        }
        if (!$this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => 'El formulario venció o ya fue enviado. Actualiza y vuelve a intentarlo.'], 403);
            return;
        }
        $input = $this->request->request->all();
        $type = isset($input['doc_type']) && is_string($input['doc_type']) ? $input['doc_type'] : '';
        $id = isset($input['doc_id']) && is_scalar($input['doc_id'])
            ? filter_var($input['doc_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (!isset(ElectronicDocuments::TYPES[$type]) || $id === false) {
            $this->json(['ok' => false, 'message' => 'El comprobante seleccionado no es válido.'], 400);
            return;
        }
        $provider = $service->electronic();
        $record = $provider->find($type, (int)$id);
        if (!$record) {
            $this->json(['ok' => false, 'message' => 'El documento no está disponible en este contexto.'], 404);
            return;
        }
        if (!empty($record['authorized'])) {
            $this->json(['ok' => true, 'message' => 'El documento ya está autorizado.', 'document' => $record]);
            return;
        }
        if (!$record['can_authorize']) {
            $this->json(['ok' => false, 'message' => 'No tienes permiso para autorizar este documento.'], 403);
            return;
        }
        $class = '\\FacturaScripts\\Dinamic\\Model\\' . $type;
        $document = new $class();
        if (!$document->loadFromCode((int)$id)) {
            $this->json(['ok' => false, 'message' => 'El documento ya no existe.'], 404);
            return;
        }
        try {
            $ok = DocumentElectronic::processDocument($document, false, static function ($fresh) use ($provider, $type, $id): bool {
                $current = $provider->find($type, (int)$id);
                return $current && (!empty($current['authorized']) || $current['can_authorize'])
                    && DocumentValidator::validDocumentRequirements($fresh);
            });
        } catch (Throwable $exception) {
            error_log('SpiderDashboard SRI ' . $type . '#' . $id . ': ' . $exception->getMessage());
            $ok = false;
        }
        $updated = $provider->find($type, (int)$id);
        $ok = $ok && $updated && !empty($updated['authorized']);
        if ($ok) {
            Tools::log()->notice('Dashboard: documento ' . $type . ' #' . $id . ' autorizado por ' . $this->user->nick);
        }
        $this->json(['ok' => (bool)$ok, 'document' => $updated,
            'message' => $ok ? 'Comprobante autorizado correctamente.'
                : 'El comprobante continúa pendiente o está siendo procesado. Actualiza su estado y revisa el mensaje del documento.'], $ok ? 200 : 422);
    }

    private function json(array $data, int $status = 200): void
    {
        $this->setTemplate(false);
        $data['token'] = $this->multiRequestProtection->newToken();
        $this->response->setStatusCode($status);
        $this->response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $this->response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
