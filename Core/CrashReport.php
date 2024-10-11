<?php

namespace FacturaScripts\Core;

/**
 * La clase que se encarga de gestionar los errores fatales.
 */
final class CrashReport
{
    public static function getErrorInfo(int $code, string $message, string $file, int $line): array
    {
        // calculamos un hash para el error, de forma que en la web podamos dar respuesta automáticamente
        $errorUrl = parse_url($_SERVER["REQUEST_URI"] ?? '', PHP_URL_PATH);
        $errorMessage = self::formatErrorMessage($message);
        $errorFile = str_replace(FS_FOLDER, '', $file);
        $errorHash = md5($code . $errorFile . $line . $errorMessage);
        $reportUrl = 'https://facturascripts.com/errores/' . $errorHash;
        $reportQr = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($reportUrl);

        return [
            'code' => $code,
            'message' => Tools::noHtml($errorMessage),
            'file' => $errorFile,
            'line' => $line,
            'fragment' => self::getErrorFragment($file, $line),
            'hash' => $errorHash,
            'url' => $errorUrl,
            'report_url' => $reportUrl,
            'report_qr' => $reportQr,
            'core_version' => Kernel::version(),
            'php_version' => phpversion(),
            'os' => PHP_OS,
            'plugin_list' => implode(',', Plugins::enabled()),
        ];
    }

    public static function init(): void
    {
        ob_start();

        register_shutdown_function('FacturaScripts\Core\CrashReport::shutdown');
    }

    public static function newToken(): string
    {
        $seed = Tools::config('db_name') . Tools::config('db_user') . Tools::config('db_password');
        return md5($seed . date('Y-m-d H'));
    }

    public static function save(array $info): void
    {
        // si no existe la carpeta MyFiles, no podemos guardar el archivo
        if (!is_dir(Tools::folder('MyFiles'))) {
            return;
        }

        // guardamos los datos en un archivo en MyFiles
        $file_name = 'crash_' . $info['hash'] . '.json';
        $file_path = Tools::folder('MyFiles', $file_name);
        if (file_exists($file_path)) {
            return;
        }

        file_put_contents($file_path, json_encode($info, JSON_PRETTY_PRINT));
    }

