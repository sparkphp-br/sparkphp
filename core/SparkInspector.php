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
            'dumps' => [],
            'exceptions' => [],
            'metrics' => [
                'db_ms' => 0.0,
                'view_ms' => 0.0,
                'total_ms' => 0.0,
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
                Response::json(['error' => 'Not Found'], 404)->send();
                return true;
            }

            Response::json($entry)->send();
            return true;
        }

        if ($request->method() === 'POST' && $relativePath === 'clear') {
            $cleared = $this->storage->clear();

            if ($request->acceptsJson()) {
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

        $this->context['cache'][] = [
            'operation' => $operation,
            'key' => $key,
            'meta' => $this->sanitize($meta),
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

        $this->context['queue'][] = $this->sanitize($payload);
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
    #spark-inspector-toolbar .spark-inspector-minimized{display:none;padding:10px;}
    #spark-inspector-toolbar .spark-inspector-minimized-button{display:flex;align-items:center;justify-content:center;width:72px;height:72px;border:0;border-radius:22px;background:#1f2937;cursor:pointer;padding:0;box-shadow:inset 0 0 0 1px #374151;}
    #spark-inspector-toolbar .spark-inspector-minimized-button:hover{background:#263244;}
    #spark-inspector-toolbar .spark-inspector-minimized-button .spark-inspector-badge{width:48px;height:48px;font-size:24px;}
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-body,
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-footer,
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-name{display:none;}
    #spark-inspector-toolbar[data-state="compact"]{max-width:min(240px,calc(100vw - 24px));}
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-header{padding:8px 10px;}
    #spark-inspector-toolbar[data-state="compact"] .spark-inspector-summary{max-width:110px;}
    #spark-inspector-toolbar[data-state="minimized"]{max-width:92px;}
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
        <span class="spark-inspector-badge">S</span>
        <div style="min-width:0;">
          <strong class="spark-inspector-name">Spark Inspector</strong>
          <div class="spark-inspector-summary">{$totalMs} ms • {$queries} queries • {$exceptions} exceptions</div>
        </div>
      </div>
      <div class="spark-inspector-controls">
        <button type="button" id="spark-inspector-minimize" class="spark-inspector-button" aria-label="Minimize toolbar">Minimize</button>
        <button type="button" id="spark-inspector-toggle" class="spark-inspector-button" aria-label="Toggle compact mode">Compact</button>
        <a href="{$href}" class="spark-inspector-open">Open</a>
      </div>
    </div>
    <div class="spark-inspector-body">
      <div class="spark-inspector-metric"><strong>Total</strong><span>{$totalMs} ms</span></div>
      <div class="spark-inspector-metric"><strong>Memory</strong><span>{$memoryKb} KB</span></div>
      <div class="spark-inspector-metric"><strong>Queries</strong><span>{$queries}</span></div>
      <div class="spark-inspector-metric"><strong>Exceptions</strong><span>{$exceptions}</span></div>
    </div>
    <div class="spark-inspector-footer">
      <span class="spark-inspector-id">{$id}</span>
      <span style="color:#6b7280;font-size:11px;">Drag the header to float this widget.</span>
    </div>
    <div class="spark-inspector-minimized" id="spark-inspector-minimized">
      <button type="button" id="spark-inspector-restore" class="spark-inspector-minimized-button" aria-label="Restore toolbar">
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
  var minimize = document.getElementById('spark-inspector-minimize');
  var restore = document.getElementById('spark-inspector-restore');
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
      toggle.style.display = toolbar.dataset.state === 'minimized' ? 'none' : '';
    }

    if (minimize) {
      minimize.style.display = toolbar.dataset.state === 'minimized' ? 'none' : '';
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

  if (minimize) {
    minimize.addEventListener('click', function (event) {
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

  if (restore) {
    restore.addEventListener('click', function (event) {
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
            $statusColor = ((int) $item['status']) >= 500 ? '#dc2626' : (((int) $item['status']) >= 400 ? '#d97706' : '#059669');
            $slow = (float) $item['duration_ms'] >= $slowMs;
            $rows[] = sprintf(
                '<tr style="background:%s;"><td><a href="%s/requests/%s">%s</a></td><td>%s</td><td><span style="color:%s;font-weight:700;">%s</span></td><td>%.1f ms</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $slow ? '#fff7ed' : '#ffffff',
                htmlspecialchars($this->prefix),
                htmlspecialchars($item['id']),
                htmlspecialchars($item['method']),
                htmlspecialchars($item['path']),
                $statusColor,
                htmlspecialchars((string) $item['status']),
                (float) $item['duration_ms'],
                number_format((float) $item['memory_peak_kb'], 0) . ' KB',
                htmlspecialchars((string) $item['query_count']),
                htmlspecialchars($item['created_at'])
            );
        }

        $tableRows = $rows === [] ? '<tr><td colspan="7">No requests captured yet.</td></tr>' : implode('', $rows);

        $content = <<<HTML
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;">
  <div>
    <h1 style="margin:0;">Spark Inspector</h1>
    <p style="margin:6px 0 0;color:#6b7280;">Recent requests captured by the framework.</p>
  </div>
  <form method="POST" action="{$this->prefix}/clear">
    <button type="submit" style="background:#111827;color:#fff;border:0;border-radius:10px;padding:10px 14px;cursor:pointer;">Clear History</button>
  </form>
</div>
<table style="width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;">
  <thead style="background:#111827;color:#fff;">
    <tr><th style="text-align:left;padding:12px;">Method</th><th style="text-align:left;padding:12px;">Path</th><th style="text-align:left;padding:12px;">Status</th><th style="text-align:left;padding:12px;">Duration</th><th style="text-align:left;padding:12px;">Memory</th><th style="text-align:left;padding:12px;">Queries</th><th style="text-align:left;padding:12px;">Captured</th></tr>
  </thead>
  <tbody>{$tableRows}</tbody>
</table>
HTML;

        return $this->renderShell('Spark Inspector', $content);
    }

    private function renderRequestPage(array $entry): string
    {
        $tabs = [
            'overview' => $this->renderOverviewTab($entry),
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
            $nav[] = sprintf(
                '<button type="button" data-tab="%s" style="border:0;background:%s;color:%s;padding:10px 14px;border-radius:999px;cursor:pointer;">%s</button>',
                htmlspecialchars($name),
                $first ? '#111827' : '#e5e7eb',
                $first ? '#fff' : '#111827',
                htmlspecialchars($label)
            );

            $panels[] = sprintf(
                '<section data-panel="%s" style="display:%s;margin-top:18px;">%s</section>',
                htmlspecialchars($name),
                $first ? 'block' : 'none',
                $panel
            );
            $first = false;
        }

        $id = htmlspecialchars($entry['id']);
        $content = <<<HTML
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
  <div>
    <h1 style="margin:0;">Request {$id}</h1>
    <p style="margin:6px 0 0;color:#6b7280;">{$entry['request']['method']} {$entry['request']['path']}</p>
  </div>
  <a href="{$this->prefix}/requests" style="color:#111827;text-decoration:none;background:#e5e7eb;padding:10px 14px;border-radius:10px;">Back</a>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;">%s</div>
%s
<script>
document.querySelectorAll('[data-tab]').forEach(function(button) {
  button.addEventListener('click', function() {
    document.querySelectorAll('[data-tab]').forEach(function(other) {
      other.style.background = '#e5e7eb';
      other.style.color = '#111827';
    });
    document.querySelectorAll('[data-panel]').forEach(function(panel) {
      panel.style.display = 'none';
    });
    button.style.background = '#111827';
    button.style.color = '#fff';
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

        return <<<HTML
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;">
  {$this->metricCard('Status', (string) ($entry['response']['status'] ?? 'n/a'))}
  {$this->metricCard('Total', number_format((float) ($metrics['total_ms'] ?? 0), 1) . ' ms')}
  {$this->metricCard('DB', number_format((float) ($metrics['db_ms'] ?? 0), 1) . ' ms')}
  {$this->metricCard('Views', (string) $views)}
  {$this->metricCard('Queries', (string) $queries)}
  {$this->metricCard('Exceptions', (string) $exceptions)}
  {$this->metricCard('Memory Peak', number_format((float) ($metrics['memory_peak_kb'] ?? 0), 0) . ' KB')}
</div>
<div style="margin-top:18px;background:#fff;border-radius:14px;padding:18px;">
  <h2 style="margin-top:0;">Route</h2>
  <pre style="white-space:pre-wrap;">{$this->pretty($entry['route'] ?? [])}</pre>
</div>
HTML;
    }

    private function renderCollectionTab(string $title, array $items): string
    {
        $content = $items === []
            ? '<p style="color:#6b7280;">No data captured.</p>'
            : '<pre style="white-space:pre-wrap;">' . $this->pretty($items) . '</pre>';

        return <<<HTML
<div style="background:#fff;border-radius:14px;padding:18px;">
  <h2 style="margin-top:0;">{$title}</h2>
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
    body { margin:0; background:#f3f4f6; color:#111827; font:14px/1.5 system-ui,sans-serif; }
    main { max-width:1200px; margin:0 auto; padding:24px; }
    table tbody tr td { padding:12px; border-bottom:1px solid #e5e7eb; }
    a { color:#111827; }
    pre { margin:0; font:12px/1.5 ui-monospace,SFMono-Regular,monospace; background:#f9fafb; padding:14px; border-radius:12px; overflow:auto; }
  </style>
</head>
<body>
  <main>{$content}</main>
</body>
</html>
HTML;
    }

    private function metricCard(string $label, string $value): string
    {
        $label = htmlspecialchars($label);
        $value = htmlspecialchars($value);

        return <<<HTML
<div style="background:#fff;border-radius:14px;padding:18px;">
  <div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">{$label}</div>
  <div style="font-size:24px;font-weight:700;margin-top:8px;">{$value}</div>
</div>
HTML;
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
}
