<?php

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Tools;
use RuntimeException;

/** Serializa metadatos y DDL por base; permite dependencias dentro del mismo proceso. */
final class SchemaCheckLock
{
    private static $handles = [];
    private $key;
    private $owned = false;

    public function __construct(string $database)
    {
        $this->key = Tools::folder('MyFiles', '.schema-check-' . hash('sha256', $database) . '.lock');
    }

    public function acquire(): void
    {
        if ($this->owned) {
            return;
        }
        if (isset(self::$handles[$this->key])) {
            self::$handles[$this->key]['depth']++;
            $this->owned = true;
            return;
        }
        if (!is_dir(dirname($this->key)) && !@mkdir(dirname($this->key), 0775, true) && !is_dir(dirname($this->key))) {
            throw new RuntimeException('No se puede crear la carpeta de bloqueos del esquema.');
        }
        $exists = is_file($this->key);
        $handle = @fopen($this->key, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('No se puede abrir el bloqueo del esquema de la base.');
        }
        if (!$exists) {
            @chmod($this->key, 0666);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('No se puede bloquear la comprobación del esquema.');
        }
        self::$handles[$this->key] = ['handle' => $handle, 'depth' => 1];
        $this->owned = true;
    }

    public function release(): void
    {
        if (!$this->owned) {
            return;
        }
        $this->owned = false;
        if (--self::$handles[$this->key]['depth'] > 0) {
            return;
        }
        $handle = self::$handles[$this->key]['handle'];
        unset(self::$handles[$this->key]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
