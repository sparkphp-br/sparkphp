<?php

// ─────────────────────────────────────────────────────────────────────────────
// Route helpers — used inside app/routes/*.php files
// ─────────────────────────────────────────────────────────────────────────────

final class SparkRouteRegistration
{
    public function __construct(private array $verbs)
    {
    }

    public function guard(string ...$middlewares): static
    {
        foreach ($this->verbs as $verb) {
            $current = Router::$_meta[$verb]['middlewares'] ?? [];
            Router::$_meta[$verb]['middlewares'] = array_values(
                array_unique(array_merge($current, $middlewares))
            );
        }

        return $this;
    }
}

function sparkRouteRegister(string|array $verbs, callable $handler): SparkRouteRegistration
{
    $verbs = (array) $verbs;

    foreach ($verbs as $verb) {
        Router::$_collected[$verb] = $handler;
        Router::$_meta[$verb] ??= ['middlewares' => []];
    }

    return new SparkRouteRegistration($verbs);
}

function get(callable $handler): SparkRouteRegistration    { return sparkRouteRegister('get', $handler); }
function post(callable $handler): SparkRouteRegistration   { return sparkRouteRegister('post', $handler); }
function put(callable $handler): SparkRouteRegistration    { return sparkRouteRegister('put', $handler); }
function patch(callable $handler): SparkRouteRegistration  { return sparkRouteRegister('patch', $handler); }
function delete(callable $handler): SparkRouteRegistration { return sparkRouteRegister('delete', $handler); }
function any(callable $handler): SparkRouteRegistration    {
    return sparkRouteRegister(['get','post','put','patch','delete'], $handler);
}

// ─────────────────────────────────────────────────────────────────────────────
// Application
// ─────────────────────────────────────────────────────────────────────────────

function app(): Bootstrap
{
    return Bootstrap::getInstance();
}

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $default;
}

function config(string $key, mixed $default = null): mixed
{
    static $configs = [];

    // Format: 'file.key' or 'file.key.nested'
    $parts = explode('.', $key, 2);
    $file  = $parts[0];
    $subKey = $parts[1] ?? null;

    if (!isset($configs[$file])) {
        $path = app()->getBasePath() . "/app/config/{$file}.php";
        $configs[$file] = file_exists($path) ? (require $path) : [];
    }

    if ($subKey === null) {
        return $configs[$file] ?: $default;
    }

    // Support nested keys: 'app.name' → config['name']
    $value = $configs[$file];
    foreach (explode('.', $subKey) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

// ─────────────────────────────────────────────────────────────────────────────
// Request helpers
// ─────────────────────────────────────────────────────────────────────────────

function input(?string $key = null, mixed $default = null): mixed
{
    static $request = null;
    if (!$request) {
        try {
            $request = app()->getContainer()->make(Request::class);
        } catch (\Throwable) {
            // During boot, Container may not have Request yet
            if ($key === null) {
                return array_merge($_GET ?? [], $_POST ?? []);
            }
            return $_POST[$key] ?? $_GET[$key] ?? $default;
        }
    }
    return $key === null ? $request->input() : $request->input($key, $default);
}

function query(?string $key = null, mixed $default = null): mixed
{
    if ($key === null) {
        return $_GET ?? [];
    }
    return $_GET[$key] ?? $default;
}

function method(): string
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'POST' && isset($_POST['_method'])) {
        return strtoupper($_POST['_method']);
    }
    return $m;
}

