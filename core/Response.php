<?php

class Response
{
    private int $status  = 200;
    private array $headers = [];
    private mixed $body  = null;

    public function __construct(mixed $body = null, int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    // ─────────────────────────────────────────────
    // Static factories
    // ─────────────────────────────────────────────

    public static function json(mixed $data, int $status = 200): static
    {
        return new static(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function html(string $html, int $status = 200): static
    {
        return new static($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $url, int $status = 302): static
    {
        return new static('', $status, ['Location' => $url]);
    }

    public static function created(mixed $data): static
    {
        return static::json($data, 201);
    }

    public static function noContent(): static
    {
        return new static('', 204);
    }

    public static function notFound(string $message = 'Not Found'): static
    {
        return static::json(['error' => $message], 404);
    }

    public static function error(string $message, int $status = 500): static
    {
        return static::json(['error' => $message], $status);
    }

    public static function download(string $filePath, ?string $name = null): static
    {
        $name = $name ?? basename($filePath);
        $r    = new static(null, 200, [
            'Content-Type'        => mime_content_type($filePath) ?: 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Content-Length'      => (string) filesize($filePath),
        ]);
        $r->body = fn() => readfile($filePath);
        return $r;
    }

    // ─────────────────────────────────────────────
    // Instance API
    // ─────────────────────────────────────────────

    public function status(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(mixed $content = null): void
    {
        if ($content !== null) {
            $this->body = $content;
        }

        if (class_exists('SparkInspector')) {
            SparkInspector::decorateResponse($this);
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if (is_callable($this->body)) {
            ($this->body)();
        } else {
            echo $this->body;
        }

        if (class_exists('SparkInspector')) {
            SparkInspector::finalizeResponse($this);
        }
    }

    // ─────────────────────────────────────────────
    // Smart resolver
    // ─────────────────────────────────────────────

    /**
     * Intelligently resolve the return value of a route handler into a response.
     */
    public function resolve(mixed $result, Request $request, View $view, string $route): void
    {
        // Already a Response object
        if ($result instanceof static) {
            $result->send();
            return;
        }

        // Explicit redirect
        if (is_string($result) && str_starts_with($result, 'redirect:')) {
            static::redirect(substr($result, 9))->send();
            return;
        }

        $method = strtolower($request->method());
        $status = $method === 'post' ? 201 : 200;

        // null on GET → 404
        if ($result === null && $method === 'get') {
            if ($request->acceptsJson()) {
                static::notFound()->send();
                return;
            }

            $this->sendErrorView($view, 404, 'Not Found');
            return;
        }

        // null on non-GET → 204
        if ($result === null) {
            static::noContent()->send();
            return;
        }

        // String → HTML
        if (is_string($result)) {
            static::html($result)->send();
            return;
        }

        // Array/object
        if (is_array($result) || is_object($result)) {
            // API request → JSON
            if ($request->acceptsJson()) {
                static::json($result, $status)->send();
                return;
            }

            // HTML request → look for mirror view
            $viewName  = $this->routeToViewName($route);
            $variables = is_array($result) ? $result : (array) $result;

            try {
                $html = $view->render($viewName, $variables);
                static::html($html, $status)->send();
            } catch (\RuntimeException) {
                // No view found → fall back to JSON
                static::json($result, $status)->send();
            }
            return;
        }

        // Boolean true → 204
        if ($result === true) {
            static::noContent()->send();
            return;
        }

        echo $result;
    }

    private function routeToViewName(string $route): string
    {
        // /users/:id → users/show (not ideal, but a sensible default)
        // /users     → users/index
        $path = trim($route, '/');
        return $path ?: 'index';
    }

    private function sendErrorView(View $view, int $status, string $fallbackMessage): void
    {
        http_response_code($status);

        try {
            static::html($view->render("errors/{$status}", [
                'code' => $status,
                'message' => $fallbackMessage,
            ]), $status)->send();
            return;
        } catch (\RuntimeException) {
            echo "<h1>{$status} - {$fallbackMessage}</h1>";
        }
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function setBody(mixed $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists($name, $this->headers);
    }

    public function contentType(): string
    {
        return $this->headers['Content-Type'] ?? '';
    }

    public function isHtmlInspectable(): bool
    {
        if ($this->status === 204 || $this->hasHeader('Location')) {
            return false;
        }

        if (is_callable($this->body) || !is_string($this->body) || $this->body === '') {
            return false;
        }

        return str_contains(strtolower($this->contentType()), 'text/html');
    }
}
