<?php
namespace Wagy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Autoloader
{
    private static $prefix;
    private static $base_dir;

    public static function init(string $prefix, string $base_dir): void {
        self::$prefix   = $prefix;
        self::$base_dir = rtrim($base_dir, '/\\') . '/';
        spl_autoload_register([__CLASS__, 'load']);
    }

    private static function load(string $class): void {
        $len = strlen(self::$prefix);
        if (strncmp(self::$prefix, $class, $len) !== 0) return;

        $relative = substr($class, $len);
        $file     = self::$base_dir . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) require $file;
    }
}