    public static function shutdown(): void
    {
        $error = error_get_last();
        if (!isset($error) || in_array($error['type'], [E_WARNING, E_NOTICE, E_DEPRECATED, E_CORE_ERROR, E_CORE_WARNING])) {
            return;
        }

        // limpiamos el buffer si es necesario
        if (ob_get_length() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        $info = self::getErrorInfo($error['type'], $error['message'], $error['file'], $error['line']);
        self::save($info);

        // comprobamos si el content-type es json
        if (isset($_SERVER['CONTENT_TYPE']) && 'application/json' === $_SERVER['CONTENT_TYPE']) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $error['message'], 'info' => $info]);
            return;
        }

        // comprobamos si el content-type es text/plain
        if (isset($_SERVER['CONTENT_TYPE']) && 'text/plain' === $_SERVER['CONTENT_TYPE']) {
            header('Content-Type: text/plain');
            echo $error['message'];
            return;
        }

        $messageParts = explode("\nStack trace:\n", $info['message']);

        echo '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Ha ocurrido un problema #' . $info['code'] . '</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet"'
            . ' integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">'
            . '</head>'
            . '<body class="bg-light">'
            . '<div class="container mt-5 mb-5">'
            . '<div class="row justify-content-center">'
            . '<div class="col-sm-6">'
            . '<div class="card shadow">'
            . '<div class="card-body">'
            . '<img src="' . $info['report_qr'] . '" alt="' . $info['hash'] . '" class="float-end">'
            . '<h3 class="mt-0">Ha ocurrido un problema #' . $info['code'] . '</h3>'
            . '<p>' . nl2br($messageParts[0]) . '</p>'
            . '<p class="mb-0"><b>Url</b>: ' . $info['url'] . '</p>';

        if (Tools::config('debug', false)) {
            echo '<p class="mb-0"><b>File</b>: ' . $info['file'] . ', <b>line</b>: ' . $info['line'] . '</p>';
        }

        echo '<p class="mb-0"><b>Hash</b>: ' . $info['hash'] . '</p>';

        if (Tools::config('debug', false)) {
            echo '<p class="mb-0"><b>Core</b>: ' . $info['core_version']
                . ', <b>plugins</b>: ' . implode(', ', Plugins::enabled()) . '<br/>'
                . '<b>PHP</b>: ' . $info['php_version'] . ', <b>OS</b>: ' . $info['os'] . '</p>';

            echo '<pre style="border: solid 1px grey; margin: 2px; padding: 5px">' . htmlspecialchars_decode($info['fragment']) . '</pre>';
        }

        echo '</div>';

        if (Tools::config('debug', false) && isset($messageParts[1])) {
            echo '<div class="table-responsive">'
                . '<table class="table table-striped mb-0">'
                . '<thead><tr><th>#</th><th>Trace</th></tr></thead>'
                . '<tbody>';

            $num = 1;
            $trace = explode("\n", $messageParts[1]);
            foreach (array_reverse($trace) as $value) {
                if (trim($value) === 'thrown' || substr($value, 3) === '{main}') {
                    continue;
                }

                echo '<tr><td>' . $num . '</td><td>' . substr($value, 3) . '</td></tr>';
                $num++;
            }

            echo '<tr><td>' . $num . '</td><td>' . $info['file'] . ':' . $info['line'] . '</td></tr>';
            echo '</tbody></table></div>';
        }

        $url_message = static::generateFormattedMsg($info);
        echo '<div class="card-footer p-2">'
            . '<div class="row">'
            . '<div class="col">'
            . '<a class="btn btn-dark" href="'.$url_message.'" target="_blank">
               ' . self::trans('to-report') . '</a>'
            . '</div>';

        if (Tools::config('debug', false)) {
            echo '<div class="col-auto">'
                . '<a href="' . Tools::config('route') . '/deploy?action=disable-plugins&token=' . self::newToken()
                . '" class="btn btn-light">' . self::trans('disable-plugins') . '</a> '
                . '<a href="' . Tools::config('route') . '/deploy?action=rebuild&token=' . self::newToken()
                . '" class="btn btn-light">' . self::trans('rebuild') . '</a> '
                . '</div>';
        }

        echo '</div>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</body>'
            . '</html>';
    }

    public static function validateToken(string $token): bool
    {
        return $token === self::newToken();
    }

    private static function formatErrorMessage(string $message): string
    {
        // quitamos el folder de las rutas
        $message = str_replace(FS_FOLDER, '', $message);

        // partimos por la traza
        $messageParts = explode("Stack trace:", $message);

        // si hay error de json, lo añadimos al mensaje
        if (json_last_error()) {
            $messageParts[0] .= "\n" . json_last_error_msg();
        }

        // ahora volvemos a unir el mensaje
        return implode("\nStack trace:", $messageParts);
    }

    private static function trans(string $code): string
    {
        $translations = [
            'es_ES' => [
                'to-report' => 'Enviar informe',
                'disable-plugins' => 'Desactivar plugins',
                'rebuild' => 'Reconstruir',
            ],
            'es_EC' => [
                'to-report' => 'Enviar informe',
                'disable-plugins' => 'Desactivar plugins',
                'rebuild' => 'Reconstruir',
            ],
            'en_US' => [
                'to-report' => 'Send report',
                'disable-plugins' => 'Disable plugins',
                'rebuild' => 'Rebuild',
            ],
        ];

        return $translations[FS_LANG][$code] ?? $code;
    }

    protected static function getErrorFragment($file, $line, $linesToShow = 10): string
    {
        // leemos el archivo
        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        // calculamos el fragmento
        $startLine = ($line - ($linesToShow / 2)) - 1;
        $start = max($startLine, 0);
        $length = $linesToShow + 1;

        $errorFragment = array_slice($lines, $start, $length, true);
        foreach ($errorFragment as $index => $value) {
            $index++;

            // marcamos la línea del error
            if ($index === $line) {
                $errorFragment[$index] = '<spam style="padding-top: 0.1rem; padding-bottom: 0.1rem; '
                    . 'background-color: #951414; color: white">' . $index . $value . '</spam>';
                continue;
            }

            $errorFragment[$index] = $index . $value;
        }

        return implode("\n", $errorFragment);
    }

    public static function generateFormattedMsg($info)
    {
        $msg = "Hola, he tenido un problema desde " . $_SERVER['HTTP_HOST'];
        $msg .= "\n\n *URL:* " . $info['url'];
        $msg .= "\n *Mensaje:* " . $info['message'];
        $msg .= "\n *Archivo:* " . $info['file'];
        $msg .= "\n *Linea:* " . $info['line'];
        $msg .= "\n *Hash:* " . $info['hash'];
        $msg .= "\n *Core:* " . $info['core_version'];
        $msg .= "\n *Plugins:* " . $info['plugin_list'];
        $msg .= "\n *PHP:* " . $info['php_version'];
        $msg .= "\n *OS:* " . $info['os'];

        $url_report = 'https://api.whatsapp.com/send?phone=593987035780&text=' . urlencode($msg);
        return $url_report;
    }

}
