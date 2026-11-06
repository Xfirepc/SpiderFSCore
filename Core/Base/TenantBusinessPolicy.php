<?php

namespace FacturaScripts\Core\Base;

use FacturaScripts\Core\KernelException;

/** Limita funciones de sectores insertadas en páginas compartidas del ERP. */
final class TenantBusinessPolicy
{
    public static function filter($controller, $user, $request): void
    {
        if (!TenantMenuPolicy::isManaged() || !empty($user->sysadmin)) {
            return;
        }
        $deniedViews = [];
        $deniedFields = [];
        if (!TenantMenuPolicy::allows('ListServicioAT', $user)) {
            $deniedViews = ['ListMaquinaAT', 'ListServicioAT'];
        }
        if (!TenantMenuPolicy::allows('SpiderRestaurante', $user)) {
            $deniedViews = array_merge($deniedViews, ['EditSRComposition', 'ConfiguracionCocina', 'EditFamiliaFoto']);
            if (in_array($controller->getPageData()['name'], ['EditProducto', 'EditFamilia'], true)) {
                $deniedFields = ['recipe', 'idcocina', 'prioridadcocina', 'imprimircomanda', 'muestra_en_pos'];
            }
        }
        $active = $request->request->get('activetab', $request->query->get('activetab', ''));
        if (in_array($active, $deniedViews, true)) {
            throw new KernelException('AccessDenied', 'Módulo no incluido en la licencia');
        }
        foreach ($deniedViews as $view) {
            unset($controller->views[$view]);
        }
        foreach ($controller->views as $view) {
            foreach ($deniedFields as $field) {
                if ($request->request->has($field) || $request->query->has($field)) {
                    throw new KernelException('AccessDenied', 'Campo de un módulo no incluido');
                }
                $column = $view->columnForField($field);
                if ($column !== null) {
                    foreach ($view->getColumns() as $group) {
                        unset($group->columns[$column->name]);
                    }
                }
            }
        }
    }
}
