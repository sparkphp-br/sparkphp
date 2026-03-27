<?php

class Request
{
    private array $input   = [];
    private array $files   = [];
    private ?string $rawBody = null;

    public function __construct()
    {
        $this->parseInput();
    }

    // ─────────────────────────────────────────────
    // Input
    // ─────────────────────────────────────────────

    private function parseInput(): void
    {
        $this->files = $_FILES ?? [];

        // JSON body
        if ($this->isJson()) {
            $this->rawBody = file_get_contents('php://input');
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $this->input = $decoded;
                return;
            }
        }

        // POST body
        $this->input = array_merge($_GET ?? [], $_POST ?? []);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->input;
        }
        return $this->input[$key] ?? $default;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET ?? [];
        }
        return $_GET[$key] ?? $default;
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function all(): array
    {
        return $this->input;
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->input, array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->input, array_flip($keys));
    }

    // ─────────────────────────────────────────────
    // HTTP Info
    // ─────────────────────────────────────────────

    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Method override
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $this->header('X-HTTP-Method-Override');
            if ($override) {
                return strtoupper($override);
            }
        }
        return $method;
    }

    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        return $pos !== false ? substr($uri, 0, $pos) : $uri;
    }

    public function url(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        return $scheme . '://' . ($this->header('Host') ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public function fullUrl(): string
    {
        return $this->url();
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        // Special cases
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        } elseif ($name === 'Content-Length') {
            $key = 'CONTENT_LENGTH';
        } elseif ($name === 'Authorization') {
            $key = 'HTTP_AUTHORIZATION';
            if (!isset($_SERVER[$key]) && function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                return $headers['Authorization'] ?? $default;
            }
        }

        return $_SERVER[$key] ?? $default;
    }

    public function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $_COOKIE[$key] ?? $default;
    }

    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    // ─────────────────────────────────────────────
    // Type detection
    // ─────────────────────────────────────────────

    public function isJson(): bool
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($ct, 'application/json');
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function acceptsJson(): bool
    {
        $accept = $this->header('Accept') ?? '';
        return str_contains($accept, 'application/json') || $this->isAjax();
    }

    public function acceptsHtml(): bool
    {
        $accept = $this->header('Accept') ?? '';
        return str_contains($accept, 'text/html') || $accept === '*/*' || $accept === '';
    }

    public function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    }

    public function isGet(): bool    { return $this->method() === 'GET'; }
    public function isPost(): bool   { return $this->method() === 'POST'; }
    public function isPut(): bool    { return $this->method() === 'PUT'; }
    public function isPatch(): bool  { return $this->method() === 'PATCH'; }
    public function isDelete(): bool { return $this->method() === 'DELETE'; }
}
