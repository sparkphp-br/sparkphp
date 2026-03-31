<?php

declare(strict_types=1);

function sparkCacheClear(): void
{
    $dirs = [
        SPARK_BASE . '/storage/cache/env.php',
        SPARK_BASE . '/storage/cache/classes.php',
        SPARK_BASE . '/storage/cache/routes.php',
    ];
    foreach ($dirs as $path) {
        if (file_exists($path)) {
            unlink($path);
            out('  ' . color('Cleared ', 'dim') . color(basename($path), 'cyan'));
        }
    }

    // Clear app cache files
    foreach (glob(SPARK_BASE . '/storage/cache/app/*.cache') as $f) {
        unlink($f);
    }
    success('Cache cleared.');
}

function sparkViewsCache(): void
{
    require_once SPARK_BASE . '/core/View.php';
    $view    = new View(SPARK_BASE);
    $viewDir = SPARK_BASE . '/app/views';
    $count   = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'spark') {
            $rel = str_replace([$viewDir . '/', $viewDir . '\\', '.spark'], ['', '', ''], $file->getPathname());
            try {
                $view->render($rel, []);
                $count++;
            } catch (\Throwable $e) {
                out('  ' . color("Warning [{$rel}]: " . $e->getMessage(), 'yellow'));
            }
        }
    }
    success("{$count} views cached.");
}

function sparkViewsClear(): void
{
    foreach (glob(SPARK_BASE . '/storage/cache/views/*.php') as $f) {
        unlink($f);
    }
    success('View cache cleared.');
}

function sparkRoutesCache(): void
{
    require_once SPARK_BASE . '/core/helpers.php';
    require_once SPARK_BASE . '/core/Router.php';
    $router = new Router(SPARK_BASE);
    $routes = $router->buildRoutes();
    success(count($routes) . ' routes cached.');
}

function sparkRoutesClear(): void
{
    $file = SPARK_BASE . '/storage/cache/routes.php';
    if (file_exists($file)) {
        unlink($file);
    }
    success('Route cache cleared.');
}

function sparkRoutesList(): void
{
    require_once SPARK_BASE . '/core/helpers.php';
    require_once SPARK_BASE . '/core/Router.php';
    $router = new Router(SPARK_BASE);
    $routes = $router->list();

    echo "\n";
    out('  ' . color(str_pad('URL', 32), 'yellow') . color(str_pad('Name', 20), 'yellow') . color(str_pad('Middlewares', 24), 'yellow') . color('File', 'yellow'));
    out('  ' . color(str_repeat('─', 100), 'dim'));

    foreach ($routes as $route) {
        $mwRaw = implode(', ', $route['middlewares']);
        $mw    = $mwRaw ?: '—';
        $file  = str_replace(SPARK_BASE . '/app/routes/', '', $route['file']);
        $name  = $route['name'] ?? null;
        $alias = $route['originalUrl'] ?? null;

        $urlCol  = $route['url'];
        if ($alias) {
            $urlCol .= color(' ← ' . $alias, 'dim');
        }

        $namePad = str_pad($name ?: '—', 20);
        $nameStr = $name ? color($namePad, 'magenta') : color($namePad, 'dim');
        $mwPad   = str_pad($mw, 24);
        $mwStr   = $mwRaw ? color($mwPad, 'cyan') : color($mwPad, 'dim');

        out('  ' . color(str_pad($route['url'], 32), 'green') . $nameStr . $mwStr . color($file, 'dim'));
    }
    echo "\n";
}

function sparkApiSpec(array $args): void
{
    require_once SPARK_BASE . '/core/OpenApiGenerator.php';

    $output = SPARK_BASE . '/storage/api/openapi.json';
    $onlyApi = true;

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--output=')) {
            $output = SPARK_BASE . '/' . ltrim(substr($arg, 9), '/');
            continue;
        }

        if ($arg === '--all') {
            $onlyApi = false;
        }
    }

    $generator = new OpenApiGenerator(SPARK_BASE);
    $writtenTo = $generator->write([
        'output' => $output,
        'only_api' => $onlyApi,
    ]);

    success('OpenAPI spec generated at ' . str_replace(SPARK_BASE . '/', '', $writtenTo));
}

function sparkOptimize(): void
{
    sparkCacheClear();
    sparkRoutesCache();
    sparkViewsCache();
    success('Application optimized for production.');
}

function sparkInspectorClear(): void
{
    require_once SPARK_BASE . '/core/SparkInspectorStorage.php';
    $storage = new SparkInspectorStorage(SPARK_BASE);
    $cleared = $storage->clear();
    success("Inspector history cleared ({$cleared} request file(s)).");
}

function sparkInspectorStatus(): void
{
    require_once SPARK_BASE . '/core/SparkInspectorStorage.php';
    require_once SPARK_BASE . '/core/SparkInspector.php';

    SparkInspector::boot(SPARK_BASE);
    $inspector = SparkInspector::getInstance();
    $status = $inspector?->status() ?? ['enabled' => false, 'prefix' => '/_spark', 'storage' => []];

    echo "\n";
    out('  ' . color('Enabled: ', 'yellow') . color($status['enabled'] ? 'yes' : 'no', $status['enabled'] ? 'green' : 'red'));
    out('  ' . color('Prefix:  ', 'yellow') . color($status['prefix'], 'cyan'));
    out('  ' . color('Dir:     ', 'yellow') . color($status['storage']['directory'] ?? '-', 'dim'));
    out('  ' . color('History: ', 'yellow') . color((string) ($status['storage']['stored_requests'] ?? 0), 'white')
        . color('/' . (string) ($status['storage']['history_limit'] ?? 0), 'dim'));
    echo "\n";
}

