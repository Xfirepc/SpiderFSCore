<?php

namespace FacturaScripts\Core\Lib;

/**
 * Serializa la ejecución real del rebuild, incluso si su cliente se desconecta.
 * El archivo permanece para que todos los procesos bloqueen el mismo inode.
 */
final class PluginRebuildLock
{
    private const FILE_NAME = '/MyFiles/.plugin-rebuild-execution.lock';

    /** @var resource|null */
    private static $handle;

    /** @var int */
    private static $depth = 0;

    /** @var bool */
    private $owned = false;

    public function acquire(): bool
    {
        if ($this->owned) {
            return true;
        }
        if (is_resource(self::$handle)) {
            self::$depth++;
            $this->owned = true;
            return true;
        }

        $path = FS_FOLDER . self::FILE_NAME;
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            return false;
        }
        $exists = is_file($path);
        $handle = @fopen($path, 'c+');
        if (false === $handle) {
            return false;
        }
        if (!$exists) {
            @chmod($path, 0666);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        self::$handle = $handle;
        self::$depth = 1;
        $this->owned = true;
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['pid' => getmypid(), 'started_at' => date('c')]));
        fflush($handle);
        return true;
    }

    public function release(): void
    {
        if (!$this->owned) {
            return;
        }
        $this->owned = false;
        self::$depth--;
        if (self::$depth > 0) {
            return;
        }
        ftruncate(self::$handle, 0);
        fflush(self::$handle);
        flock(self::$handle, LOCK_UN);
        fclose(self::$handle);
        self::$handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
