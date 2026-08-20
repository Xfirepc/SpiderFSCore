<?php

namespace FacturaScripts\Core\Base;

use FacturaScripts\Core\Tools;

/**
 * Runtime policy for the per-tenant page whitelist.
 *
 * Legacy installations deliberately keep the historical behaviour. License
 * and manual installations are deny-by-default when their JSON is missing or
 * invalid. This class has no dependency on SpiderBuilder so the guard also
 * works before a plugin class is loaded.
 */
final class TenantMenuPolicy
{
    public const LEGACY = 'legacy';
    public const LICENSE = 'license';
    public const MANUAL = 'manual';

    private static ?array $menuCache = null;
    private static ?string $menuCacheDatabase = null;

    public static function current(): string
    {
        $policy = (string)Tools::settings('main_system', 'menu_policy', self::LEGACY);
        return in_array($policy, [self::LEGACY, self::LICENSE, self::MANUAL], true)
            ? $policy
            : self::LEGACY;
    }

    public static function isManaged(): bool
    {
        return self::current() !== self::LEGACY;
    }

    /**
     * Returns null when the file is missing/corrupt. An empty but valid map is
     * allowed and means that every page is denied.
     */
    public static function menu(): ?array
    {
        $database = defined('FS_DB_NAME') ? (string)FS_DB_NAME : '';
        if (self::$menuCacheDatabase === $database && self::$menuCache !== null) {
            return self::$menuCache;
        }

        self::$menuCacheDatabase = $database;
        self::$menuCache = null;
        $path = FS_FOLDER . '/MyFiles/Menu/' . $database . '.json';
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $page => $allowed) {
            if (!is_string($page) || !is_bool($allowed)) {
                return null;
            }
        }

        self::$menuCache = $decoded;
        return self::$menuCache;
    }

    public static function resetCache(): void
    {
        self::$menuCache = null;
        self::$menuCacheDatabase = null;
    }

    public static function isExempt(string $pageName): bool
    {
        $lower = strtolower($pageName);
        return in_array($lower, ['login', 'logout', 'api', 'apiroot'], true)
            || str_starts_with($lower, 'api');
    }

    public static function allows(string $pageName, $user = null): bool
    {
        if (!self::isManaged() || self::isExempt($pageName)) {
            return true;
        }
        if ($user !== null && !empty($user->sysadmin)) {
            return true;
        }

        $menu = self::menu();
        return $menu !== null && !empty($menu[$pageName]);
    }
}
