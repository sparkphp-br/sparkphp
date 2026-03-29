<?php

class Router
{
    private const DIRECTORY_MIDDLEWARE_FILE = '_middleware.php';

    /** @internal Used by global route helpers (get/post/etc.) */
    public static array $_collected = [];
    /** @internal Used by route helper chaining such as ->guard('auth') */
    public static array $_meta = [];
    /** @internal Used by path() to redefine the route URL */
    public static ?string $_path = null;
    /** @internal Used by name() / path()->name() to assign a route name */
    public static ?string $_routeName = null;

    private string $basePath;
    private array $routes = [];
    private string $cacheFile;
    private static array $namedRoutes = [];

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

    public static function getNamedRoutes(): array
    {
        return self::$namedRoutes;
    }

    // ─────────────────────────────────────────────
    // Route map building
    // ─────────────────────────────────────────────

    private function loadRoutes(): array
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';

        if (!$isDev && file_exists($this->cacheFile)) {
            $cached = require $this->cacheFile;
            if (isset($cached['routes'])) {
                self::$namedRoutes = $cached['names'] ?? [];
                return $cached['routes'];
            }
            return $cached;
        }

        return $this->buildRoutes();
    }

    public function buildRoutes(): array
    {
        $routesDir = $this->basePath . '/app/routes';
        if (!is_dir($routesDir)) {
            return [];
        }

        self::$namedRoutes = [];
        $routes = [];
        $this->scanDir($routesDir, $routesDir, [], $routes);

        // Sort: exact first (no params = higher priority)
        usort($routes, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $this->saveCache($routes);
        return $routes;
    }

    private function scanDir(string $dir, string $base, array $middlewares, array &$routes): void
    {
        $middlewares = $this->mergeMiddlewares(
            $middlewares,
            $this->loadDirectoryMiddlewareFile($dir)
        );

        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($entry === self::DIRECTORY_MIDDLEWARE_FILE) {
                continue;
            }

            $fullPath = $dir . '/' . $entry;

            if (is_dir($fullPath)) {
                // Middleware folder: [auth] or [auth+throttle]
                if (preg_match('/^\[(.+)]$/', $entry, $m)) {
                    $names = array_map('trim', explode('+', $m[1]));
                    $this->scanDir($fullPath, $base, $this->mergeMiddlewares($middlewares, $names), $routes);
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

            // Extract path() / name() metadata from the route file
            $routeName = null;
            $originalUrl = $url;
            $content = file_get_contents($fullPath);
            if (str_contains($content, 'path(') || str_contains($content, 'name(')) {
                $this->extractRouteMetadata($fullPath);

                if (self::$_path !== null) {
                    [$urlSegments, $paramNames, $hasParams] = $this->parseAliasPath(self::$_path);
                    $url     = '/' . implode('/', array_filter($urlSegments));
                    $pattern = $this->buildPattern($urlSegments, $paramNames);
                }

                $routeName = self::$_routeName;
                self::$_path = null;
                self::$_routeName = null;
            }

            if ($routeName !== null) {
                self::$namedRoutes[$routeName] = $url;
            }

            $routes[] = [
                'file'        => $fullPath,
                'url'         => $url,
                'pattern'     => $pattern,
                'paramNames'  => $paramNames,
                'middlewares' => $middlewares,
                'priority'    => $hasParams ? 10 : 1,
                'name'        => $routeName,
                'originalUrl' => $originalUrl !== $url ? $originalUrl : null,
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

    private function loadDirectoryMiddlewareFile(string $dir): array
    {
        $file = $dir . '/' . self::DIRECTORY_MIDDLEWARE_FILE;
        if (!is_file($file)) {
            return [];
        }

        $middlewares = (static function () use ($file) {
            return require $file;
        })();

        return $this->normalizeMiddlewareList($middlewares, $file);
    }

    private function normalizeMiddlewareList(mixed $middlewares, string $file): array
    {
        if ($middlewares === null) {
            return [];
        }

        if (is_string($middlewares)) {
            $middlewares = [$middlewares];
        }

        if (!is_array($middlewares)) {
            throw new RuntimeException("Route middleware file [{$file}] must return a string, array, or null.");
        }

        $normalized = [];
        $stack = array_values($middlewares);

        while ($stack !== []) {
            $item = array_shift($stack);

            if ($item === null) {
                continue;
            }

            if (is_array($item)) {
                array_unshift($stack, ...array_values($item));
                continue;
            }

            if (!is_string($item)) {
                throw new RuntimeException("Route middleware file [{$file}] contains an invalid middleware entry.");
            }

            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    private function mergeMiddlewares(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            foreach ($group as $middleware) {
                if (!in_array($middleware, $merged, true)) {
                    $merged[] = $middleware;
                }
            }
        }

        return $merged;
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

    /**
     * Load a route file to extract only path()/name() metadata.
     * Resets all static state afterward.
     */
    private function extractRouteMetadata(string $file): void
    {
        self::$_collected = [];
        self::$_meta = [];
        self::$_path = null;
        self::$_routeName = null;

        (static function () use ($file) {
            require $file;
        })();

        // Keep only $_path and $_routeName; discard handlers
        self::$_collected = [];
        self::$_meta = [];
    }

    /**
     * Parse a path() alias string into URL segments and param names.
     *
     * Supports both :param and {param} syntax:
     *   '/documents/:slug'  → ['documents', ':slug'], ['slug'], true
     *   '/documents/{slug}' → ['documents', ':slug'], ['slug'], true
     */
    private function parseAliasPath(string $path): array
    {
        $path     = trim($path, '/');
        $parts    = $path === '' ? [] : explode('/', $path);
        $segments   = [];
        $paramNames = [];
        $hasParams  = false;

        foreach ($parts as $part) {
            if (preg_match('/^\{(.+)\}$/', $part, $m) || preg_match('/^:(.+)$/', $part, $m)) {
                $segments[]   = ':' . $m[1];
                $paramNames[] = $m[1];
                $hasParams    = true;
            } else {
                $segments[] = $part;
            }
        }

        return [$segments, $paramNames, $hasParams];
    }

    private function loadHandlers(string $file): array
    {
        self::$_collected = [];
        self::$_meta = [];
        self::$_path = null;
        self::$_routeName = null;

        (static function () use ($file) {
            require $file;
        })();

        $collected = self::$_collected;
        $meta      = self::$_meta;
        self::$_collected = [];
        self::$_meta = [];
        self::$_path = null;
        self::$_routeName = null;

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
        $data = [
            'routes' => $cacheable,
            'names'  => self::$namedRoutes,
        ];
        file_put_contents($this->cacheFile, '<?php return ' . var_export($data, true) . ';');
    }
}
