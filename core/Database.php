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
        $driver = $_ENV['DB'] ?? 'mysql';
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


// ─────────────────────────────────────────────────────────────────────────────
// Query Builder
// ─────────────────────────────────────────────────────────────────────────────

class QueryBuilder
{
    private Database $db;
    private string $table;
    private array $wheres   = [];
    private array $bindings = [];
    private ?string $orderBy = null;
    private ?int $limitVal  = null;
    private ?int $offsetVal = null;
    private array $selects  = ['*'];
    private string $modelClass = '';

    public function __construct(Database $db, string $table)
    {
        $this->db    = $db;
        $this->table = $table;
    }

    public function setModel(string $class): static
    {
        $this->modelClass = $class;
        return $this;
    }

    // ─────────────────────────────────────────────
    // Clauses
    // ─────────────────────────────────────────────

    public function select(array|string $columns): static
    {
        $this->selects = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        if ($value === null) {
            $this->wheres[]   = "`{$column}` = ?";
            $this->bindings[] = $operatorOrValue;
        } else {
            $this->wheres[]   = "`{$column}` {$operatorOrValue} ?";
            $this->bindings[] = $value;
        }
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $placeholders   = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "`{$column}` IN ({$placeholders})";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = "`{$column}` IS NULL";
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = "`{$column}` IS NOT NULL";
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy = "`{$column}` " . strtoupper($direction);
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limitVal = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offsetVal = $offset;
        return $this;
    }

    // ─────────────────────────────────────────────
    // Fetch
    // ─────────────────────────────────────────────

    public function get(): array
    {
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->db->execute($sql, $bindings);
        $rows = $stmt->fetchAll();
        return $this->hydrateAll($rows);
    }

    public function first(): mixed
    {
        $this->limitVal = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    public function find(int|string $id): mixed
    {
        return $this->where('id', $id)->first();
    }

    public function all(): array
    {
        return $this->get();
    }

    public function count(): int
    {
        $where = $this->buildWhere();
        $sql   = "SELECT COUNT(*) as cnt FROM `{$this->table}`" . $where[0];
        $stmt  = $this->db->execute($sql, $where[1]);
        return (int) $stmt->fetchColumn();
    }

    public function sum(string $column): float
    {
        $where = $this->buildWhere();
        $sql   = "SELECT SUM(`{$column}`) FROM `{$this->table}`" . $where[0];
        $stmt  = $this->db->execute($sql, $where[1]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    public function max(string $column): mixed
    {
        $where = $this->buildWhere();
        $sql   = "SELECT MAX(`{$column}`) FROM `{$this->table}`" . $where[0];
        $stmt  = $this->db->execute($sql, $where[1]);
        return $stmt->fetchColumn();
    }

    public function min(string $column): mixed
    {
        $where = $this->buildWhere();
        $sql   = "SELECT MIN(`{$column}`) FROM `{$this->table}`" . $where[0];
        $stmt  = $this->db->execute($sql, $where[1]);
        return $stmt->fetchColumn();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ─────────────────────────────────────────────
    // Pagination
    // ─────────────────────────────────────────────

    public function paginate(int $perPage = 15): object
    {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $total   = $this->count();
        $lastPage = (int) ceil($total / $perPage);

        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return (object) [
            'data'        => $items,
            'total'       => $total,
            'per_page'    => $perPage,
            'current_page'=> $page,
            'last_page'   => $lastPage,
            'from'        => ($page - 1) * $perPage + 1,
            'to'          => min($page * $perPage, $total),
        ];
    }

    // ─────────────────────────────────────────────
    // Mutate
    // ─────────────────────────────────────────────

    public function create(array $data): mixed
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT INTO `{$this->table}` ({$cols}) VALUES ({$phs})";

        $this->db->execute($sql, array_values($data));
        $id = $this->db->lastInsertId();

        return $this->find((int) $id) ?? (object) array_merge(['id' => (int) $id], $data);
    }

    public function insert(array $data): bool
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT INTO `{$this->table}` ({$cols}) VALUES ({$phs})";
        $this->db->execute($sql, array_values($data));
        return true;
    }

    public function update(array $data): int
    {
        $set      = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $where    = $this->buildWhere();
        $sql      = "UPDATE `{$this->table}` SET {$set}" . $where[0];
        $bindings = array_merge(array_values($data), $where[1]);
        $stmt     = $this->db->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $where = $this->buildWhere();
        $sql   = "DELETE FROM `{$this->table}`" . $where[0];
        $stmt  = $this->db->execute($sql, $where[1]);
        return $stmt->rowCount();
    }

    public function increment(string $column, int $by = 1): int
    {
        $where = $this->buildWhere();
        $sql   = "UPDATE `{$this->table}` SET `{$column}` = `{$column}` + ?" . $where[0];
        $stmt  = $this->db->execute($sql, array_merge([$by], $where[1]));
        return $stmt->rowCount();
    }

    public function decrement(string $column, int $by = 1): int
    {
        return $this->increment($column, -$by);
    }

    // ─────────────────────────────────────────────
    // SQL building
    // ─────────────────────────────────────────────

    private function buildSelect(): array
    {
        $cols     = implode(', ', $this->selects);
        $sql      = "SELECT {$cols} FROM `{$this->table}`";
        [$w, $b]  = $this->buildWhere();
        $sql     .= $w;

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }
        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }
        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return [$sql, $b];
    }

    private function buildWhere(): array
    {
        if (empty($this->wheres)) {
            return ['', $this->bindings];
        }
        return [' WHERE ' . implode(' AND ', $this->wheres), $this->bindings];
    }

    // ─────────────────────────────────────────────
    // Hydration
    // ─────────────────────────────────────────────

    private function hydrateAll(array $rows): array
    {
        if (!$this->modelClass || !class_exists($this->modelClass)) {
            return $rows;
        }
        return array_map(fn($row) => $this->hydrateOne($row), $rows);
    }

    private function hydrateOne(object $row): object
    {
        if (!$this->modelClass || !class_exists($this->modelClass)) {
            return $row;
        }
        /** @var Model $model */
        $model = new $this->modelClass();
        foreach ((array) $row as $key => $value) {
            $model->$key = $value;
        }
        $model->exists = true;
        return $model;
    }
}
