<?php

class SparkInspector
{
    private static ?SparkInspector $instance = null;

    private string $basePath;
    private string $prefix;
    private bool $enabled;
    private bool $internalRequest = false;
    private bool $persistCurrentRequest = false;
    private bool $finalized = false;
    private ?float $startedAt = null;
    private array $context = [];
    private SparkInspectorStorage $storage;

    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->prefix = rtrim($_ENV['SPARK_INSPECTOR_PREFIX'] ?? '/_spark', '/');
        $this->enabled = $this->resolveEnabled();
        $this->storage = new SparkInspectorStorage($this->basePath);
    }

    public static function boot(string $basePath): void
    {
        static::$instance = new static($basePath);
    }

    public static function getInstance(): ?static
    {
        return static::$instance;
    }

    public static function isEnabled(): bool
    {
        return static::$instance?->enabled ?? false;
    }

    public static function isInspectorPath(string $path): bool
    {
        $instance = static::$instance;
        if (!$instance) {
            return false;
        }

        return $path === $instance->prefix || str_starts_with($path, $instance->prefix . '/');
    }

    public static function startRequest(Request $request): void
    {
        static::$instance?->beginRequest($request);
    }

    public static function handleInternalRequest(Request $request): bool
    {
        return static::$instance?->dispatchInternal($request) ?? false;
    }

    public static function recordRoute(array $payload): void
    {
        static::$instance?->addRoute($payload);
    }

    public static function recordMiddleware(string $name, array $params = [], string $status = 'passed'): void
    {
        static::$instance?->addMiddleware($name, $params, $status);
    }

    public static function recordView(string $name, string $type, float $durationMs, bool $compiled, bool $cacheHit): void
    {
        static::$instance?->addView($name, $type, $durationMs, $compiled, $cacheHit);
    }

    public static function recordQuery(string $sql, array $bindings, float $durationMs, int $rowCount = 0): void
    {
        static::$instance?->addQuery($sql, $bindings, $durationMs, $rowCount);
    }

    public static function recordCache(string $operation, ?string $key, array $meta = []): void
    {
        static::$instance?->addCache($operation, $key, $meta);
    }

    public static function recordLog(string $level, string $message, array $context = []): void
    {
        static::$instance?->addLog($level, $message, $context);
    }

    public static function recordEvent(string $event, mixed $data = null): void
    {
        static::$instance?->addEvent($event, $data);
    }

    public static function recordMail(array $payload): void
    {
        static::$instance?->addMail($payload);
    }

    public static function recordQueue(array $payload): void
    {
        static::$instance?->addQueue($payload);
    }

    public static function recordAi(array $payload): void
    {
        static::$instance?->addAi($payload);
    }

    public static function inspect(mixed ...$values): void
    {
        static::$instance?->addDump('inspect', $values);
    }

    public static function recordDump(mixed ...$values): void
    {
        static::$instance?->addDump('dump', $values);
    }

    public static function measure(string $label, callable $callback): mixed
    {
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            static::$instance?->addTimeline($label, $durationMs, 'measure');
        }
    }

    public static function recordException(Throwable $e): void
    {
        static::$instance?->addException($e);
    }

    public static function decorateResponse(Response $response): void
    {
        static::$instance?->augmentResponse($response);
    }

    public static function finalizeResponse(Response $response): void
    {
        static::$instance?->completeResponse($response);
    }

    public static function terminateWithHtml(string $html, int $status = 500): never
    {
        $response = Response::html($html, $status);
        $response->send();
        exit;
    }

    public function status(): array
    {
        return [
            'enabled' => $this->enabled,
            'prefix' => $this->prefix,
            'storage' => $this->storage->status(),
        ];
    }

    public function clear(): int
    {
        return $this->storage->clear();
    }

    private function beginRequest(Request $request): void
    {
        $this->internalRequest = static::isInspectorPath($request->path());
        $this->persistCurrentRequest = $this->enabled && !$this->internalRequest;
        $this->finalized = false;

        if (!$this->enabled) {
            $this->context = [];
            return;
        }

        $this->startedAt = microtime(true);
        $this->context = [
            'id' => bin2hex(random_bytes(10)),
            'created_at' => date(DATE_ATOM),
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'url' => $request->url(),
                'headers' => $this->sanitize($request->headers()),
                'query' => $this->sanitize($request->query()),
                'input' => $this->sanitize($request->all()),
                'ip' => $request->ip(),
                'ajax' => $request->isAjax(),
                'accepts_json' => $request->acceptsJson(),
                'accepts_html' => $request->acceptsHtml(),
                'wants_json' => $request->wantsJson(),
                'preferred_format' => $request->preferredFormat(['html', 'json']),
            ],
            'response' => [
                'status' => null,
                'headers' => [],
                'content_type' => null,
                'body_length' => 0,
                'body_preview' => null,
            ],
            'route' => [
                'url' => null,
                'params' => [],
                'middlewares' => [],
            ],
            'timeline' => [],
            'views' => [],
            'queries' => [],
            'cache' => [],
            'logs' => [],
            'events' => [],
            'mail' => [],
            'queue' => [],
            'ai' => [],
            'dumps' => [],
            'exceptions' => [],
            'pipelines' => [],
            'bottlenecks' => [],
            'metrics' => [
                'db_ms' => 0.0,
                'view_ms' => 0.0,
                'total_ms' => 0.0,
                'cache_ops' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
                'cache_stale_hits' => 0,
                'cache_writes' => 0,
                'queue_ops' => 0,
                'queue_dispatches' => 0,
                'queue_sync_dispatches' => 0,
                'queue_enqueued' => 0,
                'queue_delayed' => 0,
                'queue_processed' => 0,
                'queue_released' => 0,
                'queue_failed' => 0,
                'queue_retries' => 0,
                'ai_ops' => 0,
                'ai_text_ops' => 0,
                'ai_embedding_ops' => 0,
                'ai_image_ops' => 0,
                'ai_audio_ops' => 0,
                'ai_agent_ops' => 0,
                'ai_retrieval_ops' => 0,
                'ai_tool_calls' => 0,
                'ai_ms' => 0.0,
                'ai_tokens_in' => 0,
                'ai_tokens_out' => 0,
                'ai_tokens_total' => 0,
                'ai_cost_usd' => 0.0,
                'memory_start_kb' => memory_get_usage(true) / 1024,
                'memory_end_kb' => 0.0,
                'memory_peak_kb' => 0.0,
            ],
            'metadata' => [
                'app_env' => $_ENV['APP_ENV'] ?? 'dev',
                'app_name' => $_ENV['APP_NAME'] ?? 'SparkPHP',
                'php' => PHP_VERSION,
                'inspector_prefix' => $this->prefix,
            ],
        ];
    }

    private function dispatchInternal(Request $request): bool
    {
        if (!$this->enabled || !$this->internalRequest) {
            return false;
        }

        $relativePath = trim(substr($request->path(), strlen($this->prefix)), '/');

        if ($relativePath === '') {
            Response::redirect($this->prefix . '/requests')->send();
            return true;
        }

        if ($request->method() === 'GET' && $relativePath === 'requests') {
            Response::html($this->renderRequestsPage())->send();
            return true;
        }

        if ($request->method() === 'GET' && preg_match('#^requests/([a-f0-9]+)$#', $relativePath, $matches)) {
            $entry = $this->storage->find($matches[1]);
            if ($entry === null) {
                Response::html($this->renderNotFoundPage(), 404)->send();
                return true;
            }

            Response::html($this->renderRequestPage($entry), 200)->send();
            return true;
        }

        if ($request->method() === 'GET' && $relativePath === 'api/requests') {
            Response::json(['requests' => $this->storage->all()])->send();
            return true;
        }

        if ($request->method() === 'GET' && preg_match('#^api/requests/([a-f0-9]+)$#', $relativePath, $matches)) {
            $entry = $this->storage->find($matches[1]);
            if ($entry === null) {
                Response::notFound()->send();
                return true;
            }

            Response::json($entry)->send();
            return true;
        }

        if ($request->method() === 'POST' && $relativePath === 'clear') {
            $cleared = $this->storage->clear();

            if ($request->wantsJson()) {
                Response::json(['cleared' => $cleared])->send();
                return true;
            }

            Response::redirect($this->prefix . '/requests')->send();
            return true;
        }

        return false;
    }

    private function addRoute(array $payload): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['route'] = array_merge($this->context['route'], [
            'url' => $payload['url'] ?? $this->context['route']['url'],
            'params' => $this->sanitize($payload['params'] ?? $this->context['route']['params']),
            'middlewares' => $payload['middlewares'] ?? $this->context['route']['middlewares'],
            'status' => $payload['status'] ?? null,
            'allowed' => $payload['allowed'] ?? [],
        ]);
    }

    private function addMiddleware(string $name, array $params, string $status): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        if (!in_array($name, $this->context['route']['middlewares'], true)) {
            $this->context['route']['middlewares'][] = $name;
        }

        $this->context['timeline'][] = [
            'label' => 'middleware:' . $name,
            'type' => 'middleware',
            'duration_ms' => 0.0,
            'status' => $status,
            'params' => $params,
        ];
    }

    private function addView(string $name, string $type, float $durationMs, bool $compiled, bool $cacheHit): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['views'][] = [
            'name' => $name,
            'type' => $type,
            'duration_ms' => round($durationMs, 3),
            'compiled' => $compiled,
            'cache_hit' => $cacheHit,
        ];

        $this->context['metrics']['view_ms'] += $durationMs;
        $this->addTimeline('view:' . $name, $durationMs, 'view');
    }

    private function addQuery(string $sql, array $bindings, float $durationMs, int $rowCount): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['queries'][] = [
            'sql' => $sql,
            'bindings' => $this->sanitize($bindings),
            'duration_ms' => round($durationMs, 3),
            'row_count' => $rowCount,
        ];

        $this->context['metrics']['db_ms'] += $durationMs;
        $this->addTimeline('query', $durationMs, 'db');
    }

    private function addCache(string $operation, ?string $key, array $meta): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $sanitizedMeta = $this->sanitize($meta);

        $this->context['cache'][] = [
            'operation' => $operation,
            'key' => $key,
            'meta' => $sanitizedMeta,
        ];

        $this->context['metrics']['cache_ops']++;

        if (($meta['hit'] ?? false) === true) {
            $this->context['metrics']['cache_hits']++;
        }

        if (($meta['miss'] ?? false) === true) {
            $this->context['metrics']['cache_misses']++;
        }

        if (($meta['stale'] ?? false) === true) {
            $this->context['metrics']['cache_stale_hits']++;
        }

        if (in_array($operation, ['set', 'touch', 'forget', 'flush', 'flush_tags', 'increment', 'decrement', 'refresh'], true)) {
            $this->context['metrics']['cache_writes']++;
        }

        $this->context['timeline'][] = [
            'label' => 'cache:' . $operation,
            'type' => 'cache',
            'duration_ms' => 0.0,
            'status' => ($meta['hit'] ?? false) === true
                ? (($meta['stale'] ?? false) === true ? 'stale' : 'hit')
                : (($meta['miss'] ?? false) === true ? 'miss' : 'ok'),
            'meta' => $sanitizedMeta,
            'key' => $key,
        ];
    }

    private function addLog(string $level, string $message, array $context): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['logs'][] = [
            'level' => $level,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];
    }

    private function addEvent(string $event, mixed $data): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['events'][] = [
            'event' => $event,
            'data' => $this->sanitize($data),
        ];
    }

    private function addMail(array $payload): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['mail'][] = $this->sanitize($payload);
    }

    private function addQueue(array $payload): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $sanitizedPayload = $this->sanitize($payload);
        $type = (string) ($payload['type'] ?? 'unknown');

        $this->context['queue'][] = $sanitizedPayload;
        $this->context['metrics']['queue_ops']++;

        if (in_array($type, ['dispatch', 'dispatch-sync'], true)) {
            $this->context['metrics']['queue_dispatches']++;
        }

        if ($type === 'dispatch-sync') {
            $this->context['metrics']['queue_sync_dispatches']++;
        }

        if (in_array($type, ['push', 'later'], true)) {
            $this->context['metrics']['queue_enqueued']++;
        }

        if ($type === 'later') {
            $this->context['metrics']['queue_delayed']++;
        }

        if ($type === 'processed') {
            $this->context['metrics']['queue_processed']++;
        }

        if ($type === 'released') {
            $this->context['metrics']['queue_released']++;
        }

        if ($type === 'failed') {
            $this->context['metrics']['queue_failed']++;
        }

        if ($type === 'retry') {
            $this->context['metrics']['queue_retries']++;
        }

        $this->context['timeline'][] = [
            'label' => 'queue:' . $type,
            'type' => 'queue',
            'duration_ms' => 0.0,
            'status' => match ($type) {
                'failed' => 'failed',
                'released' => 'released',
                'retry' => 'retry',
                'later' => 'delayed',
                default => 'ok',
            },
            'meta' => $sanitizedPayload,
        ];
    }

    private function addDump(string $type, array $values): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $formatted = array_map([$this, 'stringify'], $values);

        $this->context['dumps'][] = [
            'type' => $type,
            'values' => $formatted,
        ];
    }

    private function addException(Throwable $e): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['exceptions'][] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ];
    }

    private function addTimeline(string $label, float $durationMs, string $type): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->context['timeline'][] = [
            'label' => $label,
            'type' => $type,
            'duration_ms' => round($durationMs, 3),
        ];
    }

    private function augmentResponse(Response $response): void
    {
        if (!$this->enabled || $this->context === []) {
            return;
        }

        $this->snapshotRuntimeMetrics();
        $response->header('X-Spark-Request-Id', $this->context['id']);
        $response->header('X-Spark-Inspector-Url', $this->inspectorUrl($this->context['id']));
        $response->header('Server-Timing', $this->serverTiming());

        if ($this->internalRequest) {
            return;
        }

        if (!$response->isHtmlInspectable()) {
            return;
        }

        $body = (string) $response->getBody();
        $toolbar = $this->renderToolbar();

        if (stripos($body, '</body>') !== false) {
            $body = preg_replace('/<\/body>/i', $toolbar . '</body>', $body, 1) ?? ($body . $toolbar);
        } else {
            $body .= $toolbar;
        }

        $response->setBody($body);
    }

    private function completeResponse(Response $response): void
    {
        if (!$this->enabled || $this->context === [] || $this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->snapshotRuntimeMetrics();
        $body = $response->getBody();
        $preview = null;
        $bodyLength = 0;

        if (is_string($body)) {
            $bodyLength = strlen($body);
            $preview = substr($body, 0, 5000);
        }

        $this->context['response'] = [
            'status' => $response->getStatus(),
            'headers' => $this->sanitize($response->getHeaders()),
            'content_type' => $response->contentType(),
            'body_length' => $bodyLength,
            'body_preview' => $preview,
        ];
        $this->refreshInsights();

        if ($this->persistCurrentRequest) {
            $this->storage->save($this->context);
        }
    }

    private function resolveEnabled(): bool
    {
        $setting = strtolower((string) ($_ENV['SPARK_INSPECTOR'] ?? 'auto'));
        $isDev = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';

        if (!$isDev) {
            return false;
        }

        return match ($setting) {
            'false', '0', 'off' => false,
            'true', '1', 'on' => true,
            default => true,
        };
    }

    private function renderToolbar(): string
    {
        $id = htmlspecialchars($this->context['id']);
        $totalMs = number_format((float) ($this->context['metrics']['total_ms'] ?? 0.0), 1);
        $memoryKb = number_format((float) ($this->context['metrics']['memory_peak_kb'] ?? 0.0), 0);
        $queries = count($this->context['queries'] ?? []);
        $exceptions = count($this->context['exceptions'] ?? []);
        $queueFailures = (int) ($this->context['metrics']['queue_failed'] ?? 0);
        $queueOps = (int) ($this->context['metrics']['queue_ops'] ?? 0);
        $cacheHitRate = $this->cacheHitRate($this->context['metrics'] ?? []);
        $summaryParts = [
            $totalMs . ' ms',
            $queries . ' queries',
            $cacheHitRate . ' cache hit',
        ];

        if ($queueFailures > 0) {
            $summaryParts[] = $queueFailures . ' queue failures';
        } elseif ($queueOps > 0) {
            $summaryParts[] = $queueOps . ' queue ops';
        }

        $summary = htmlspecialchars(implode(' • ', $summaryParts));
        $href = htmlspecialchars($this->inspectorUrl($this->context['id']));

        return <<<HTML
<div id="spark-inspector-toolbar" data-state="expanded" style="position:fixed;right:16px;bottom:16px;z-index:99999;color:#f9fafb;font:13px/1.4 system-ui,sans-serif;">
  <style>
    #spark-inspector-toolbar{max-width:min(420px,calc(100vw - 24px));}
    #spark-inspector-toolbar *{box-sizing:border-box;}
    #spark-inspector-toolbar .spark-inspector-shell{background:#111827;border:1px solid #374151;border-radius:16px;box-shadow:0 20px 45px rgba(0,0,0,.35);overflow:hidden;backdrop-filter:blur(12px);}
    #spark-inspector-toolbar .spark-inspector-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;cursor:grab;user-select:none;}
    #spark-inspector-toolbar .spark-inspector-header:active{cursor:grabbing;}
    #spark-inspector-toolbar .spark-inspector-title{display:flex;align-items:center;gap:10px;min-width:0;}
    #spark-inspector-toolbar .spark-inspector-badge{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#f59e0b;color:#111827;font-size:14px;font-weight:800;flex:0 0 auto;}
    #spark-inspector-toolbar .spark-inspector-badge-toggle{border:0;cursor:pointer;padding:0;transition:transform .15s ease,filter .15s ease;}
    #spark-inspector-toolbar .spark-inspector-badge-toggle:hover{filter:brightness(1.05);transform:scale(1.04);}
    #spark-inspector-toolbar .spark-inspector-name{color:#f59e0b;font-weight:700;}
    #spark-inspector-toolbar .spark-inspector-summary{color:#d1d5db;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    #spark-inspector-toolbar .spark-inspector-controls{display:flex;align-items:center;gap:8px;flex:0 0 auto;}
    #spark-inspector-toolbar .spark-inspector-button{border:0;background:#1f2937;color:#f9fafb;border-radius:999px;padding:7px 10px;cursor:pointer;font:inherit;}
    #spark-inspector-toolbar .spark-inspector-button:hover{background:#374151;}
    #spark-inspector-toolbar .spark-inspector-open{display:inline-flex;align-items:center;justify-content:center;background:#f59e0b;color:#111827;text-decoration:none;padding:8px 14px;border-radius:999px;font-weight:700;}
    #spark-inspector-toolbar .spark-inspector-open:hover{background:#fbbf24;}
    #spark-inspector-toolbar .spark-inspector-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:0 12px 12px;}
    #spark-inspector-toolbar .spark-inspector-metric{background:#1f2937;border:1px solid #374151;border-radius:12px;padding:8px 10px;}
    #spark-inspector-toolbar .spark-inspector-metric strong{display:block;color:#f9fafb;font-size:12px;}
    #spark-inspector-toolbar .spark-inspector-metric span{display:block;color:#9ca3af;font-size:11px;margin-top:2px;}
    #spark-inspector-toolbar .spark-inspector-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 12px 12px;}
    #spark-inspector-toolbar .spark-inspector-id{color:#9ca3af;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    #spark-inspector-toolbar .spark-inspector-minimized{display:none;padding:0;}
    #spark-inspector-toolbar .spark-inspector-minimized-button{display:flex;align-items:center;justify-content:center;width:44px;height:44px;border:0;border-radius:14px;background:#1f2937;cursor:pointer;padding:0;box-shadow:0 8px 18px rgba(0,0,0,.24),inset 0 0 0 1px #374151;}
    #spark-inspector-toolbar .spark-inspector-minimized-button:hover{background:#263244;}
    #spark-inspector-toolbar .spark-inspector-minimized-button .spark-inspector-badge{width:28px;height:28px;font-size:15px;}
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-body,
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-footer,
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-name{display:none;}
    #spark-inspector-toolbar[data-state="compact"]{max-width:min(240px,calc(100vw - 24px));}
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-header{padding:8px 10px;}
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-summary{max-width:110px;}
    #spark-inspector-toolbar[data-state="minimized"]{max-width:44px;}
    #spark-inspector-toolbar[data-state="minimized"] .spark-inspector-shell{background:transparent;border:0;border-radius:0;box-shadow:none;backdrop-filter:none;overflow:visible;}
    #spark-inspector-toolbar[data-state="minimized"] .spark-inspector-header,
    #spark-inspector-toolbar[data-state="minimized"] .spark-inspector-body,
    #spark-inspector-toolbar[data-state="minimized"] .spark-inspector-footer{display:none;}
    #spark-inspector-toolbar[data-state="minimized"] .spark-inspector-minimized{display:flex;}
    @media (max-width: 640px){
      #spark-inspector-toolbar{left:12px;right:12px;bottom:12px;max-width:none !important;}
      #spark-inspector-toolbar .spark-inspector-body{grid-template-columns:1fr 1fr;}
      #spark-inspector-toolbar .spark-inspector-footer{flex-direction:column;align-items:stretch;}
      #spark-inspector-toolbar .spark-inspector-open{text-align:center;}
    }
  </style>
  <div class="spark-inspector-shell">
    <div class="spark-inspector-header" id="spark-inspector-handle" title="Drag to move">
      <div class="spark-inspector-title">
        <button type="button" id="spark-inspector-badge-toggle" class="spark-inspector-badge spark-inspector-badge-toggle" aria-label="Minimize toolbar">S</button>
        <div style="min-width:0;">
          <strong class="spark-inspector-name">Spark Inspector</strong>
          <div class="spark-inspector-summary">{$summary}</div>
        </div>
      </div>
      <div class="spark-inspector-controls">
        <button type="button" id="spark-inspector-toggle" class="spark-inspector-button" aria-label="Toggle compact mode">Compact</button>
        <a href="{$href}" class="spark-inspector-open">Open</a>
      </div>
    </div>
    <div class="spark-inspector-body">
      <div class="spark-inspector-metric"><strong>Total</strong><span>{$totalMs} ms</span></div>
      <div class="spark-inspector-metric"><strong>Memory</strong><span>{$memoryKb} KB</span></div>
      <div class="spark-inspector-metric"><strong>Queries</strong><span>{$queries}</span></div>
      <div class="spark-inspector-metric"><strong>Exceptions</strong><span>{$exceptions}</span></div>
      <div class="spark-inspector-metric"><strong>Cache Hit</strong><span>{$cacheHitRate}</span></div>
      <div class="spark-inspector-metric"><strong>Queue</strong><span>{$queueOps} ops</span></div>
    </div>
    <div class="spark-inspector-footer">
      <span class="spark-inspector-id">{$id}</span>
      <span style="color:#6b7280;font-size:11px;">Drag the header to float this widget.</span>
    </div>
    <div class="spark-inspector-minimized" id="spark-inspector-minimized">
      <button type="button" id="spark-inspector-badge-restore" class="spark-inspector-minimized-button" aria-label="Restore toolbar">
        <span class="spark-inspector-badge">S</span>
      </button>
    </div>
  </div>
</div>
<script>
(function () {
  var toolbar = document.getElementById('spark-inspector-toolbar');
  if (!toolbar || toolbar.dataset.ready === 'true') {
    return;
  }

  toolbar.dataset.ready = 'true';

  var toggle = document.getElementById('spark-inspector-toggle');
  var badgeToggle = document.getElementById('spark-inspector-badge-toggle');
  var badgeRestore = document.getElementById('spark-inspector-badge-restore');
  var handle = document.getElementById('spark-inspector-handle');
  var storageKey = 'spark-inspector-toolbar';
  var defaultState = window.innerWidth <= 900 ? 'compact' : 'expanded';

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function applyState(state) {
    if (state !== 'compact' && state !== 'minimized') {
      state = 'expanded';
    }

    toolbar.dataset.state = state;

    if (toggle) {
      toggle.textContent = toolbar.dataset.state === 'compact' ? 'Expand' : 'Compact';
    }
  }

  function applyPosition(position) {
    if (!position || typeof position.left !== 'number' || typeof position.top !== 'number') {
      toolbar.style.left = '';
      toolbar.style.top = '';
      toolbar.style.right = '16px';
      toolbar.style.bottom = '16px';
      return;
    }

    var maxLeft = Math.max(12, window.innerWidth - toolbar.offsetWidth - 12);
    var maxTop = Math.max(12, window.innerHeight - toolbar.offsetHeight - 12);

    toolbar.style.left = clamp(position.left, 12, maxLeft) + 'px';
    toolbar.style.top = clamp(position.top, 12, maxTop) + 'px';
    toolbar.style.right = 'auto';
    toolbar.style.bottom = 'auto';
  }

  function readPrefs() {
    try {
      return JSON.parse(localStorage.getItem(storageKey) || '{}');
    } catch (error) {
      return {};
    }
  }

  function savePrefs(prefs) {
    try {
      localStorage.setItem(storageKey, JSON.stringify(prefs));
    } catch (error) {
    }
  }

  var prefs = readPrefs();
  applyState(prefs.state || defaultState);
  requestAnimationFrame(function () {
    applyPosition(prefs.position || null);
  });

  if (toggle) {
    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      prefs.state = toolbar.dataset.state === 'compact' ? 'expanded' : 'compact';
      applyState(prefs.state);
      savePrefs(prefs);
      requestAnimationFrame(function () {
        applyPosition(prefs.position || null);
      });
    });
  }

  if (badgeToggle) {
    badgeToggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      prefs.state = 'minimized';
      applyState(prefs.state);
      savePrefs(prefs);
      requestAnimationFrame(function () {
        applyPosition(prefs.position || null);
      });
    });
  }

  if (badgeRestore) {
    badgeRestore.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      prefs.state = 'expanded';
      applyState(prefs.state);
      savePrefs(prefs);
      requestAnimationFrame(function () {
        applyPosition(prefs.position || null);
      });
    });
  }

  if (!handle) {
    return;
  }

  var drag = null;

  handle.addEventListener('pointerdown', function (event) {
    if (event.target && (event.target.closest('button') || event.target.closest('a'))) {
      return;
    }

    var rect = toolbar.getBoundingClientRect();
    drag = {
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top
    };

    handle.setPointerCapture(event.pointerId);
  });

  handle.addEventListener('pointermove', function (event) {
    if (!drag) {
      return;
    }

    prefs.position = {
      left: event.clientX - drag.offsetX,
      top: event.clientY - drag.offsetY
    };

    applyPosition(prefs.position);
  });

  function stopDrag(event) {
    if (!drag) {
      return;
    }

    drag = null;
    savePrefs(prefs);

    if (event && typeof handle.releasePointerCapture === 'function') {
      try {
        handle.releasePointerCapture(event.pointerId);
      } catch (error) {
      }
    }
  }

  handle.addEventListener('pointerup', stopDrag);
  handle.addEventListener('pointercancel', stopDrag);

  window.addEventListener('resize', function () {
    applyPosition(prefs.position || null);
  });
})();
</script>
HTML;
    }

    private function renderRequestsPage(): string
    {
        $rows = [];
        $slowMs = (float) ($_ENV['SPARK_INSPECTOR_SLOW_MS'] ?? 250);

        foreach ($this->storage->all() as $item) {
            $status = (int) $item['status'];
            $statusColor = $status >= 500 ? '#dc2626' : ($status >= 400 ? '#d97706' : '#059669');
            $statusBg = $status >= 500 ? '#fef2f2' : ($status >= 400 ? '#fffbeb' : '#ecfdf5');
            $slow = (float) $item['duration_ms'] >= $slowMs;
            $methodBadge = in_array($item['method'], ['POST', 'PUT', 'PATCH', 'DELETE']) ? '#7c3aed' : '#2563eb';
            $duration = (float) $item['duration_ms'];
            $durationColor = $slow ? '#dc2626' : ($duration > 100 ? '#d97706' : '#6b7280');
            $timeAgo = $this->timeAgo($item['created_at']);

            $rows[] = sprintf(
                '<tr class="req-row" onclick="window.location.href=\'%s/requests/%s\'" style="cursor:pointer;">' .
                '<td><span class="method-badge" style="background:%s;">%s</span></td>' .
                '<td class="path-cell"><code>%s</code></td>' .
                '<td><span class="status-badge" style="background:%s;color:%s;">%s</span></td>' .
                '<td><span style="color:%s;font-weight:600;">%.1f ms</span></td>' .
                '<td class="dim">%s</td>' .
                '<td class="dim">%s</td>' .
                '<td class="dim"><span title="%s">%s</span></td>' .
                '</tr>',
                htmlspecialchars($this->prefix),
                htmlspecialchars($item['id']),
                $methodBadge,
                htmlspecialchars($item['method']),
                htmlspecialchars($item['path']),
                $statusBg,
                $statusColor,
                htmlspecialchars((string) $item['status']),
                $durationColor,
                $duration,
                number_format((float) $item['memory_peak_kb'], 0) . ' KB',
                htmlspecialchars((string) $item['query_count']),
                htmlspecialchars($item['created_at']),
                htmlspecialchars($timeAgo)
            );
        }

        $count = count($rows);
        $tableRows = $rows === [] ? '<tr><td colspan="7" style="text-align:center;padding:48px 12px;color:#9ca3af;">No requests captured yet.</td></tr>' : implode('', $rows);

        $content = <<<HTML
<div class="page-header">
  <div class="header-left">
    <div class="logo-row">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#2563eb" stroke-width="2"/><path d="M12 6v6l4 2" stroke="#2563eb" stroke-width="2" stroke-linecap="round"/></svg>
      <h1>Spark Inspector</h1>
    </div>
    <p class="subtitle">Captured <strong>{$count}</strong> recent requests</p>
  </div>
  <form method="POST" action="{$this->prefix}/clear">
    <button type="submit" class="btn-clear">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
      Clear
    </button>
  </form>
</div>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th style="width:80px;">Method</th>
        <th>Path</th>
        <th style="width:80px;">Status</th>
        <th style="width:100px;">Duration</th>
        <th style="width:100px;">Memory</th>
        <th style="width:70px;">Queries</th>
        <th style="width:110px;">When</th>
      </tr>
    </thead>
    <tbody>{$tableRows}</tbody>
  </table>
</div>
HTML;

        return $this->renderShell('Spark Inspector', $content);
    }

    private function renderRequestPage(array $entry): string
    {
        $tabIcons = [
            'overview' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
            'pipelines' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 7h14"/><path d="M5 12h8"/><path d="M5 17h14"/><circle cx="18" cy="12" r="2"/></svg>',
            'timeline' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'request' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'response' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
            'route' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M6 9v2a3 3 0 0 0 3 3h6a3 3 0 0 0 3-3V6"/><circle cx="18" cy="6" r="3"/></svg>',
            'middleware' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
            'views' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
            'queries' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
            'cache' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'logs' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'events' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
            'mail' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            'queue' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/></svg>',
            'dumps' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'exceptions' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        ];

        $tabs = [
            'overview' => $this->renderOverviewTab($entry),
            'pipelines' => $this->renderPipelinesTab($entry),
            'timeline' => $this->renderCollectionTab('Timeline', $entry['timeline'] ?? []),
            'request' => $this->renderPrettyTab('Request', $entry['request'] ?? []),
            'response' => $this->renderPrettyTab('Response', $entry['response'] ?? []),
            'route' => $this->renderPrettyTab('Route', $entry['route'] ?? []),
            'middleware' => $this->renderCollectionTab('Middleware', $entry['route']['middlewares'] ?? []),
            'views' => $this->renderCollectionTab('Views', $entry['views'] ?? []),
            'queries' => $this->renderCollectionTab('Queries', $entry['queries'] ?? []),
            'cache' => $this->renderCollectionTab('Cache', $entry['cache'] ?? []),
            'logs' => $this->renderCollectionTab('Logs', $entry['logs'] ?? []),
            'events' => $this->renderCollectionTab('Events', $entry['events'] ?? []),
            'mail' => $this->renderCollectionTab('Mail', $entry['mail'] ?? []),
            'queue' => $this->renderCollectionTab('Queue', $entry['queue'] ?? []),
            'dumps' => $this->renderCollectionTab('Dumps', $entry['dumps'] ?? []),
            'exceptions' => $this->renderCollectionTab('Exceptions', $entry['exceptions'] ?? []),
        ];

        $nav = [];
        $panels = [];
        $first = true;

        foreach ($tabs as $name => $panel) {
            $label = ucfirst($name);
            $icon = $tabIcons[$name] ?? '';
            $activeClass = $first ? ' active' : '';
            $nav[] = sprintf(
                '<button type="button" class="tab-btn%s" data-tab="%s">%s<span>%s</span></button>',
                $activeClass,
                htmlspecialchars($name),
                $icon,
                htmlspecialchars($label)
            );

            $panels[] = sprintf(
                '<section data-panel="%s" style="display:%s;">%s</section>',
                htmlspecialchars($name),
                $first ? 'block' : 'none',
                $panel
            );
            $first = false;
        }

        $status = (int) ($entry['response']['status'] ?? 0);
        $statusColor = $status >= 500 ? '#dc2626' : ($status >= 400 ? '#d97706' : '#059669');
        $statusBg = $status >= 500 ? '#fef2f2' : ($status >= 400 ? '#fffbeb' : '#ecfdf5');
        $method = htmlspecialchars($entry['request']['method'] ?? '');
        $path = htmlspecialchars($entry['request']['path'] ?? '');
        $duration = number_format((float) ($entry['metrics']['total_ms'] ?? 0), 1);
        $id = htmlspecialchars($entry['id']);
        $shortId = substr($id, 0, 8) . '...';

        $content = <<<HTML
<div class="detail-header">
  <a href="{$this->prefix}/requests" class="back-link">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
    All Requests
  </a>
  <div class="detail-title-row">
    <div class="detail-title-left">
      <span class="method-badge" style="background:#2563eb;font-size:13px;">{$method}</span>
      <h1>{$path}</h1>
    </div>
    <div class="detail-meta">
      <span class="status-badge" style="background:{$statusBg};color:{$statusColor};font-size:13px;">{$status}</span>
      <span class="meta-sep"></span>
      <span class="meta-item">{$duration} ms</span>
      <span class="meta-sep"></span>
      <span class="meta-item mono" title="{$id}">{$shortId}</span>
    </div>
  </div>
</div>
<div class="tab-bar">%s</div>
<div class="tab-content">%s</div>
<script>
document.querySelectorAll('.tab-btn').forEach(function(button) {
  button.addEventListener('click', function() {
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('[data-panel]').forEach(function(p) { p.style.display = 'none'; });
    button.classList.add('active');
    document.querySelector('[data-panel="' + button.dataset.tab + '"]').style.display = 'block';
  });
});
</script>
HTML;

        return $this->renderShell('Spark Inspector Request', sprintf($content, implode('', $nav), implode('', $panels)));
    }

    private function renderOverviewTab(array $entry): string
    {
        $metrics = $entry['metrics'] ?? [];
        $queries = count($entry['queries'] ?? []);
        $exceptions = count($entry['exceptions'] ?? []);
        $views = count($entry['views'] ?? []);
        $cacheOps = (int) ($metrics['cache_ops'] ?? 0);
        $cacheHits = (int) ($metrics['cache_hits'] ?? 0);
        $cacheMisses = (int) ($metrics['cache_misses'] ?? 0);
        $cacheStaleHits = (int) ($metrics['cache_stale_hits'] ?? 0);
        $cacheHitRate = $this->cacheHitRate($metrics);
        $queueOps = (int) ($metrics['queue_ops'] ?? 0);
        $queueFailed = (int) ($metrics['queue_failed'] ?? 0);
        $queueRetries = (int) ($metrics['queue_retries'] ?? 0);

        $status = (int) ($entry['response']['status'] ?? 0);
        $statusColor = $status >= 500 ? '#dc2626' : ($status >= 400 ? '#d97706' : '#059669');
        $exColor = $exceptions > 0 ? '#dc2626' : '#111827';
        $bottlenecks = $entry['bottlenecks'] ?? [];
        $bottlenecksCard = $bottlenecks === []
            ? '<div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg><p>No bottlenecks detected</p></div>'
            : '<pre>' . $this->pretty($bottlenecks) . '</pre>';

        return <<<HTML
<div class="metric-grid">
  {$this->metricCard('Status', (string) ($entry['response']['status'] ?? 'n/a'), $statusColor, 'status')}
  {$this->metricCard('Total Time', number_format((float) ($metrics['total_ms'] ?? 0), 1) . ' ms', '#2563eb', 'time')}
  {$this->metricCard('DB Time', number_format((float) ($metrics['db_ms'] ?? 0), 1) . ' ms', '#7c3aed', 'db')}
  {$this->metricCard('Views', (string) $views, '#0891b2', 'views')}
  {$this->metricCard('Queries', (string) $queries, '#7c3aed', 'queries')}
  {$this->metricCard('Cache Ops', (string) $cacheOps, '#d97706', 'cache')}
  {$this->metricCard('Cache Hit Rate', $cacheHitRate, '#d97706', 'cache')}
  {$this->metricCard('Stale Hits', (string) $cacheStaleHits, '#ea580c', 'cache')}
  {$this->metricCard('Queue Ops', (string) $queueOps, '#2563eb', 'queue')}
  {$this->metricCard('Queue Failures', (string) $queueFailed, $queueFailed > 0 ? '#dc2626' : '#2563eb', 'queue')}
  {$this->metricCard('Queue Retries', (string) $queueRetries, $queueRetries > 0 ? '#d97706' : '#2563eb', 'queue')}
  {$this->metricCard('Exceptions', (string) $exceptions, $exColor, 'exceptions')}
  {$this->metricCard('Memory Peak', number_format((float) ($metrics['memory_peak_kb'] ?? 0), 0) . ' KB', '#059669', 'memory')}
</div>
<div class="card" style="margin-top:16px;">
  <h3 class="card-title">Route Information</h3>
  <pre>{$this->pretty($entry['route'] ?? [])}</pre>
</div>
<div class="card" style="margin-top:16px;">
  <h3 class="card-title">Bottlenecks</h3>
  {$bottlenecksCard}
</div>
HTML;
    }

    private function renderPipelinesTab(array $entry): string
    {
        $pipelines = $entry['pipelines'] ?? [];
        $requestPipeline = $pipelines['request'] ?? ['summary' => [], 'steps' => []];
        $cachePipeline = $pipelines['cache'] ?? ['summary' => [], 'hot_keys' => []];
        $queuePipeline = $pipelines['queue'] ?? ['summary' => [], 'jobs' => []];

        $cacheHighlights = ($cachePipeline['hot_keys'] ?? []) === []
            ? '<div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg><p>No cache hotspots captured</p></div>'
            : '<pre>' . $this->pretty($cachePipeline['hot_keys']) . '</pre>';

        $queueHighlights = ($queuePipeline['jobs'] ?? []) === []
            ? '<div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg><p>No queue jobs captured</p></div>'
            : '<pre>' . $this->pretty($queuePipeline['jobs']) . '</pre>';

        return <<<HTML
<div class="stack-grid">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Request Pipeline</h3>
      <span class="badge">{$this->countPipelineSteps($requestPipeline['steps'] ?? [])} steps</span>
    </div>
    {$this->renderSummaryGrid($requestPipeline['summary'] ?? [])}
    {$this->renderPipelineSteps($requestPipeline['steps'] ?? [])}
  </div>
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Cache Pipeline</h3>
      <span class="badge">{$this->countPipelineItems($cachePipeline['summary'] ?? [])} metrics</span>
    </div>
    {$this->renderSummaryGrid($cachePipeline['summary'] ?? [])}
    <div class="section-split">
      <h4 class="section-heading">Hot Keys</h4>
      {$cacheHighlights}
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Queue Pipeline</h3>
      <span class="badge">{$this->countPipelineItems($queuePipeline['jobs'] ?? [])} jobs</span>
    </div>
    {$this->renderSummaryGrid($queuePipeline['summary'] ?? [])}
    <div class="section-split">
      <h4 class="section-heading">Jobs, failures and retries</h4>
      {$queueHighlights}
    </div>
  </div>
</div>
HTML;
    }

    private function renderCollectionTab(string $title, array $items): string
    {
        $count = count($items);
        $content = $items === []
            ? '<div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg><p>No data captured</p></div>'
            : '<pre>' . $this->pretty($items) . '</pre>';

        return <<<HTML
<div class="card">
  <div class="card-header">
    <h3 class="card-title">{$title}</h3>
    <span class="badge">{$count} items</span>
  </div>
  {$content}
</div>
HTML;
    }

    private function renderPrettyTab(string $title, array $payload): string
    {
        return $this->renderCollectionTab($title, $payload);
    }

    private function renderNotFoundPage(): string
    {
        return $this->renderShell('Spark Inspector', '<h1>Request not found</h1>');
    }

    private function renderShell(string $title, string $content): string
    {
        $title = htmlspecialchars($title);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin:0; background:#f8fafc; color:#111827; font:14px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif; }
    main { max-width:1280px; margin:0 auto; padding:28px 24px; }
    a { color:#2563eb; text-decoration:none; }
    a:hover { text-decoration:underline; }
    pre { margin:0; font:12px/1.6 ui-monospace,SFMono-Regular,'SF Mono',Menlo,monospace; background:#f8fafc; padding:16px; border-radius:10px; overflow:auto; white-space:pre-wrap; border:1px solid #e5e7eb; }

    /* Page header */
    .page-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:24px; }
    .header-left { display:flex; flex-direction:column; gap:4px; }
    .logo-row { display:flex; align-items:center; gap:10px; }
    .logo-row h1 { margin:0; font-size:22px; font-weight:700; color:#0f172a; }
    .subtitle { margin:0; color:#64748b; font-size:13px; }
    .subtitle strong { color:#334155; }
    .btn-clear { display:inline-flex; align-items:center; gap:6px; background:#fff; color:#64748b; border:1px solid #e2e8f0; border-radius:8px; padding:8px 14px; font-size:13px; cursor:pointer; font-weight:500; transition:all .15s; }
    .btn-clear:hover { background:#fef2f2; color:#dc2626; border-color:#fecaca; }

    /* Table */
    .table-wrap { background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
    table { width:100%; border-collapse:collapse; }
    thead { background:#f8fafc; }
    thead th { text-align:left; padding:10px 14px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#64748b; border-bottom:1px solid #e2e8f0; }
    tbody tr { transition:background .1s; }
    tbody tr:hover, .req-row:hover { background:#f1f5f9 !important; }
    tbody td { padding:10px 14px; border-bottom:1px solid #f1f5f9; font-size:13px; white-space:nowrap; }
    tbody tr:last-child td { border-bottom:none; }
    .path-cell code { font:12px/1.4 ui-monospace,SFMono-Regular,monospace; color:#334155; background:#f1f5f9; padding:2px 6px; border-radius:4px; }
    .dim { color:#64748b; }

    /* Badges */
    .method-badge { display:inline-block; padding:2px 8px; border-radius:4px; color:#fff; font-size:11px; font-weight:700; letter-spacing:.03em; font-family:ui-monospace,SFMono-Regular,monospace; }
    .status-badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:700; font-family:ui-monospace,SFMono-Regular,monospace; }

    /* Detail page */
    .detail-header { margin-bottom:24px; }
    .back-link { display:inline-flex; align-items:center; gap:4px; color:#64748b; font-size:13px; font-weight:500; margin-bottom:12px; }
    .back-link:hover { color:#2563eb; text-decoration:none; }
    .detail-title-row { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
    .detail-title-left { display:flex; align-items:center; gap:10px; }
    .detail-title-left h1 { margin:0; font-size:22px; font-weight:700; font-family:ui-monospace,SFMono-Regular,monospace; color:#0f172a; }
    .detail-meta { display:flex; align-items:center; gap:10px; }
    .meta-sep { width:1px; height:16px; background:#e2e8f0; }
    .meta-item { color:#64748b; font-size:13px; font-weight:500; }
    .mono { font-family:ui-monospace,SFMono-Regular,monospace; font-size:12px; }

    /* Tabs */
    .tab-bar { display:flex; gap:2px; flex-wrap:wrap; background:#f1f5f9; padding:4px; border-radius:10px; margin-bottom:20px; }
    .tab-btn { display:inline-flex; align-items:center; gap:5px; border:0; background:transparent; color:#64748b; padding:7px 12px; border-radius:7px; cursor:pointer; font-size:12.5px; font-weight:500; transition:all .15s; font-family:inherit; }
    .tab-btn:hover { color:#334155; background:#e2e8f0; }
    .tab-btn.active { background:#fff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .tab-btn svg { opacity:.6; flex-shrink:0; }
    .tab-btn.active svg { opacity:1; }

    /* Cards */
    .card { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
    .card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .card-title { margin:0; font-size:15px; font-weight:600; color:#0f172a; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#f1f5f9; color:#64748b; }
    .stack-grid { display:grid; gap:16px; }
    .summary-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:16px; }
    .summary-item { border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#f8fafc; }
    .summary-item strong { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:6px; }
    .summary-item span { color:#0f172a; font-size:14px; font-weight:600; word-break:break-word; }
    .pipeline-list { display:grid; gap:10px; }
    .pipeline-step { border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#fff; }
    .pipeline-step__top { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .pipeline-step__stage { display:inline-flex; align-items:center; gap:8px; font-size:12px; font-weight:700; color:#2563eb; text-transform:uppercase; letter-spacing:.04em; }
    .pipeline-step__label { color:#0f172a; font-weight:600; }
    .pipeline-step__meta { margin-top:6px; color:#64748b; font-size:12px; }
    .section-split { margin-top:16px; }
    .section-heading { margin:0 0 10px; font-size:13px; font-weight:700; color:#334155; }

    /* Metric grid */
    .metric-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; }
    .metric-card { position:relative; background:#fff; border-radius:10px; border:1px solid #e2e8f0; padding:16px 16px 16px 20px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .metric-accent { position:absolute; left:0; top:0; bottom:0; width:4px; border-radius:4px 0 0 4px; }
    .metric-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:6px; }
    .metric-value { font-size:22px; font-weight:700; line-height:1.2; }

    /* Empty state */
    .empty-state { display:flex; flex-direction:column; align-items:center; gap:8px; padding:40px 20px; color:#94a3b8; }
    .empty-state p { margin:0; font-size:13px; }

    /* Responsive */
    @media (max-width: 768px) {
      main { padding:16px 12px; }
      .page-header { flex-direction:column; align-items:flex-start; }
      .detail-title-row { flex-direction:column; align-items:flex-start; }
      .detail-meta { flex-wrap:wrap; }
      .metric-grid { grid-template-columns:repeat(2, 1fr); }
      .tab-bar { gap:1px; }
      .tab-btn span { display:none; }
      .tab-btn { padding:8px; }
    }
  </style>
</head>
<body>
  <main>{$content}</main>
</body>
</html>
HTML;
    }

    private function metricCard(string $label, string $value, string $accentColor = '#111827', string $type = ''): string
    {
        $label = htmlspecialchars($label);
        $value = htmlspecialchars($value);

        return <<<HTML
<div class="metric-card">
  <div class="metric-accent" style="background:{$accentColor};"></div>
  <div class="metric-label">{$label}</div>
  <div class="metric-value" style="color:{$accentColor};">{$value}</div>
</div>
HTML;
    }

    private function timeAgo(string $datetime): string
    {
        $now = time();
        $time = strtotime($datetime);
        if ($time === false) {
            return $datetime;
        }
        $diff = $now - $time;
        if ($diff < 5) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }
        return floor($diff / 86400) . 'd ago';
    }

    private function serverTiming(): string
    {
        $total = (float) ($this->context['metrics']['total_ms'] ?? 0.0);
        $db = (float) ($this->context['metrics']['db_ms'] ?? 0.0);
        $view = (float) ($this->context['metrics']['view_ms'] ?? 0.0);

        return sprintf('total;dur=%.3f, db;dur=%.3f, view;dur=%.3f', $total, $db, $view);
    }

    private function inspectorUrl(string $id): string
    {
        return $this->prefix . '/requests/' . $id;
    }

    private function pretty(mixed $value): string
    {
        return htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null');
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        return print_r($value, true);
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        $mask = filter_var($_ENV['SPARK_INSPECTOR_MASK'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if (!$mask) {
            return $value;
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = $this->sanitize($childValue, (string) $childKey);
            }
            return $clean;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value, $key);
        }

        $needle = strtolower((string) $key);
        foreach (['authorization', 'cookie', 'password', 'passwd', 'token', 'secret'] as $sensitive) {
            if ($needle !== '' && str_contains($needle, $sensitive)) {
                return '***';
            }
        }

        return $value;
    }

    private function snapshotRuntimeMetrics(): void
    {
        if ($this->context === []) {
            return;
        }

        $totalMs = $this->startedAt ? (microtime(true) - $this->startedAt) * 1000 : 0.0;
        $this->context['metrics']['total_ms'] = round($totalMs, 3);
        $this->context['metrics']['memory_end_kb'] = round(memory_get_usage(true) / 1024, 3);
        $this->context['metrics']['memory_peak_kb'] = round(memory_get_peak_usage(true) / 1024, 3);
    }

    private function refreshInsights(): void
    {
        if ($this->context === []) {
            return;
        }

        $this->context['pipelines'] = [
            'request' => $this->buildRequestPipeline(),
            'cache' => $this->buildCachePipeline(),
            'queue' => $this->buildQueuePipeline(),
        ];

        $this->context['bottlenecks'] = $this->buildBottlenecks($this->context['pipelines']);
    }

    private function buildRequestPipeline(): array
    {
        $request = $this->context['request'] ?? [];
        $response = $this->context['response'] ?? [];
        $route = $this->context['route'] ?? [];
        $timeline = $this->context['timeline'] ?? [];
        $metrics = $this->context['metrics'] ?? [];

        $steps = [[
            'stage' => 'request',
            'label' => trim(($request['method'] ?? 'GET') . ' ' . ($request['path'] ?? '/')),
            'status' => 'received',
            'meta' => implode(' • ', array_filter([
                $request['preferred_format'] ?? null,
                ($request['ajax'] ?? false) ? 'ajax' : null,
                $request['ip'] ?? null,
            ])),
        ]];

        if (($route['url'] ?? null) !== null || ($route['status'] ?? null) !== null) {
            $steps[] = [
                'stage' => 'route',
                'label' => (string) ($route['url'] ?? ($request['path'] ?? '/')),
                'status' => match ((int) ($route['status'] ?? 200)) {
                    404 => 'missing',
                    405 => 'method_not_allowed',
                    default => 'matched',
                },
                'meta' => implode(' • ', array_filter([
                    !empty($route['middlewares']) ? count($route['middlewares']) . ' middleware(s)' : null,
                    !empty($route['allowed']) ? 'Allow: ' . implode(', ', $route['allowed']) : null,
                ])),
            ];
        }

        foreach ($timeline as $item) {
            $meta = [];

            if (isset($item['duration_ms']) && (float) $item['duration_ms'] > 0) {
                $meta[] = number_format((float) $item['duration_ms'], 3) . ' ms';
            }

            if (isset($item['status'])) {
                $meta[] = (string) $item['status'];
            }

            if (isset($item['key']) && $item['key'] !== null) {
                $meta[] = 'key: ' . $item['key'];
            }

            if (isset($item['meta']['job']) && $item['meta']['job']) {
                $meta[] = 'job: ' . $item['meta']['job'];
            }

            if (isset($item['meta']['queue']) && $item['meta']['queue']) {
                $meta[] = 'queue: ' . $item['meta']['queue'];
            }

            $steps[] = [
                'stage' => $this->humanizePipelineStage((string) ($item['type'] ?? 'step')),
                'label' => (string) ($item['label'] ?? 'step'),
                'status' => (string) ($item['status'] ?? 'ok'),
                'meta' => implode(' • ', $meta),
            ];
        }

        $steps[] = [
            'stage' => 'response',
            'label' => (string) (($response['status'] ?? 'n/a') . ' ' . ($response['content_type'] ?? 'response')),
            'status' => 'sent',
            'meta' => implode(' • ', array_filter([
                isset($response['body_length']) ? number_format((float) $response['body_length']) . ' bytes' : null,
                isset($metrics['memory_peak_kb']) ? number_format((float) $metrics['memory_peak_kb'], 0) . ' KB peak' : null,
            ])),
        ];

        return [
            'summary' => [
                'Method' => $request['method'] ?? 'GET',
                'Path' => $request['path'] ?? '/',
                'Status' => (string) ($response['status'] ?? 'n/a'),
                'Total Time' => number_format((float) ($metrics['total_ms'] ?? 0.0), 1) . ' ms',
                'Queries' => (string) count($this->context['queries'] ?? []),
                'Views' => (string) count($this->context['views'] ?? []),
                'Cache Ops' => (string) ($metrics['cache_ops'] ?? 0),
                'Queue Ops' => (string) ($metrics['queue_ops'] ?? 0),
            ],
            'steps' => $steps,
        ];
    }

    private function buildCachePipeline(): array
    {
        $metrics = $this->context['metrics'] ?? [];
        $cache = $this->context['cache'] ?? [];
        $keys = [];

        foreach ($cache as $item) {
            $key = (string) ($item['key'] ?? '[none]');
            if (!isset($keys[$key])) {
                $keys[$key] = [
                    'key' => $key,
                    'ops' => 0,
                    'hits' => 0,
                    'misses' => 0,
                    'writes' => 0,
                    'stale_hits' => 0,
                    'tags' => [],
                ];
            }

            $keys[$key]['ops']++;

            if (($item['meta']['hit'] ?? false) === true) {
                $keys[$key]['hits']++;
            }

            if (($item['meta']['miss'] ?? false) === true) {
                $keys[$key]['misses']++;
            }

            if (($item['meta']['stale'] ?? false) === true) {
                $keys[$key]['stale_hits']++;
            }

            if (in_array($item['operation'] ?? '', ['set', 'touch', 'forget', 'flush', 'flush_tags', 'increment', 'decrement', 'refresh'], true)) {
                $keys[$key]['writes']++;
            }

            foreach ((array) ($item['meta']['tags'] ?? []) as $tag) {
                $keys[$key]['tags'][$tag] = true;
            }
        }

        foreach ($keys as &$key) {
            $key['tags'] = array_values(array_keys($key['tags']));
        }
        unset($key);

        usort($keys, static function (array $left, array $right): int {
            return [$right['ops'], $right['writes'], $right['hits']] <=> [$left['ops'], $left['writes'], $left['hits']];
        });

        return [
            'summary' => [
                'Ops' => (string) ($metrics['cache_ops'] ?? 0),
                'Hit Rate' => $this->cacheHitRate($metrics),
                'Hits' => (string) ($metrics['cache_hits'] ?? 0),
                'Misses' => (string) ($metrics['cache_misses'] ?? 0),
                'Stale Hits' => (string) ($metrics['cache_stale_hits'] ?? 0),
                'Writes' => (string) ($metrics['cache_writes'] ?? 0),
                'Unique Keys' => (string) count($keys),
            ],
            'hot_keys' => array_slice($keys, 0, 5),
        ];
    }

    private function buildQueuePipeline(): array
    {
        $metrics = $this->context['metrics'] ?? [];
        $queue = $this->context['queue'] ?? [];
        $jobs = [];

        foreach ($queue as $item) {
            $job = (string) ($item['job'] ?? 'unknown');

            if (!isset($jobs[$job])) {
                $jobs[$job] = [
                    'job' => $job,
                    'queues' => [],
                    'events' => [],
                    'attempts' => 0,
                    'tries' => null,
                    'failures' => 0,
                    'retries' => 0,
                    'released' => 0,
                    'last_error' => null,
                ];
            }

            $type = (string) ($item['type'] ?? 'unknown');
            $jobs[$job]['events'][$type] = ((int) ($jobs[$job]['events'][$type] ?? 0)) + 1;

            if (isset($item['queue']) && $item['queue']) {
                $jobs[$job]['queues'][(string) $item['queue']] = true;
            }

            $jobs[$job]['attempts'] = max((int) $jobs[$job]['attempts'], (int) ($item['attempts'] ?? 0));
            $jobs[$job]['tries'] = $item['tries'] ?? $jobs[$job]['tries'];

            if ($type === 'failed') {
                $jobs[$job]['failures']++;
            }

            if ($type === 'retry') {
                $jobs[$job]['retries']++;
            }

            if ($type === 'released') {
                $jobs[$job]['released']++;
            }

            if (!empty($item['error'])) {
                $jobs[$job]['last_error'] = $item['error'];
            }
        }

        foreach ($jobs as &$job) {
            $job['queues'] = array_values(array_keys($job['queues']));
        }
        unset($job);

        $jobs = array_values($jobs);
        usort($jobs, static function (array $left, array $right): int {
            return [$right['failures'], $right['retries'], array_sum($right['events'])] <=> [$left['failures'], $left['retries'], array_sum($left['events'])];
        });

        return [
            'summary' => [
                'Ops' => (string) ($metrics['queue_ops'] ?? 0),
                'Dispatches' => (string) ($metrics['queue_dispatches'] ?? 0),
                'Sync Dispatches' => (string) ($metrics['queue_sync_dispatches'] ?? 0),
                'Enqueued' => (string) ($metrics['queue_enqueued'] ?? 0),
                'Delayed' => (string) ($metrics['queue_delayed'] ?? 0),
                'Processed' => (string) ($metrics['queue_processed'] ?? 0),
                'Released' => (string) ($metrics['queue_released'] ?? 0),
                'Failures' => (string) ($metrics['queue_failed'] ?? 0),
                'Retries' => (string) ($metrics['queue_retries'] ?? 0),
            ],
            'jobs' => array_slice($jobs, 0, 8),
        ];
    }

    private function buildBottlenecks(array $pipelines): array
    {
        $timeline = $this->context['timeline'] ?? [];
        $queries = $this->context['queries'] ?? [];
        $views = $this->context['views'] ?? [];
        $cacheHotKeys = $pipelines['cache']['hot_keys'] ?? [];
        $queueJobs = $pipelines['queue']['jobs'] ?? [];

        $slowestStep = $this->findMaxBy($timeline, 'duration_ms');
        $slowestQuery = $this->findMaxBy($queries, 'duration_ms');
        $slowestView = $this->findMaxBy($views, 'duration_ms');
        $noisiestCacheKey = $this->findMaxBy($cacheHotKeys, 'ops');
        $mostFragileJob = null;

        if ($queueJobs !== []) {
            usort($queueJobs, static function (array $left, array $right): int {
                return [$right['failures'], $right['retries'], $right['released']] <=> [$left['failures'], $left['retries'], $left['released']];
            });

            $mostFragileJob = ($queueJobs[0]['failures'] ?? 0) > 0 || ($queueJobs[0]['retries'] ?? 0) > 0
                ? $queueJobs[0]
                : null;
        }

        return array_filter([
            'slowest_step' => $slowestStep,
            'slowest_query' => $slowestQuery,
            'slowest_view' => $slowestView,
            'noisiest_cache_key' => $noisiestCacheKey,
            'most_fragile_job' => $mostFragileJob,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function renderSummaryGrid(array $items): string
    {
        if ($items === []) {
            return '<div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg><p>No summary available</p></div>';
        }

        $rows = [];

        foreach ($items as $label => $value) {
            $rows[] = sprintf(
                '<div class="summary-item"><strong>%s</strong><span>%s</span></div>',
                htmlspecialchars((string) $label),
                htmlspecialchars((string) $value)
            );
        }

        return '<div class="summary-grid">' . implode('', $rows) . '</div>';
    }

    private function renderPipelineSteps(array $steps): string
    {
        if ($steps === []) {
            return '<div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg><p>No pipeline steps captured</p></div>';
        }

        $rows = [];

        foreach ($steps as $step) {
            $stage = htmlspecialchars((string) ($step['stage'] ?? 'step'));
            $label = htmlspecialchars((string) ($step['label'] ?? ''));
            $status = htmlspecialchars((string) ($step['status'] ?? 'ok'));
            $meta = trim((string) ($step['meta'] ?? ''));

            $rows[] = sprintf(
                '<div class="pipeline-step"><div class="pipeline-step__top"><div><div class="pipeline-step__stage">%s</div><div class="pipeline-step__label">%s</div></div><span class="badge">%s</span></div>%s</div>',
                $stage,
                $label,
                $status,
                $meta !== '' ? '<div class="pipeline-step__meta">' . htmlspecialchars($meta) . '</div>' : ''
            );
        }

        return '<div class="pipeline-list">' . implode('', $rows) . '</div>';
    }

    private function humanizePipelineStage(string $type): string
    {
        $type = str_replace(['_', '-'], ' ', trim($type));
        $type = ucwords($type);

        return $type !== '' ? $type : 'Step';
    }

    private function cacheHitRate(array $metrics): string
    {
        $hits = (int) ($metrics['cache_hits'] ?? 0);
        $misses = (int) ($metrics['cache_misses'] ?? 0);
        $lookups = $hits + $misses;

        return $lookups > 0
            ? number_format(($hits / $lookups) * 100, 1) . '%'
            : 'n/a';
    }

    private function findMaxBy(array $items, string $key): ?array
    {
        $winner = null;
        $max = null;

        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists($key, $item)) {
                continue;
            }

            $value = (float) $item[$key];
            if ($max === null || $value > $max) {
                $max = $value;
                $winner = $item;
            }
        }

        if ($winner === null || ((float) ($winner[$key] ?? 0)) <= 0) {
            return null;
        }

        return $winner;
    }

    private function countPipelineSteps(array $steps): int
    {
        return count($steps);
    }

    private function countPipelineItems(array $items): int
    {
        return count($items);
    }
}
