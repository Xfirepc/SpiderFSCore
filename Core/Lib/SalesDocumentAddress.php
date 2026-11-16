<?php

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Tools;

/** Dirección de la copia del documento; no modifica la ficha del cliente. */
final class SalesDocumentAddress
{
    public const MAX_LENGTH = 100;

    public static function normalize(?string $address): string
    {
        $address = Tools::noHtml($address) ?? '';
        $address = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $address);

        return rtrim(mb_substr($address, 0, self::MAX_LENGTH, 'UTF-8'));
    }
}
