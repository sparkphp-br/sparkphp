<?php

class Session
{
    private string $basePath;
    private bool $started = false;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $driver = $_ENV['SESSION'] ?? 'file';

        if ($driver === 'file') {
            $path = $this->basePath . '/storage/sessions';
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            session_save_path($path);
        }

        session_name('spark_session');

        $secure   = !empty($_ENV['SESSION_SECURE']) && $_ENV['SESSION_SECURE'] !== 'false';
        $lifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 7200);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        $this->started = true;

        // Rotate flash: move 'flash_new' → 'flash', remove old flash
        $_SESSION['flash']     = $_SESSION['flash_new'] ?? [];
        $_SESSION['flash_new'] = [];
    }

    // ─────────────────────────────────────────────
    // Read / Write
    // ─────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function put(array $data): void
    {
        foreach ($data as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flush(): void
    {
        $_SESSION = [];
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }

    // ─────────────────────────────────────────────
    // Flash (1-request lifetime)
    // ─────────────────────────────────────────────

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['flash_new'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['flash'][$key] ?? $default;
    }

    // ─────────────────────────────────────────────
    // Helpers for validation feedback
    // ─────────────────────────────────────────────

    public function flashOld(array $data): void
    {
        $this->flash('_old', $data);
    }

    public function old(string $field, mixed $default = null): mixed
    {
        return ($_SESSION['flash']['_old'] ?? [])[$field] ?? $default;
    }

    public function flashErrors(array $errors): void
    {
        $this->flash('_errors', $errors);
    }

    public function errors(?string $field = null): mixed
    {
        $errors = $_SESSION['flash']['_errors'] ?? [];
        if ($field === null) {
            return $errors;
        }
        return $errors[$field] ?? null;
    }

    // ─────────────────────────────────────────────
    // CSRF
    // ─────────────────────────────────────────────

    public function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function verifyCsrf(string $token): bool
    {
        return hash_equals($_SESSION['_csrf'] ?? '', $token);
    }

    // ─────────────────────────────────────────────
    // Regenerate
    // ─────────────────────────────────────────────

    public function regenerate(bool $deleteOld = true): void
    {
        session_regenerate_id($deleteOld);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        $this->started = false;
    }
}
