<?php

class Database
{
    private static ?Database $instance = null;
    private ?\PDO $pdo = null;

    public static function getInstance(): static
    {
        if (!static::$instance) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public static function reset(): void
    {
        static::$instance = null;
    }

    // ─────────────────────────────────────────────
    // Lazy connection
    // ─────────────────────────────────────────────

    public function pdo(): \PDO
    {
        if ($this->pdo) {
            return $this->pdo;
        }

        $driver = $_ENV['DB'] ?? 'mysql';
        $host   = $_ENV['DB_HOST'] ?? 'localhost';
        $port   = $_ENV['DB_PORT'] ?? '3306';
        $name   = $_ENV['DB_NAME'] ?? '';
        $user   = $_ENV['DB_USER'] ?? 'root';
        $pass   = $_ENV['DB_PASS'] ?? '';

        $dsn = match ($driver) {
            'mysql'  => "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            'pgsql'  => "pgsql:host={$host};port={$port};dbname={$name}",
            'sqlite' => "sqlite:{$name}",
            default  => throw new \RuntimeException("Unsupported DB driver: {$driver}"),
        };

        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $this->pdo;
    }

    public function driver(): string
    {
        return $_ENV['DB'] ?? 'mysql';
    }

    // ─────────────────────────────────────────────
    // Query builder entry point
    // ─────────────────────────────────────────────

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    // ─────────────────────────────────────────────
    // Raw queries
    // ─────────────────────────────────────────────

    public function raw(string $sql, array $bindings = []): array
    {
        $stmt = $this->execute($sql, $bindings);
        return $stmt->fetchAll();
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $this->execute($sql, $bindings);
        return true;
    }

    public function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }

    // ─────────────────────────────────────────────
    // Transactions
    // ─────────────────────────────────────────────

    public function transaction(callable $callback): mixed
    {
        $this->pdo()->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo()->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────
    // Schema inspection (cached)
    // ─────────────────────────────────────────────

    public function getColumns(string $table): array
    {
        $driver = $this->driver();
        return match ($driver) {
            'mysql'  => $this->mysqlColumns($table),
            'sqlite' => $this->sqliteColumns($table),
            'pgsql'  => $this->pgsqlColumns($table),
            default  => [],
        };
    }

    private function mysqlColumns(string $table): array
    {
        $rows = $this->raw("DESCRIBE `{$table}`");
        return array_column((array) $rows, 'Field');
    }

    private function sqliteColumns(string $table): array
    {
        $rows = $this->raw("PRAGMA table_info({$table})");
        return array_column((array) $rows, 'name');
    }

    private function pgsqlColumns(string $table): array
    {
        $rows = $this->raw(
            "SELECT column_name FROM information_schema.columns WHERE table_name = ?",
            [$table]
        );
        return array_column((array) $rows, 'column_name');
    }

    public function execute(string $sql, array $bindings = []): \PDOStatement
    {
        $start = microtime(true);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);
        $durationMs = (microtime(true) - $start) * 1000;

        if (class_exists('SparkInspector')) {
            SparkInspector::recordQuery($sql, $bindings, $durationMs, $stmt->rowCount());
        }

        return $stmt;
    }
}
