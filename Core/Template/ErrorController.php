<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Template;

use Exception;
use FacturaScripts\Core\Contract\ErrorControllerInterface;
use FacturaScripts\Core\CrashReport;
use FacturaScripts\Core\ErrorPage;
use FacturaScripts\Core\Tools;

abstract class ErrorController implements ErrorControllerInterface
{
    /** @var Exception */
    protected $exception;

    /** @var bool */
    protected $save_crash = false;

    /** @var string */
    protected $url;

    public function __construct(Exception $exception, string $url = '')
    {
        $this->exception = $exception;
        $this->url = $url;
    }

    protected function html(string $title, string $bodyHtml, string $bodyCss): string
    {
        $bodyCss = 'bg-light';
        return '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet"'
            . ' integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">'
            . '</head>'
            . '<body class="' . $bodyCss . '">' . $bodyHtml . '</body>'
            . '</html>';
    }

    protected function htmlCard(string $title, string $cardBody, string $bodyCss, string $table = ''): string
    {
        $info = CrashReport::getErrorInfo(
            $this->exception->getCode(),
            $this->exception->getMessage() . "\nStack trace:\n" . $this->exception->getTraceAsString(),
            $this->exception->getFile(),
            $this->exception->getLine()
        );
        $info['exception'] = get_class($this->exception);

        if ($this->save_crash) {
            CrashReport::save($info);
        }

        // Conservamos la firma para los controladores existentes, pero la vista
        // decide en el servidor qué información corresponde a cada usuario.
        $classParts = explode('\\', static::class);
        return ErrorPage::response($info, end($classParts));
    }

    protected function setSaveCrash(bool $save): void
    {
        $this->save_crash = $save;
    }
}
