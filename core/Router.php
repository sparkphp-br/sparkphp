<?php

class Router
{
    /** @internal Used by global route helpers (get/post/etc.) */
    public static array $_collected = [];
    /** @internal Used by route helper chaining such as ->guard('auth') */
    public static array $_meta = [];

    private string $basePath;
    private array $routes = [];
    private string $cacheFile;

    public function __construct(string $basePath)
    {
        $this->basePath  = $basePath;
        $this->cacheFile = $basePath . '/storage/cache/routes.php';
        $this->routes    = $this->loadRoutes();
    }

    // ─────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────

    public function resolve(string $urlPath, string $method): ?array
    {
        $urlPath = '/' . trim($urlPath, '/');
        $method  = strtolower($method);
        $allowedMethods = [];
        $methodNotAllowed = false;

        // Method override (PUT/PATCH/DELETE via _method in POST body)
        if ($method === 'post' && isset($_POST['_method'])) {
            $method = strtolower($_POST['_method']);
        }

        // Sort: exact patterns first, then parameterised
        usort($this->routes, fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($this->routes as $route) {
            $params = [];
            if (!$this->matchPattern($route['pattern'], $urlPath, $route['paramNames'], $params)) {
                continue;
            }

            $loaded   = $this->loadHandlers($route['file']);
            $handlers = $loaded['handlers'];
            $meta     = $loaded['meta'];
            $handler  = $handlers[$method] ?? $handlers['any'] ?? null;
            if ($handler === null) {
                // Path matches but verb not defined for this route file.
                $methodNotAllowed = true;
                foreach (array_keys($handlers) as $verb) {
                    $allowedMethods[] = strtoupper($verb);
                }
                continue;
            }

            $resolved = [
                'handler'     => $handler,
                'params'      => $params,
                'middlewares' => array_values(array_unique(array_merge(
                    $route['middlewares'],
                    $meta[$method]['middlewares'] ?? []
                ))),
                'route'       => $route['url'],
            ];

            if (class_exists('SparkInspector')) {
                SparkInspector::recordRoute([
                    'url' => $route['url'],
                    'params' => $params,
                    'middlewares' => $resolved['middlewares'],
                    'status' => 200,
                ]);
            }

            return $resolved;
        }

        if ($methodNotAllowed) {
            $allowedMethods = array_values(array_unique($allowedMethods));
            sort($allowedMethods);
            return [
                'status'  => 405,
                'allowed' => $allowedMethods,
            ];
        }

        return null;
    }

    public function list(): array
    {
        return $this->routes;
    }

    // ─────────────────────────────────────────────
    // Route map building
    // ─────────────────────────────────────────────

    private function loadRoutes(): array
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';

        if (!$isDev && file_exists($this->cacheFile)) {
            return require $this->cacheFile;
        }

        return $this->buildRoutes();
    }

    public function buildRoutes(): array
    {
        $routesDir = $this->basePath . '/app/routes';
        if (!is_dir($routesDir)) {
            return [];
        }

        $routes = [];
        $this->scanDir($routesDir, $routesDir, [], $routes);

        // Sort: exact first (no params = higher priority)
        usort($routes, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $this->saveCache($routes);
        return $routes;
    }

    private function scanDir(string $dir, string $base, array $middlewares, array &$routes): void
    {
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $entry;

            if (is_dir($fullPath)) {
                // Middleware folder: [auth] or [auth+throttle]
                if (preg_match('/^\[(.+)]$/', $entry, $m)) {
                    $names = array_map('trim', explode('+', $m[1]));
                    $this->scanDir($fullPath, $base, array_merge($middlewares, $names), $routes);
                } else {
                    $this->scanDir($fullPath, $base, $middlewares, $routes);
                }
                continue;
            }

            if (pathinfo($entry, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            [$urlSegments, $paramNames, $hasParams] = $this->fileToUrlSegments($fullPath, $base);

            $url     = '/' . implode('/', array_filter($urlSegments));
            $pattern = $this->buildPattern($urlSegments, $paramNames);

            $routes[] = [
                'file'        => $fullPath,
                'url'         => $url,
                'pattern'     => $pattern,
                'paramNames'  => $paramNames,
                'middlewares' => $middlewares,
                'priority'    => $hasParams ? 10 : 1,
            ];
        }
    }

    /**
     * Convert file path to URL segments and extract param names.
     *
     * Example: /app/routes/api/[auth]/users.[id].php
     *   → urlSegments: ['api', 'users', ':id']
     *   → paramNames:  ['id']
     */
    private function fileToUrlSegments(string $file, string $base): array
    {
        // Relative path from routes dir
        $relative = ltrim(str_replace([$base, '\\'], ['', '/'], $file), '/');
        // Remove .php
        $relative = preg_replace('/\.php$/', '', $relative);

        $parts      = explode('/', $relative);
        $segments   = [];
        $paramNames = [];
        $hasParams  = false;

        foreach ($parts as $part) {
            // Skip middleware folders [auth]
            if (preg_match('/^\[.+]$/', $part)) {
                continue;
            }

            // Handle filename with params: users.[id] → 'users', ':id'
            if (str_contains($part, '.[')) {
                $subParts = preg_split('/\.(?=\[)/', $part);
                foreach ($subParts as $sub) {
                    if (preg_match('/^\[(.+)]$/', $sub, $m)) {
                        $segments[]   = ':' . $m[1];
                        $paramNames[] = $m[1];
                        $hasParams    = true;
                    } else {
                        $segments[] = $sub;
                    }
                }
                continue;
            }

            // index → '' (empty = root of that level)
            if ($part === 'index') {
                // don't add segment
                continue;
            }

            $segments[] = $part;
        }

        return [$segments, $paramNames, $hasParams];
    }

    private function buildPattern(array $segments, array $paramNames): string
    {
        $pattern = '';
        foreach ($segments as $seg) {
            if (str_starts_with($seg, ':')) {
                $pattern .= '/([^/]+)';
            } else {
                $pattern .= '/' . preg_quote($seg, '#');
            }
        }
        return '#^' . ($pattern ?: '/') . '$#';
    }

    private function matchPattern(string $pattern, string $url, array $paramNames, array &$params): bool
    {
        if (!preg_match($pattern, $url, $matches)) {
            return false;
        }
        array_shift($matches); // remove full match

        foreach ($matches as $i => $value) {
            $value = urldecode($value);
            $params[$i] = $value;
            if (isset($paramNames[$i])) {
                $params[$paramNames[$i]] = $value;
            }
        }
        return true;
    }

    // ─────────────────────────────────────────────
    // Handler loading
    // ─────────────────────────────────────────────

    private function loadHandlers(string $file): array
    {
        self::$_collected = [];
        self::$_meta = [];

        (static function () use ($file) {
            require $file;
        })();

        $collected = self::$_collected;
        $meta      = self::$_meta;
        self::$_collected = [];
        self::$_meta = [];

        return [
            'handlers' => $collected,
            'meta'     => $meta,
        ];
    }

    // ─────────────────────────────────────────────
    // Cache
    // ─────────────────────────────────────────────

    private function saveCache(array $routes): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // Routes contain closures — we only cache the metadata, not handlers
        $cacheable = array_map(fn($r) => array_diff_key($r, ['handler' => true]), $routes);
        file_put_contents($this->cacheFile, '<?php return ' . var_export($cacheable, true) . ';');
    }
}
