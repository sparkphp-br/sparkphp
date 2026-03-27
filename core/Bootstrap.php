<?php

class Bootstrap
{
    private static ?Bootstrap $instance = null;
    private Container $container;
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        static::$instance = $this;
    }

    public static function getInstance(): static
    {
        return static::$instance;
    }

    public function boot(): void
    {
        $this->loadEnv();
        $this->initContainer();
        $this->registerAutoloader();
        $this->loadHelpers();
        $this->configureRuntime();
        $this->initSession();
        $this->initLogger();
    }

    private function loadEnv(): void
    {
        $cacheFile = $this->basePath . '/storage/cache/env.php';

        if (file_exists($cacheFile) && !$this->isDev()) {
            $env = require $cacheFile;
            foreach ($env as $key => $value) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
            return;
        }

        $envFile = $this->basePath . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $env = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $env[$key] = $value;
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }

        // Cache in production
        if (!$this->isDev()) {
            $this->ensureDir(dirname($cacheFile));
            file_put_contents($cacheFile, '<?php return ' . var_export($env, true) . ';');
        }
    }

    private function initContainer(): void
    {
        require_once __DIR__ . '/Container.php';
        $this->container = new Container();
        $this->container->singleton(Container::class, fn() => $this->container);
        $this->container->singleton(Bootstrap::class, fn() => $this);
    }

    private function registerAutoloader(): void
    {
        require_once __DIR__ . '/Autoloader.php';
        $autoloader = new Autoloader($this->basePath);
        $autoloader->register();
        $this->container->singleton(Autoloader::class, fn() => $autoloader);
    }

    private function loadHelpers(): void
    {
        require_once __DIR__ . '/helpers.php';
    }

    private function configureRuntime(): void
    {
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');
        mb_internal_encoding('UTF-8');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(fn(\Throwable $e) => $this->handleException($e));
    }

    private function initSession(): void
    {
        require_once __DIR__ . '/Session.php';
        $session = new Session($this->basePath);
        $session->start();
        $this->container->singleton(Session::class, fn() => $session);
    }

    private function initLogger(): void
    {
        require_once __DIR__ . '/Logger.php';
        $logger = new Logger($this->basePath);
        $this->container->singleton(Logger::class, fn() => $logger);
    }

    private function handleException(\Throwable $e): never
    {
        if (class_exists('SparkInspector')) {
            SparkInspector::recordException($e);
        }

        $code = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        if (http_response_code() < 400) {
            http_response_code($code);
        }

        // Log all 5xx errors
        if ($code >= 500) {
            try {
                $this->container->make(Logger::class)->exception($e);
            } catch (\Throwable) {}
        }

        require_once __DIR__ . '/Response.php';
        require_once __DIR__ . '/View.php';

        $request = $this->container->has(Request::class)
            ? $this->container->make(Request::class)
            : null;

        if ($request && $request->acceptsJson()) {
            Response::json([
                'error' => $this->isDev() ? $e->getMessage() : 'Server Error',
                'exception' => $this->isDev() ? get_class($e) : null,
            ], $code)->send();
            exit;
        }

        if ($this->isDev()) {
            Response::html($this->renderDevError($e, $code), $code)->send();
            exit;
        }

        $viewFile = $this->basePath . "/app/views/errors/{$code}.spark";
        $fallback = $this->basePath . "/app/views/errors/500.spark";
        $target   = file_exists($viewFile) ? $viewFile : (file_exists($fallback) ? $fallback : null);

        if ($target) {
            try {
                $view = new View($this->basePath);
                Response::html($view->render("errors/{$code}", ['error' => $e, 'code' => $code]), $code)->send();
                exit;
            } catch (\Throwable) {}
        }

        Response::html("<h1>Error {$code}</h1>", $code)->send();
        exit;
    }

    private function renderDevError(\Throwable $e, int $code): string
    {
        $class   = htmlspecialchars(get_class($e));
        $message = htmlspecialchars($e->getMessage());
        $file    = htmlspecialchars($e->getFile());
        $line    = $e->getLine();
        $trace   = htmlspecialchars($e->getTraceAsString());

        return <<<HTML
        <!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Error {$code}</title>
        <style>
            body{font-family:monospace;background:#0f172a;color:#e2e8f0;margin:0;padding:2rem}
            .box{background:#1e293b;border-radius:8px;padding:2rem;margin-bottom:1rem}
            .badge{background:#ef4444;color:#fff;padding:.2rem .6rem;border-radius:4px;font-size:.8rem}
            .file{color:#94a3b8;font-size:.9rem}
            pre{background:#0f172a;padding:1rem;border-radius:4px;overflow:auto;font-size:.85rem;color:#cbd5e1}
        </style></head><body>
        <div class="box">
            <span class="badge">{$code}</span>
            <h2 style="margin:.5rem 0;color:#f87171">{$class}</h2>
            <p style="font-size:1.1rem">{$message}</p>
            <p class="file">{$file} <strong>line {$line}</strong></p>
        </div>
        <div class="box"><pre>{$trace}</pre></div>
        </body></html>
        HTML;
    }

    public function run(): void
    {
        // Load remaining core components
        foreach (['Request','Response','Router','Middleware','View','Cache','Database','Model','EventEmitter','Validator','Mailer','Queue','SparkInspectorStorage','SparkInspector'] as $class) {
            require_once __DIR__ . "/{$class}.php";
        }

        EventEmitter::setBasePath($this->basePath);
        SparkInspector::boot($this->basePath);
        $this->container->singleton(SparkInspector::class, fn() => SparkInspector::getInstance());

        $request = new Request();
        $this->container->singleton(Request::class, fn() => $request);
        SparkInspector::startRequest($request);

        $cache = new Cache($this->basePath);
        $this->container->singleton(Cache::class, fn() => $cache);

        $this->container->singleton(Queue::class, fn() => new Queue($this->basePath));
        $this->container->bind(Mailer::class, fn() => new Mailer());

        if (SparkInspector::isInspectorPath($request->path())) {
            if (SparkInspector::handleInternalRequest($request)) {
                return;
            }

            $this->abort404($request);
            return;
        }

        $router  = new Router($this->basePath);
        $match   = $router->resolve($request->path(), $request->method());

        if (!$match) {
            $this->abort404($request);
            return;
        }
        if (($match['status'] ?? 200) === 405) {
            $this->abort405($request, $match['allowed'] ?? []);
            return;
        }

        ['handler' => $handler, 'params' => $params, 'middlewares' => $middlewares] = $match;

        // Middleware pipeline
        $pipeline = new Middleware($this->basePath, $middlewares);
        $early    = $pipeline->run();
        if ($early !== null) {
            $early->send();
            return;
        }

        // Execute handler
        $result = $this->container->call($handler, $params);

        // Resolve and send response
        $response = new Response();
        $view     = new View($this->basePath);
        $this->container->singleton(View::class, fn() => $view);

        $response->resolve($result, $request, $view, $match['route'] ?? '');
    }

    private function abort404(Request $request): void
    {
        require_once __DIR__ . '/Response.php';
        $viewFile = $this->basePath . '/app/views/errors/404.spark';
        if (file_exists($viewFile)) {
            require_once __DIR__ . '/View.php';
            $view = new View($this->basePath);
            Response::html($view->render('errors/404', []), 404)->send();
        } else {
            Response::html('<h1>404 - Not Found</h1>', 404)->send();
        }
    }

    private function abort405(Request $request, array $allowed = []): void
    {
        require_once __DIR__ . '/Response.php';

        $response = $request->acceptsJson()
            ? Response::json(['error' => 'Method Not Allowed'], 405)
            : Response::html($this->render405View(), 405);

        if (!empty($allowed)) {
            $response->header('Allow', implode(', ', $allowed));
        }
        $response->send();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    private function isDev(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'dev') === 'dev';
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function render405View(): string
    {
        $viewFile = $this->basePath . '/app/views/errors/405.spark';
        if (file_exists($viewFile)) {
            require_once __DIR__ . '/View.php';
            $view = new View($this->basePath);
            return $view->render('errors/405', []);
        }

        return '<h1>405 - Method Not Allowed</h1>';
    }
}
