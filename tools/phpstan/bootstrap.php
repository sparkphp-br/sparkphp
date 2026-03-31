<?php

declare(strict_types=1);

if (!defined('SPARK_BASE')) {
    define('SPARK_BASE', dirname(__DIR__, 2));
}

if (!defined('SPARK_VERSION')) {
    require_once SPARK_BASE . '/core/Version.php';
    SparkVersion::define(SPARK_BASE);
}

$_ENV['APP_ENV'] ??= 'dev';

