<?php

declare(strict_types=1);

// ─── Base path is one level up from /public ───────────────────────────────
define('SPARK_BASE', dirname(__DIR__));

// ─── Load Bootstrap ───────────────────────────────────────────────────────
require_once SPARK_BASE . '/core/Bootstrap.php';

$app = new Bootstrap(SPARK_BASE);
$app->boot();
$app->run();
