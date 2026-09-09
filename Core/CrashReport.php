<?php

namespace FacturaScripts\Core;

/**
 * La clase que se encarga de gestionar los errores fatales.
 */
final class CrashReport
{
    /** @var int Nivel del buffer propio, para descartar también salidas parciales de Twig. */
    private static $bufferLevel = 0;

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
        self::$bufferLevel = ob_get_level();

        register_shutdown_function('FacturaScripts\Core\CrashReport::shutdown');
    }

    public static function clearOutput(): void
    {
        $level = max(1, self::$bufferLevel);
        while (ob_get_level() > $level) {
            if (!ob_end_clean()) {
                break;
            }
        }
        if (ob_get_level() === $level) {
            ob_clean();
        }
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

        self::clearOutput();

        http_response_code(500);

        $info = self::getErrorInfo($error['type'], $error['message'], $error['file'], $error['line']);
        self::save($info);

        echo ErrorPage::response($info);
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
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

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
        return ErrorPage::supportUrl($info);
    }
}
