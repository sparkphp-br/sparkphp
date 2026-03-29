<?php

class SparkVersion
{
    public const FALLBACK = '0.8.0';

    public static function current(?string $basePath = null): string
    {
        $path = self::versionFile($basePath);
        if (is_file($path)) {
            $version = trim((string) file_get_contents($path));
            if (self::isValid($version)) {
                return $version;
            }
        }

        return self::FALLBACK;
    }

    public static function releaseLine(?string $version = null): string
    {
        $version ??= self::current();

        if (preg_match('/^(\d+)\.(\d+)\./', $version, $matches) !== 1) {
            return $version;
        }

        return $matches[1] . '.' . $matches[2] . '.x';
    }

    public static function define(?string $basePath = null): string
    {
        $version = self::current($basePath);

        if (!defined('SPARK_VERSION')) {
            define('SPARK_VERSION', $version);
        }

        return SPARK_VERSION;
    }

    public static function versionFile(?string $basePath = null): string
    {
        $root = $basePath !== null
            ? rtrim($basePath, '/\\')
            : dirname(__DIR__);

        return $root . '/VERSION';
    }

    private static function isValid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }
}
