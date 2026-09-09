<?php

namespace FacturaScripts\Core;

use FacturaScripts\Core\Model\User;

/**
 * Presentación segura de errores, también cuando fallan Twig o la base de datos.
 */
final class ErrorPage
{
    public static function canShowDetails(): bool
    {
        // Usamos únicamente la identidad ya autenticada en esta petición.
        // Session::user() podría crear un modelo y volver a consultar una BD caída.
        $user = Session::get('user');
        return $user instanceof User && !empty($user->nick) && $user->enabled && $user->admin;
    }

    public static function publicInfo(array $info, string $handler = ''): array
    {
        $title = 'No pudimos completar esta acción';
        $message = 'Ocurrió un inconveniente al procesar tu solicitud.';
        $hint = 'Vuelve a la pantalla anterior y revisa el estado de la operación antes de intentarlo de nuevo.';
        $eyebrow = 'VAMOS A RESOLVERLO';
        $icon = 'connection';
        // Solo clasificamos el mensaje. Nunca mostramos texto de una excepción al usuario.
        $technicalMessage = strtolower(explode('Stack trace:', $info['message'] ?? '')[0]);

        // El tipo explícito tiene prioridad sobre el texto, que puede incluir una URL del usuario.
        if ($handler === 'PageNotFound') {
            $title = 'No encontramos esta página';
            $message = 'Puede que el enlace haya cambiado o que la página ya no esté disponible.';
            $hint = 'Revisa el enlace o vuelve al inicio para continuar.';
            $eyebrow = 'PÁGINA NO ENCONTRADA';
            $icon = 'search';
        } elseif ($handler === 'AccessDenied') {
            $title = 'No tienes acceso a esta sección';
            $message = 'Tu usuario no tiene permisos para abrir esta página.';
            $hint = 'Si necesitas acceder, pide al administrador que revise los permisos de tu usuario.';
            $eyebrow = 'ACCESO RESTRINGIDO';
            $icon = 'lock';
        } elseif ($handler === 'AlreadyInstalled') {
            $title = 'El sistema ya está instalado';
            $message = 'Puedes ingresar con tu cuenta para continuar.';
            $hint = 'Vuelve al inicio para acceder al sistema.';
            $eyebrow = 'TODO LISTO PARA CONTINUAR';
            $icon = 'check';
        } elseif ($handler === 'DatabaseError' || strpos($technicalMessage, 'sqlstate[') !== false) {
            $message = 'No pudimos acceder a la información del sistema en este momento.';
        } elseif (strpos($technicalMessage, 'sri.gob.ec') !== false
            && (strpos($technicalMessage, 'wsdl') !== false || strpos($technicalMessage, 'soap') !== false)) {
            $title = 'No pudimos conectar con el SRI';
            $message = 'No fue posible comunicarnos con el servicio de comprobantes electrónicos del SRI.';
            $hint = 'Vuelve al comprobante y revisa su estado antes de intentar enviarlo nuevamente.';
        } elseif (strpos($technicalMessage, 'wsdl') !== false
            || strpos($technicalMessage, 'could not resolve host') !== false
            || strpos($technicalMessage, 'connection refused') !== false
            || strpos($technicalMessage, 'connection timed out') !== false) {
            $message = 'No pudimos conectar con un servicio necesario para completar la operación.';
        } elseif ($handler === 'FileNotFound') {
            $title = 'No encontramos el archivo';
            $message = 'El archivo que buscas ya no está disponible o el enlace cambió.';
            $hint = 'Vuelve a la pantalla anterior y abre el archivo desde el sistema.';
        } elseif ($handler === 'MyfilesTokenError') {
            $title = 'Este enlace ya no está disponible';
            $message = 'Necesitamos un enlace válido para abrir este archivo.';
            $hint = 'Vuelve al sistema y abre el archivo nuevamente desde su documento.';
        } elseif (in_array($handler, ['UnsafeFile', 'UnsafeFolder'], true)) {
            $title = 'No podemos abrir este recurso';
            $message = 'Este archivo o ubicación no está disponible para su acceso desde el sistema.';
            $hint = 'Vuelve a la pantalla anterior o consulta con el administrador.';
        }

        return [
            'title' => $title,
            'message' => $message,
            'hint' => $hint,
            'eyebrow' => $eyebrow,
            'icon' => $icon,
            'reference' => strtoupper(substr($info['hash'] ?? '', 0, 12)),
        ];
    }

    public static function response(array $info, string $handler = ''): string
    {
        $public = self::publicInfo($info, $handler);
        $admin = self::canShowDetails();
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));

        if (!headers_sent()) {
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }

        if ($contentType === 'application/json') {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            $payload = ['error' => $public['message'], 'reference' => $public['reference']];
            if ($admin) {
                $payload['info'] = $info;
            }
            return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if ($contentType === 'text/plain') {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            return $admin ? $info['message'] : $public['message'] . "\nReferencia: " . $public['reference'];
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        return self::render($info, $handler);
    }

    public static function render(array $info, string $handler = ''): string
    {
        $public = self::publicInfo($info, $handler);
        $admin = self::canShowDetails();
        $homeUrl = rtrim((string)Tools::config('route', ''), '/') . '/';
        $supportUrl = self::supportUrl($info, $handler);
        $escape = static function ($value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $logoPath = __DIR__ . '/../Plugins/SpiderThemeClassic/Assets/Images/logo-sky.png';
        $logo = is_readable($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';

        // Una plantilla PHP autónoma permite mostrar errores del propio motor Twig.
        ob_start();
        require __DIR__ . '/View/Error/Standalone.html.php';
        return (string)ob_get_clean();
    }

    public static function supportUrl(array $info, string $handler = ''): string
    {
        $public = self::publicInfo($info, $handler);
        $message = 'Hola, necesito ayuda con SpiderCode.'
            . "\nReferencia: " . $public['reference']
            . "\n" . $public['message'];

        if (self::canShowDetails()) {
            $message .= "\n\nMensaje: " . html_entity_decode($info['message'] ?? '', ENT_QUOTES, 'UTF-8')
                . "\nArchivo: " . ($info['file'] ?? '')
                . "\nLínea: " . ($info['line'] ?? '')
                . "\nURL: " . ($info['url'] ?? '')
                . "\nHash: " . ($info['hash'] ?? '');
        }

        return 'https://api.whatsapp.com/send?phone=593987035780&text=' . rawurlencode($message);
    }
}