function url(string $path = ''): string
{
    $base = rtrim(env('APP_URL', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('public/' . ltrim($path, '/'));
}

// ─────────────────────────────────────────────────────────────────────────────
// Session
// ─────────────────────────────────────────────────────────────────────────────

function session(string|array|null $key = null, mixed $default = null): mixed
{
    /** @var Session $sess */
    static $sess = null;
    if (!$sess) {
        try {
            $sess = app()->getContainer()->make(Session::class);
        } catch (\Throwable) {
            return null;
        }
    }

    if (is_array($key)) {
        $sess->put($key);
        return null;
    }

    if ($key === null) {
        return $sess;
    }

    return $sess->get($key, $default);
}

function old(string $field, mixed $default = null): mixed
{
    return session()?->old($field, $default) ?? $default;
}

function errors(?string $field = null): mixed
{
    return session()?->errors($field);
}

function csrf(): string
{
    return session()?->csrfToken() ?? '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Response helpers
// ─────────────────────────────────────────────────────────────────────────────

function redirect(string $url, int $status = 302): Response
{
    return Response::redirect($url, $status);
}

function back(): Response
{
    return redirect($_SERVER['HTTP_REFERER'] ?? '/');
}

function json(mixed $data, int $status = 200): Response
{
    return Response::json($data, $status);
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    if ($message) {
        $req = app()->getContainer()->has(Request::class)
            ? app()->getContainer()->make(Request::class)
            : null;

        if ($req && $req->acceptsJson()) {
            json(['error' => $message], $code);
        }
    }

    // Try to render error view
    $viewFile = app()->getBasePath() . "/app/views/errors/{$code}.spark";
    if (file_exists($viewFile)) {
        try {
            $view = new View(app()->getBasePath());
            echo $view->render("errors/{$code}", ['message' => $message, 'code' => $code]);
            exit;
        } catch (\Throwable) {}
    }

    echo "<h1>HTTP {$code}</h1>" . ($message ? "<p>" . htmlspecialchars($message) . "</p>" : '');
    exit;
}

function created(mixed $data): Response
{
    return Response::created($data);
}

function noContent(): Response
{
    return Response::noContent();
}

function notFound(string $message = 'Not Found'): Response
{
    return Response::notFound($message);
}

function download(string $filePath, ?string $name = null): Response
{
    return Response::download($filePath, $name);
}

// ─────────────────────────────────────────────────────────────────────────────
// View
// ─────────────────────────────────────────────────────────────────────────────

function view(string $name, array $data = []): string
{
    $view = new View(app()->getBasePath());
    return $view->render($name, $data);
}

// ─────────────────────────────────────────────────────────────────────────────
// Database
// ─────────────────────────────────────────────────────────────────────────────

function db(string $table = ''): QueryBuilder|Database
{
    $database = Database::getInstance();
    if ($table === '') {
        return $database;
    }
    return $database->table($table);
}

// ─────────────────────────────────────────────────────────────────────────────
// Cache
// ─────────────────────────────────────────────────────────────────────────────

function cache(string|array|null $key = null, mixed $default = null): mixed
{
    static $cache = null;
    if (!$cache) {
        try {
            $cache = app()->getContainer()->make(Cache::class);
        } catch (\Throwable) {
            $cache = new Cache(app()->getBasePath());
        }
    }

    if ($key === null) {
        return $cache;
    }

    if (is_array($key)) {
        // cache(['key' => 'value'], 3600)
        foreach ($key as $k => $v) {
            $cache->set($k, $v, is_int($default) ? $default : 0);
        }
        return null;
    }

    return $cache->get($key, $default);
}

// ─────────────────────────────────────────────────────────────────────────────
// Auth
// ─────────────────────────────────────────────────────────────────────────────

function auth(): mixed
{
    $userId = session('_auth_user_id');
    if (!$userId) {
        return null;
    }

    static $user = null;
    if ($user && $user->id == $userId) {
        return $user;
    }

    // Resolve User model from autoloaded classes
    if (class_exists('User')) {
        $user = User::find($userId);
    }

    return $user;
}

function login(mixed $user): void
{
    session(['_auth_user_id' => $user->id ?? $user]);
    session()?->regenerate();
}

function logout(): void
{
    session()?->forget('_auth_user_id');
    session()?->regenerate();
}

// ─────────────────────────────────────────────────────────────────────────────
// Events
// ─────────────────────────────────────────────────────────────────────────────

function emit(string $event, mixed $data = null): bool
{
    return EventEmitter::dispatch($event, $data);
}

// ─────────────────────────────────────────────────────────────────────────────
// Jobs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Dispatch a job.
 * - QUEUE=sync (default): runs immediately in the same process
 * - QUEUE=file: pushes to the file queue for async processing
 */
function dispatch(string $jobClass, mixed $data = null, string $q = 'default'): void
{
    if (class_exists('SparkInspector')) {
        SparkInspector::recordQueue([
            'type' => ($_ENV['QUEUE'] ?? 'sync') === 'sync' ? 'dispatch-sync' : 'dispatch',
            'job' => $jobClass,
            'queue' => $q,
            'data' => $data,
        ]);
    }

    if (($_ENV['QUEUE'] ?? 'sync') !== 'sync') {
        queue($jobClass, $data, $q);
        return;
    }

    if (!class_exists($jobClass)) {
        throw new \RuntimeException("Job not found: {$jobClass}");
    }
    $job = new $jobClass($data);
    if (method_exists($job, 'handle')) {
        $job->handle();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Validation
// ─────────────────────────────────────────────────────────────────────────────

function validate(array $rules, ?array $data = null): array
{
    $data = $data ?? array_merge(input() ?: [], $_FILES ?? []);
    return Validator::make($data, $rules)->validate();
}

// ─────────────────────────────────────────────────────────────────────────────
// Security
// ─────────────────────────────────────────────────────────────────────────────

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function verify(string $password, string $hash): bool
{
    return verify_password($password, $hash);
}

function encrypt(string $data): string
{
    $key    = env('APP_KEY', 'default-key-change-me');
    $key    = hash('sha256', $key, true);
    $iv     = random_bytes(16);
    $cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function decrypt(string $data): string|false
{
    $key      = env('APP_KEY', 'default-key-change-me');
    $key      = hash('sha256', $key, true);
    $decoded  = base64_decode($data, true);
    if ($decoded === false || strlen($decoded) < 17) {
        return false;
    }
    $iv       = substr($decoded, 0, 16);
    $cipher   = substr($decoded, 16);
    return openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

// ─────────────────────────────────────────────────────────────────────────────
// Debugging
// ─────────────────────────────────────────────────────────────────────────────

function dump(mixed ...$vars): void
{
    if (class_exists('SparkInspector')) {
        SparkInspector::recordDump(...$vars);
    }

    echo sparkDumpHtml(...$vars);
}

function dd(mixed ...$vars): never
{
    if (class_exists('SparkInspector')) {
        SparkInspector::recordDump(...$vars);
        SparkInspector::terminateWithHtml(sparkDumpHtml(...$vars));
    }

    echo sparkDumpHtml(...$vars);
    exit;
}

function inspect(mixed ...$values): void
{
    if (class_exists('SparkInspector')) {
        SparkInspector::inspect(...$values);
    }
}

function measure(string $label, callable $callback): mixed
{
    if (class_exists('SparkInspector')) {
        return SparkInspector::measure($label, $callback);
    }

    return $callback();
}

// ─────────────────────────────────────────────────────────────────────────────
// Logging
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the Logger singleton, or writes a message directly when called with args.
 * logger()                    → Logger instance
 * logger('msg')               → info log
 * logger('msg', 'error')      → error log
 * logger('msg', 'error', [])  → error log with context
 */
function logger(string $message = '', string $level = 'info', array $context = []): Logger
{
    $log = app()->getContainer()->make(Logger::class);
    if ($message !== '') {
        $log->log($level, $message, $context);
    }
    return $log;
}

/** Shorthand for quick log levels. */
function log_debug(string $message, array $context = []): void   { logger($message, 'debug', $context); }
function log_info(string $message, array $context = []): void    { logger($message, 'info', $context); }
function log_warning(string $message, array $context = []): void { logger($message, 'warning', $context); }
function log_error(string $message, array $context = []): void   { logger($message, 'error', $context); }

// ─────────────────────────────────────────────────────────────────────────────
// Mail
// ─────────────────────────────────────────────────────────────────────────────

/** Returns a fresh Mailer instance for chaining. */
function mailer(): Mailer
{
    return app()->getContainer()->make(Mailer::class);
}

// ─────────────────────────────────────────────────────────────────────────────
// Queue
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the Queue singleton, or pushes a job when called with args.
 * queue()                      → Queue instance
 * queue('JobClass', $data)     → push job to default queue
 * queue('JobClass', $data, 'q')→ push job to named queue
 */
function queue(string $job = '', mixed $data = null, string $q = 'default'): Queue
{
    $instance = app()->getContainer()->make(Queue::class);
    if ($job !== '') {
        $instance->push($job, $data, $q);
    }
    return $instance;
}

// ─────────────────────────────────────────────────────────────────────────────
// Date / Time
// ─────────────────────────────────────────────────────────────────────────────

function now(): \DateTimeImmutable
{
    return new \DateTimeImmutable();
}

function sparkDumpHtml(mixed ...$vars): string
{
    ob_start();
    echo '<pre style="background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:6px;font-size:13px;overflow:auto;">';
    foreach ($vars as $var) {
        var_dump($var);
    }
    echo '</pre>';
    return (string) ob_get_clean();
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF verification middleware helper
// ─────────────────────────────────────────────────────────────────────────────

function verifyCsrf(): void
{
    $methods = ['POST', 'PUT', 'PATCH', 'DELETE'];
    if (!in_array(method(), $methods, true)) {
        return;
    }

    $token = input('_csrf') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!session()?->verifyCsrf((string) $token)) {
        abort(419, 'CSRF token mismatch');
    }
}
