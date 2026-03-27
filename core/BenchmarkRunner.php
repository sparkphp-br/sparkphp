<?php

class BenchmarkRunner
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function run(int $iterations = 200, int $warmup = 15): array
    {
        $this->loadDependencies();

        $iterations = max(1, $iterations);
        $warmup = max(0, $warmup);

        $previousEnv = $_ENV;
        $fixture = $this->createFixture();

        try {
            $this->prepareBenchFixture($fixture);

            $scenarios = [
                [
                    'name' => 'autoloader.map_build',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $autoloader = new Autoloader($fixture);
                        $autoloader->buildMap();
                    },
                ],
                [
                    'name' => 'autoloader.cache_load',
                    'compare_to' => 'autoloader.map_build',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'prod';
                        require $fixture . '/storage/cache/classes.php';
                    },
                ],
                [
                    'name' => 'router.routes_build',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $router = new Router($fixture);
                        $router->buildRoutes();
                    },
                ],
                [
                    'name' => 'router.resolve_static',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'prod';
                        $router = new Router($fixture);
                        $router->resolve('/api/health', 'GET');
                    },
                ],
                [
                    'name' => 'router.resolve_dynamic',
                    'compare_to' => 'router.resolve_static',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'prod';
                        $router = new Router($fixture);
                        $router->resolve('/api/users/42', 'GET');
                    },
                ],
                [
                    'name' => 'view.render_cold',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $compiledFile = $fixture . '/storage/cache/views/' . md5($fixture . '/app/views/index.spark') . '.php';
                        if (file_exists($compiledFile)) {
                            unlink($compiledFile);
                        }

                        $view = new View($fixture);
                        $view->render('index', ['message' => 'SparkPHP', 'items' => range(1, 10)]);
                    },
                ],
                [
                    'name' => 'view.render_warm',
                    'compare_to' => 'view.render_cold',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $view = new View($fixture);
                        $view->render('index', ['message' => 'SparkPHP', 'items' => range(1, 10)]);
                    },
                ],
                [
                    'name' => 'container.autowire',
                    'callback' => function (): void {
                        $container = new Container();
                        $container->make(BenchController::class);
                    },
                ],
                [
                    'name' => 'container.singleton_hit',
                    'compare_to' => 'container.autowire',
                    'callback' => function (): void {
                        $container = new Container();
                        $container->singleton(BenchLeafService::class, fn() => new BenchLeafService());
                        $container->make(BenchLeafService::class);
                        $container->make(BenchLeafService::class);
                    },
                ],
            ];

            $results = [];
            foreach ($scenarios as $scenario) {
                $results[] = $this->measureScenario(
                    $scenario['name'],
                    $scenario['callback'],
                    $iterations,
                    $warmup,
                    $scenario['compare_to'] ?? null
                );
            }

            return $this->buildReport($results, $iterations, $warmup);
        } finally {
            $_ENV = $previousEnv;
            $this->deleteDirectory($fixture);
        }
    }

    public function save(array $report, string $path): string
    {
        $fullPath = $path;
        if (!str_starts_with($path, '/')) {
            $fullPath = $this->basePath . '/' . ltrim($path, '/');
        }

        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $fullPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $fullPath;
    }

    private function buildReport(array $results, int $iterations, int $warmup): array
    {
        $indexed = [];
        foreach ($results as $result) {
            $indexed[$result['name']] = $result;
        }

        foreach ($results as &$result) {
            $baseline = $result['compare_to'] ?? null;
            if ($baseline === null || !isset($indexed[$baseline])) {
                unset($result['compare_to']);
                continue;
            }

            $baselineAvg = $indexed[$baseline]['avg_ns'];
            $result['comparison'] = [
                'against' => $baseline,
                'speedup' => $result['avg_ns'] > 0 ? $baselineAvg / $result['avg_ns'] : 0.0,
            ];

            unset($result['compare_to']);
        }
        unset($result);

        return [
            'generated_at' => date(DATE_ATOM),
            'php' => PHP_VERSION,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'scenarios' => $results,
        ];
    }

    private function measureScenario(
        string $name,
        callable $callback,
        int $iterations,
        int $warmup,
        ?string $compareTo = null
    ): array {
        for ($i = 0; $i < $warmup; $i++) {
            $callback();
        }

        $durations = [];
        $memoryDeltas = [];

        for ($i = 0; $i < $iterations; $i++) {
            gc_collect_cycles();

            $memoryBefore = memory_get_usage(true);
            $start = hrtime(true);
            $callback();
            $end = hrtime(true);
            $memoryAfter = memory_get_usage(true);

            $durations[] = $end - $start;
            $memoryDeltas[] = max(0, $memoryAfter - $memoryBefore);
        }

        sort($durations);

        $avgNs = (int) round(array_sum($durations) / count($durations));
        $medianNs = $this->percentile($durations, 50);
        $p95Ns = $this->percentile($durations, 95);
        $avgMemoryKb = array_sum($memoryDeltas) / max(1, count($memoryDeltas)) / 1024;

        return [
            'name' => $name,
            'avg_ns' => $avgNs,
            'avg_ms' => $avgNs / 1_000_000,
            'median_ms' => $medianNs / 1_000_000,
            'p95_ms' => $p95Ns / 1_000_000,
            'ops_per_second' => $avgNs > 0 ? 1_000_000_000 / $avgNs : 0.0,
            'memory_kb' => $avgMemoryKb,
            'compare_to' => $compareTo,
        ];
    }

    private function percentile(array $sortedValues, int $percentile): int
    {
        if ($sortedValues === []) {
            return 0;
        }

        $index = (int) ceil(($percentile / 100) * count($sortedValues)) - 1;
        $index = max(0, min(count($sortedValues) - 1, $index));

        return $sortedValues[$index];
    }

    private function prepareBenchFixture(string $fixture): void
    {
        $_ENV['APP_ENV'] = 'dev';

        $autoloader = new Autoloader($fixture);
        $autoloader->buildMap();

        $router = new Router($fixture);
        $router->buildRoutes();

        $view = new View($fixture);
        $view->render('index', ['message' => 'SparkPHP', 'items' => range(1, 10)]);

        $_ENV['APP_ENV'] = 'prod';
    }

    private function createFixture(): string
    {
        $fixture = sys_get_temp_dir() . '/sparkphp-bench-' . bin2hex(random_bytes(6));

        $directories = [
            'app/models',
            'app/routes/api',
            'app/services',
            'app/views/layouts',
            'storage/cache/app',
            'storage/cache/views',
            'storage/logs',
            'storage/sessions',
            'storage/uploads',
        ];

        foreach ($directories as $directory) {
            mkdir($fixture . '/' . $directory, 0777, true);
        }

        for ($i = 1; $i <= 20; $i++) {
            file_put_contents(
                $fixture . "/app/models/BenchModel{$i}.php",
                "<?php\n\nclass BenchModel{$i}\n{\n    public function id(): int\n    {\n        return {$i};\n    }\n}\n"
            );

            file_put_contents(
                $fixture . "/app/services/BenchService{$i}.php",
                "<?php\n\nclass BenchService{$i}\n{\n    public function name(): string\n    {\n        return 'service-{$i}';\n    }\n}\n"
            );
        }

        file_put_contents($fixture . '/app/routes/index.php', <<<'PHP'
<?php
get(fn() => ['message' => 'SparkPHP']);
PHP
        );

        file_put_contents($fixture . '/app/routes/api/health.php', <<<'PHP'
<?php
get(fn() => ['ok' => true]);
PHP
        );

        file_put_contents($fixture . '/app/routes/api/users.[id].php', <<<'PHP'
<?php
get(fn(string $id) => ['id' => $id]);
PHP
        );

        file_put_contents($fixture . '/app/views/layouts/main.spark', <<<'SPARK'
<!DOCTYPE html>
<html>
<head>
    <title>{{ $title }}</title>
</head>
<body>
    @content
</body>
</html>
SPARK
        );

        file_put_contents($fixture . '/app/views/index.spark', <<<'SPARK'
@title('Benchmark')
<section>
    <h1>{{ $message }}</h1>
    <ul>
        @foreach($items as $item)
            <li>Item {{ $item }}</li>
        @endforeach
    </ul>
</section>
SPARK
        );

        return $fixture;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function loadDependencies(): void
    {
        require_once __DIR__ . '/Bootstrap.php';
        require_once __DIR__ . '/Autoloader.php';
        require_once __DIR__ . '/Container.php';
        require_once __DIR__ . '/Cache.php';
        require_once __DIR__ . '/Router.php';
        require_once __DIR__ . '/View.php';
        require_once __DIR__ . '/helpers.php';
    }
}

class BenchLeafService
{
}

class BenchNestedService
{
    public function __construct(public BenchLeafService $leaf)
    {
    }
}

class BenchController
{
    public function __construct(public BenchNestedService $nested)
    {
    }
}
