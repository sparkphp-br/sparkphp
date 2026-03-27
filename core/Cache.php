<?php

class Cache
{
    private string $basePath;
    private string $driver;
    private string $cacheDir;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->driver   = $_ENV['CACHE'] ?? 'file';
        $this->cacheDir = $basePath . '/storage/cache/app';

        if ($this->driver === 'file' && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    // ─────────────────────────────────────────────
    // Get
    // ─────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        $value = match ($this->driver) {
            'memory' => $this->memGet($key, $default),
            default  => $this->fileGet($key, $default),
        };

        if (class_exists('SparkInspector')) {
            SparkInspector::recordCache('get', $key, ['hit' => $value !== $default]);
        }

        return $value;
    }

    // ─────────────────────────────────────────────
    // Set
    // ─────────────────────────────────────────────

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        match ($this->driver) {
            'memory' => $this->memSet($key, $value, $ttl),
            default  => $this->fileSet($key, $value, $ttl),
        };

        if (class_exists('SparkInspector')) {
            SparkInspector::recordCache('set', $key, ['ttl' => $ttl]);
        }
    }

    // ─────────────────────────────────────────────
    // Delete / flush
    // ─────────────────────────────────────────────

    public function forget(string $key): void
    {
        match ($this->driver) {
            'memory' => $this->memForget($key),
            default  => $this->fileForget($key),
        };

        if (class_exists('SparkInspector')) {
            SparkInspector::recordCache('forget', $key);
        }
    }

    public function flush(): void
    {
        match ($this->driver) {
            'memory' => $this->memFlush(),
            default  => $this->fileFlush(),
        };

        if (class_exists('SparkInspector')) {
            SparkInspector::recordCache('flush', null);
        }
    }

    // ─────────────────────────────────────────────
    // Has
    // ─────────────────────────────────────────────

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    // ─────────────────────────────────────────────
    // Increment / Decrement
    // ─────────────────────────────────────────────

    public function increment(string $key, int $by = 1): int
    {
        $val = (int) ($this->get($key) ?? 0);
        $val += $by;
        $this->set($key, $val);
        return $val;
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    public function expire(string $key, int $ttl): void
    {
        $val = $this->get($key);
        if ($val !== null) {
            $this->set($key, $val, $ttl);
        }
    }

    // ─────────────────────────────────────────────
    // Remember helper
    // ─────────────────────────────────────────────

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $val = $this->get($key);
        if ($val !== null) {
            return $val;
        }
        $val = $callback();
        $this->set($key, $val, $ttl);
        return $val;
    }

    // ─────────────────────────────────────────────
    // File driver
    // ─────────────────────────────────────────────

    private function filePath(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    private function fileGet(string $key, mixed $default): mixed
    {
        $path = $this->filePath($key);
        if (!file_exists($path)) {
            return $default;
        }

        $data = unserialize(file_get_contents($path));
        if (!is_array($data)) {
            return $default;
        }

        // Expired?
        if ($data['ttl'] > 0 && $data['expires_at'] < time()) {
            unlink($path);
            return $default;
        }

        return $data['value'];
    }

    private function fileSet(string $key, mixed $value, int $ttl): void
    {
        $data = [
            'value'      => $value,
            'ttl'        => $ttl,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];
        file_put_contents($this->filePath($key), serialize($data));
    }

    private function fileForget(string $key): void
    {
        $path = $this->filePath($key);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function fileFlush(): void
    {
        foreach (glob($this->cacheDir . '/*.cache') as $file) {
            unlink($file);
        }
    }

    // ─────────────────────────────────────────────
    // In-memory driver (request-lifetime)
    // ─────────────────────────────────────────────

    private static array $memory = [];

    private function memGet(string $key, mixed $default): mixed
    {
        if (!isset(self::$memory[$key])) {
            return $default;
        }
        $item = self::$memory[$key];
        if ($item['ttl'] > 0 && $item['expires_at'] < time()) {
            unset(self::$memory[$key]);
            return $default;
        }
        return $item['value'];
    }

    private function memSet(string $key, mixed $value, int $ttl): void
    {
        self::$memory[$key] = [
            'value'      => $value,
            'ttl'        => $ttl,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];
    }

    private function memForget(string $key): void
    {
        unset(self::$memory[$key]);
    }

    private function memFlush(): void
    {
        self::$memory = [];
    }
}
