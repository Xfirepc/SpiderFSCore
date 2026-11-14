<?php
namespace FacturaScripts\Core\Lib\Security;

use FacturaScripts\Core\Tools;

/** Contadores serializados con flock; no dependen de cookies ni de limpiar cachés. */
class RecoveryRateLimiter
{
    public function allow(string $key, int $limit): bool
    {
        $folder = Tools::folder('MyFiles', 'PasswordRecovery');
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            return false;
        }
        $path = $folder . '/' . hash('sha256', Tools::config('db_name') . "\0" . $key);
        $file = fopen($path, 'c+');
        if ($file === false) {
            return false;
        }
        try {
            if (!flock($file, LOCK_EX)) {
                return false;
            }
            $data = json_decode(stream_get_contents($file), true);
            $now = time();
            $times = array_values(array_filter(is_array($data) ? $data : [], static function ($time) use ($now) {
                return is_int($time) && $time > $now - 900;
            }));
            if (count($times) >= $limit) {
                return false;
            }
            $times[] = $now;
            rewind($file);
            return ftruncate($file, 0) && fwrite($file, json_encode($times)) !== false && fflush($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
