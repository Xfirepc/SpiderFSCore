<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core\Controller;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\CrashReport;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

class Deploy implements ControllerInterface
{
    public function __construct(string $className, string $url = '')
    {
    }

    public function getPageData(): array
    {
        return [];
    }

    public function run(): void
    {
        switch ($_GET['action'] ?? '') {
            case 'disable-plugins':
                $this->disablePluginsAction();
                break;

            case 'rebuild':
                $this->rebuildAction();
                break;

            case 'rebuild-mass':
                $this->rebuildMassAction();
                return;

            default:
                $this->deployAction();
                break;
        }

        echo '<a href="' . Tools::config('route') . '/">Reload</a>';
    }

    protected function deployAction(): void
    {
        // si ya existe la carpeta Dinamic, no hacemos deploy
        if (is_dir(Tools::folder('Dinamic'))) {
            echo '<p>Deploy not needed. Dinamic folder already exists. Delete it if you want to deploy again.</p>';
            return;
        }

        Plugins::deploy();

        echo '<p>Deploy finished.</p>';
    }

    protected function disablePluginsAction(): void
    {
        // comprobamos que no se ha desactivado
        if (Tools::config('disable_deploy_actions', false)) {
            echo '<p>Deploy actions already disabled.</p>';
            return;
        }

        // comprobamos el token
        if (false === CrashReport::validateToken($_GET['token'] ?? '')) {
            echo '<p>Invalid token.</p>';
            return;
        }

        // desactivamos todos los plugins
        foreach (Plugins::enabled() as $name) {
            Plugins::disable($name);
        }

        echo '<p>Plugins disabled.</p>';
    }

    protected function rebuildAction(): void
    {
        // comprobamos que no se ha desactivado
        if (Tools::config('disable_deploy_actions', false)) {
            echo '<p>Deploy actions already disabled.</p>';
            return;
        }

        // comprobamos el token
        if (false === CrashReport::validateToken($_GET['token'] ?? '')) {
            echo '<p>Invalid token.</p>';
            return;
        }

        Plugins::deploy();

        echo '<p>Rebuild finished.</p>';
    }

    protected function rebuildMassAction(): void
    {
        // Limpiamos cualquier output previo para garantizar JSON puro.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        if (Tools::config('disable_deploy_actions', false)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Deploy actions disabled']);
            return;
        }

        // Token cross-tenant: el master lo guarda con keyInstallation = '' (cache global,
        // archivo MyFiles/Tmp/FileCache/mass-rebuild-token-XXX.cache sin sufijo de RUC).
        // El child lo lee con la misma key '' para acceder al mismo archivo.
        $token = $_GET['token'] ?? '';
        if (empty($token) || true !== Cache::get('mass-rebuild-token-' . $token, '')) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
            return;
        }

        $start = microtime(true);
        $error = null;

        // Capturamos cualquier output que Plugins::deploy pueda emitir.
        ob_start();
        try {
            // (true, true) = clean Dinamic + initControllers, lo cual SÍ ejecuta
            // Init::init/update de cada plugin → crea/migra tablas en la BD del tenant.
            // El rebuild estándar (case 'rebuild') usa Plugins::deploy() sin args y NO
            // ejecuta init/update — por eso no es suficiente para propagar tablas.
            //
            // OJO: NO llamar Cache::clear() aquí. Cache::clear() borra todos los .cache
            // en MyFiles/Tmp/FileCache, incluyendo el token global del rebuild masivo,
            // y los siguientes tenants devolverian 'Invalid or expired token'.
            Plugins::deploy(true, true);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $deployOutput = ob_get_clean();
        $elapsed = (int) ((microtime(true) - $start) * 1000);

        echo json_encode([
            'status' => $error ? 'error' : 'ok',
            'db' => defined('FS_DB_NAME') ? FS_DB_NAME : null,
            'elapsed_ms' => $elapsed,
            'message' => $error,
            'output_excerpt' => $deployOutput ? mb_substr(strip_tags($deployOutput), 0, 500) : null,
        ]);
    }
}
