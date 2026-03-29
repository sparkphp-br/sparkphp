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

final class SparkRoutePath
{
    public function name(string $name): static
    {
        Router::$_routeName = $name;
        return $this;
    }
}

/**
 * Redefine the URL path for this route file.
 *
 * Use :param or {param} for dynamic segments.
 *
 *   path('/documents');
 *   path('/documents/:slug');
 *   path('/documents/{slug}')->name('docs.show');
 */
function path(string $url): SparkRoutePath
{
    Router::$_path = $url;
    return new SparkRoutePath();
}

/**
 * Give this route a name for URL generation via route().
 *
 *   name('docs.index');
 */
function name(string $routeName): void
{
    Router::$_routeName = $routeName;
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

function base_path(string $path = ''): string
{
    try {
        $base = app()->getBasePath();
    } catch (\Throwable) {
        $base = dirname(__DIR__);
    }

    return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
}

function app_path(string $path = ''): string
{
    return base_path('app' . ($path === '' ? '' : '/' . ltrim($path, '/\\')));
}

function storage_path(string $path = ''): string
{
    return base_path('storage' . ($path === '' ? '' : '/' . ltrim($path, '/\\')));
}

function public_path(string $path = ''): string
{
    return base_path('public' . ($path === '' ? '' : '/' . ltrim($path, '/\\')));
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

function sparkTrustedProxies(): array
{
    $raw = trim((string) ($_ENV['TRUSTED_PROXIES'] ?? ''));
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', $raw)
    )));
}

function sparkIpMatchesTrustedProxy(string $ip, string $rule): bool
{
    if ($rule === '*') {
        return true;
    }

    if (!str_contains($rule, '/')) {
        return strcasecmp($ip, $rule) === 0;
    }

    [$subnet, $bits] = explode('/', $rule, 2);
    $ipBinary = inet_pton($ip);
    $subnetBinary = inet_pton($subnet);

    if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
        return false;
    }

    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    $remainder = $bits % 8;

    if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
        return false;
    }

    if ($remainder === 0) {
        return true;
    }

    $mask = (~((1 << (8 - $remainder)) - 1)) & 0xFF;

    return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
}

function sparkRequestUsesTrustedProxy(): bool
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remoteAddr === '') {
        return false;
    }

    foreach (sparkTrustedProxies() as $rule) {
        if (sparkIpMatchesTrustedProxy($remoteAddr, $rule)) {
            return true;
        }
    }

    return false;
}

function sparkForwardedHeader(string $name): ?string
{
    if (!sparkRequestUsesTrustedProxy()) {
        return null;
    }

    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = trim((string) ($_SERVER[$key] ?? ''));
    if ($value === '') {
        return null;
    }

    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part !== '') {
            return $part;
        }
    }

    return null;
}

function sparkRequestScheme(): string
{
    $forwardedProto = sparkForwardedHeader('X-Forwarded-Proto');
    if ($forwardedProto !== null) {
        return strtolower($forwardedProto) === 'https' ? 'https' : 'http';
    }

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            ? 'https'
            : 'http';
}

function sparkRequestHost(): string
{
    return sparkForwardedHeader('X-Forwarded-Host')
        ?? ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
}

