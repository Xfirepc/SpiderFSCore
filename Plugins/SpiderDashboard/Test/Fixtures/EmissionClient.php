<?php

namespace FacturaScripts\Dinamic\Controller;

/** Cliente de prueba: registra efectos y nunca abre conexiones al SRI. */
class ElectronicDocController
{
    public static $calls = [];
    public static $remoteAuthorized = false;
    public static $timeout = false;
    public static $generated = true;

    public static function checkStatusAuth($document, $electronic): bool
    {
        self::$calls[] = 'consult';
        if (self::$timeout) {
            throw new \RuntimeException('Tiempo de espera de prueba');
        }
        return self::$remoteAuthorized;
    }

    public static function generateDocument($document): bool
    {
        self::$calls[] = 'generate';
        return self::$generated;
    }

    public static function xmlSign($document): bool
    {
        self::$calls[] = 'sign';
        return true;
    }

    public static function validateDocument($document): bool
    {
        self::$calls[] = 'receive';
        return true;
    }

    public static function authorizeDocument($document): bool
    {
        self::$calls[] = 'authorize';
        return true;
    }

    public static function printDocument($document): void
    {
        self::$calls[] = 'print';
    }

    public static function sendDocument($document): void
    {
        self::$calls[] = 'email';
    }
}