function sparkRequestClientIp(): string
{
    $forwardedFor = sparkForwardedHeader('X-Forwarded-For');
    if ($forwardedFor !== null) {
        return $forwardedFor;
    }

    return $_SERVER['HTTP_CLIENT_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '127.0.0.1';
}

function request(): Request
{
    try {
        return app()->getContainer()->make(Request::class);
    } catch (\Throwable) {
        return new Request();
    }
}

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

function ip(): string
{
    return request()->ip();
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

/**
 * Generate a URL from a named route.
 *
 *   route('docs.index')                          → /documents
 *   route('docs.show', ['slug' => '02-routing']) → /documents/02-routing
 */
function route(string $name, array $params = []): string
{
    $named = Router::getNamedRoutes();

    $url = $named[$name] ?? null;
    if ($url === null) {
        throw new \RuntimeException("Route [{$name}] not defined.");
    }

    foreach ($params as $key => $value) {
        $url = str_replace(":{$key}", (string) $value, $url);
    }

    return url($url);
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

function flash(string $key, mixed $value = null): mixed
{
    $sess = session();

    if (!$sess instanceof Session) {
        return null;
    }

    if (func_num_args() > 1) {
        $sess->flash($key, $value);
        return null;
    }

    return $sess->getFlash($key);
}

function errors(?string $field = null): mixed
{
    return session()?->errors($field);
}

function csrf(): string
{
    return session()?->csrfToken() ?? '';
}

function session_regenerate(bool $deleteOld = true): void
{
    session()?->regenerate($deleteOld);
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

function stream(callable $callback, int $status = 200, array $headers = []): Response
{
    return Response::stream($callback, $status, $headers);
}

function abort(int $code, string $message = ''): never
{
    $message = $message !== '' ? $message : Response::statusText($code);
    $req = app()->getContainer()->has(Request::class)
        ? app()->getContainer()->make(Request::class)
        : null;

    if ($req && $req->wantsJson()) {
        Response::error($message, $code)->send();
        exit;
    }

    // Try to render error view
    $viewFile = app()->getBasePath() . "/app/views/errors/{$code}.spark";
    if (file_exists($viewFile)) {
        try {
            $view = new View(app()->getBasePath());
            Response::html($view->render("errors/{$code}", ['message' => $message, 'code' => $code]), $code)->send();
            exit;
        } catch (\Throwable) {}
    }

    http_response_code($code);
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

function schema(): Schema
{
    return Schema::connection();
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

function cache_remember(string $key, int $ttl, callable $callback): mixed
{
    $store = cache();

    if ($store instanceof Cache) {
        return $store->remember($key, $ttl, $callback);
    }

    return $callback();
}

function cache_flush(): void
{
    $store = cache();

    if ($store instanceof Cache) {
        $store->flush();
    }
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

function policy(mixed $subject): mixed
{
    $class = null;

    if (is_object($subject)) {
        $class = $subject::class;
    } elseif (is_string($subject) && class_exists($subject)) {
        $class = $subject;
    }

    if ($class === null) {
        return null;
    }

    $policyClass = (new ReflectionClass($class))->getShortName() . 'Policy';
    if (!class_exists($policyClass)) {
        return null;
    }

    try {
        return app()->getContainer()->make($policyClass);
    } catch (\Throwable) {
        return new $policyClass();
    }
}

function can(string $ability, mixed $subject, mixed $user = null, mixed ...$context): bool
{
    $policy = policy($subject);
    if (!$policy || !method_exists($policy, $ability)) {
        return false;
    }

    $actor = $user ?? auth();

    return (bool) $policy->{$ability}($actor, $subject, ...$context);
}

function authorize(string $ability, mixed $subject, mixed $user = null, mixed ...$context): bool
{
    if (can($ability, $subject, $user, ...$context)) {
        return true;
    }

    abort(403, 'This action is unauthorized.');
}

// ─────────────────────────────────────────────────────────────────────────────
// Events
// ─────────────────────────────────────────────────────────────────────────────

function emit(string $event, mixed $data = null): bool
{
    return EventEmitter::dispatch($event, $data);
}

function event(string $event, mixed $data = null): bool
{
    return emit($event, $data);
}

// ─────────────────────────────────────────────────────────────────────────────
// Jobs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Dispatch a job.
 * - connection=sync: runs immediately in the same process
 * - connection=file: pushes to storage/queue for async processing
 */
function dispatch(string $jobClass, mixed $data = null, ?string $q = null): void
{
    $queue = queue();
    $options = $queue->resolveDispatchOptions($jobClass, $q !== null ? ['queue' => $q] : []);

    if (class_exists('SparkInspector')) {
        SparkInspector::recordQueue([
            'type' => $options['connection'] === 'sync' ? 'dispatch-sync' : 'dispatch',
            'job' => $jobClass,
            'queue' => $options['queue'],
            'data' => $data,
            'tries' => $options['tries'],
            'timeout' => $options['timeout'],
        ]);
    }

    if ($options['connection'] !== 'sync') {
        $queue->push($jobClass, $data, $q);
        return;
    }

    $queue->dispatchSync($jobClass, $data, $q);
}

function dispatch_later(string $jobClass, mixed $data = null, int $delay = 0, ?string $q = null): void
{
    $queue = queue();
    $options = $queue->resolveDispatchOptions($jobClass, $q !== null ? ['queue' => $q] : []);

    if ($options['connection'] === 'sync') {
        dispatch($jobClass, $data, $q);
        return;
    }

    $queue->later($delay, $jobClass, $data, $q);
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
function log_notice(string $message, array $context = []): void  { logger($message, 'notice', $context); }
function log_warning(string $message, array $context = []): void { logger($message, 'warning', $context); }
function log_error(string $message, array $context = []): void   { logger($message, 'error', $context); }
function log_critical(string $message, array $context = []): void { logger($message, 'critical', $context); }

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
 * queue()                       → Queue instance
 * queue('JobClass', $data)      → push usando a rota/config do job
 * queue('JobClass', $data, 'q') → push para fila nomeada
 */
function queue(string $job = '', mixed $data = null, ?string $q = null): Queue
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

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
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

// ─────────────────────────────────────────────────────────────────────────────
// Markdown
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Convert Markdown to HTML.
 *
 *   markdown($text)                                    — sem botão de copiar
 *   markdown($text, copyable(['php', 'bash', 'js']))    — copiar só nessas linguagens
 */
function markdown(string $text, array $copyLangs = []): string
{
    return Markdown::parse($text, $copyLangs);
}

/**
 * Shorthand to declare which code-block languages get a copy button.
 *
 *   copyable(['php', 'bash', 'env'])
 */
function copyable(array $langs): array
{
    return $langs;
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF verification middleware helper
// ─────────────────────────────────────────────────────────────────────────────

function preventRequestForgery(): ?Response
{
    return new PreventRequestForgery(request(), session())->handle();
}

function verifyCsrf(): void
{
    $response = preventRequestForgery();
    if ($response instanceof Response) {
        $response->send();
        exit;
    }
}
