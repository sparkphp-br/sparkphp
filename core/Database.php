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


// ─────────────────────────────────────────────────────────────────────────────
// Query Builder
// ─────────────────────────────────────────────────────────────────────────────

class QueryBuilder
{
    private Database $db;
    private string $table;

    /** @var array<int, array{clause: string, connector: string}> */
    private array $wheres     = [];
    private array $bindings   = [];
    private ?string $orderBy  = null;
    private ?int $limitVal    = null;
    private ?int $offsetVal   = null;
    private array $selects    = ['*'];
    private string $modelClass = '';

    /** @var array<int, string> */
    private array $joins      = [];
    private array $joinBindings = [];
    private ?string $groupByClause = null;

    /** @var array<int, string> */
    private array $havings    = [];
    private array $havingBindings = [];

    /** @var array<string, array> Eager-load definitions: relation name => config */
    private array $eagerLoads = [];

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
    // Select clauses
    // ─────────────────────────────────────────────

    /**
     * Set columns to select.
     *
     * ```php
     * db('users')->select(['id', 'name'])->get();
     * db('users')->select('id, name')->get();
     * ```
     */
    public function select(array|string $columns): static
    {
        $this->selects = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Set a raw select expression.
     *
     * ```php
     * db('orders')->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id')->get();
     * ```
     */
    public function selectRaw(string $expression): static
    {
        $this->selects = [$expression];
        return $this;
    }

    // ─────────────────────────────────────────────
    // Where clauses
    // ─────────────────────────────────────────────

    /**
     * Add a WHERE clause (AND connector).
     *
     * ```php
     * db('users')->where('active', true)->get();
     * db('users')->where('age', '>', 18)->get();
     * ```
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addWhere($column, $operatorOrValue, $value, 'AND');
    }

    /**
     * Add a WHERE clause with OR connector.
     *
     * ```php
     * db('users')->where('name', 'João')->orWhere('name', 'Maria')->get();
     * ```
     */
    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addWhere($column, $operatorOrValue, $value, 'OR');
    }

    /**
     * Add a WHERE IN clause.
     *
     * ```php
     * db('users')->whereIn('role', ['admin', 'editor'])->get();
     * ```
     */
    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) {
            // Empty IN always returns false — add an impossible condition
            $this->wheres[] = ['clause' => '1 = 0', 'connector' => 'AND'];
            return $this;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = ['clause' => "`{$column}` IN ({$placeholders})", 'connector' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * ```php
     * db('users')->whereNotIn('status', ['banned', 'suspended'])->get();
     * ```
     */
    public function whereNotIn(string $column, array $values): static
    {
        if (empty($values)) {
            return $this; // NOT IN empty set = always true, no filter needed
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = ['clause' => "`{$column}` NOT IN ({$placeholders})", 'connector' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    /**
     * Add a WHERE column IS NULL clause.
     *
     * ```php
     * db('users')->whereNull('deleted_at')->get();
     * ```
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = ['clause' => "`{$column}` IS NULL", 'connector' => 'AND'];
        return $this;
    }

    /**
     * Add a WHERE column IS NOT NULL clause.
     *
     * ```php
     * db('users')->whereNotNull('email_verified_at')->get();
     * ```
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['clause' => "`{$column}` IS NOT NULL", 'connector' => 'AND'];
        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause for ranges.
     *
     * ```php
     * db('orders')->whereBetween('created_at', ['2026-01-01', '2026-03-31'])->get();
     * db('products')->whereBetween('price', [10, 100])->get();
     * ```
     */
    public function whereBetween(string $column, array $range): static
    {
        $this->wheres[]   = ['clause' => "`{$column}` BETWEEN ? AND ?", 'connector' => 'AND'];
        $this->bindings[] = $range[0];
        $this->bindings[] = $range[1];
        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause.
     *
     * ```php
     * db('products')->whereNotBetween('price', [100, 500])->get();
     * ```
     */
    public function whereNotBetween(string $column, array $range): static
    {
        $this->wheres[]   = ['clause' => "`{$column}` NOT BETWEEN ? AND ?", 'connector' => 'AND'];
        $this->bindings[] = $range[0];
        $this->bindings[] = $range[1];
        return $this;
    }

    /**
     * Add a WHERE LIKE clause for pattern matching.
     *
     * ```php
     * db('users')->whereLike('name', '%silva%')->get();
     * db('users')->whereLike('email', '%@gmail.com')->get();
     * ```
     */
    public function whereLike(string $column, string $pattern): static
    {
        $this->wheres[]   = ['clause' => "`{$column}` LIKE ?", 'connector' => 'AND'];
        $this->bindings[] = $pattern;
        return $this;
    }

    /**
     * Add a raw WHERE clause.
     *
     * ```php
     * db('users')->whereRaw('YEAR(created_at) = ?', [2026])->get();
     * ```
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->wheres[] = ['clause' => $expression, 'connector' => 'AND'];
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

    // ─────────────────────────────────────────────
    // Conditional clauses
    // ─────────────────────────────────────────────

    /**
     * Apply a callback when a condition is truthy.
     * Avoids manual if/else around query building.
     *
     * ```php
     * db('users')
     *     ->when($role, fn($q) => $q->where('role', $role))
     *     ->when($search, fn($q) => $q->whereLike('name', "%{$search}%"))
     *     ->get();
     * ```
     */
    public function when(mixed $condition, callable $callback, ?callable $fallback = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($fallback) {
            $fallback($this, $condition);
        }
        return $this;
    }

    // ─────────────────────────────────────────────
    // Joins
    // ─────────────────────────────────────────────

    /**
     * Add an INNER JOIN clause.
     *
     * ```php
     * db('orders')
     *     ->join('users', 'orders.user_id', '=', 'users.id')
     *     ->select(['orders.*', 'users.name as user_name'])
     *     ->get();
     * ```
     */
    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "INNER JOIN `{$table}` ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Add a LEFT JOIN clause.
     *
     * ```php
     * db('users')
     *     ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
     *     ->select(['users.*', 'COUNT(orders.id) as order_count'])
     *     ->groupBy('users.id')
     *     ->get();
     * ```
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "LEFT JOIN `{$table}` ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Add a RIGHT JOIN clause.
     *
     * ```php
     * db('orders')
     *     ->rightJoin('users', 'orders.user_id', '=', 'users.id')
     *     ->get();
     * ```
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "RIGHT JOIN `{$table}` ON {$first} {$operator} {$second}";
        return $this;
    }

    // ─────────────────────────────────────────────
    // Grouping & Having
    // ─────────────────────────────────────────────

    /**
     * Add a GROUP BY clause.
     *
     * ```php
     * db('orders')
     *     ->selectRaw('user_id, COUNT(*) as total')
     *     ->groupBy('user_id')
     *     ->get();
     * ```
     */
    public function groupBy(string ...$columns): static
    {
        $cols = implode(', ', array_map(fn($c) => str_contains($c, '.') ? $c : "`{$c}`", $columns));
        $this->groupByClause = $cols;
        return $this;
    }

    /**
     * Add a HAVING clause (used with GROUP BY).
     *
     * ```php
     * db('orders')
     *     ->selectRaw('user_id, COUNT(*) as total')
     *     ->groupBy('user_id')
     *     ->having('total', '>', 5)
     *     ->get();
     * ```
     */
    public function having(string $column, string $operator, mixed $value): static
    {
        $this->havings[]        = "`{$column}` {$operator} ?";
        $this->havingBindings[] = $value;
        return $this;
    }

    /**
     * Add a raw HAVING clause.
     *
     * ```php
     * db('orders')
     *     ->selectRaw('user_id, SUM(total) as revenue')
     *     ->groupBy('user_id')
     *     ->havingRaw('SUM(total) > ?', [1000])
     *     ->get();
     * ```
     */
    public function havingRaw(string $expression, array $bindings = []): static
    {
        $this->havings[]    = $expression;
        $this->havingBindings = array_merge($this->havingBindings, $bindings);
        return $this;
    }

    // ─────────────────────────────────────────────
    // Ordering
    // ─────────────────────────────────────────────

    /**
     * Set the ORDER BY clause.
     *
     * ```php
     * db('users')->orderBy('name')->get();
     * db('users')->orderBy('created_at', 'DESC')->get();
     * ```
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $col = str_contains($column, '.') ? $column : "`{$column}`";
        $this->orderBy = "{$col} " . strtoupper($direction);
        return $this;
    }

    /**
     * Order by column descending.
     *
     * ```php
     * db('users')->orderByDesc('created_at')->get();
     * ```
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by created_at descending (most recent first).
     *
     * ```php
     * db('posts')->latest()->get();
     * ```
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by created_at ascending (oldest first).
     *
     * ```php
     * db('posts')->oldest()->get();
     * ```
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Set a LIMIT on the query.
     *
     * ```php
     * db('users')->limit(10)->get();
     * ```
     */
    public function limit(int $limit): static
    {
        $this->limitVal = $limit;
        return $this;
    }

    /**
     * Set an OFFSET on the query.
     *
     * ```php
     * db('users')->limit(10)->offset(20)->get();
     * ```
     */
    public function offset(int $offset): static
    {
        $this->offsetVal = $offset;
        return $this;
    }

    // ─────────────────────────────────────────────
    // Fetch
    // ─────────────────────────────────────────────

    /**
     * Execute the query and return all results.
     *
     * ```php
     * $users = db('users')->where('active', true)->get();
     * ```
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->db->execute($sql, $bindings);
        $rows = $stmt->fetchAll();
        $results = $this->hydrateAll($rows);

        // Eager load relationships
        if (!empty($this->eagerLoads) && !empty($results) && $this->modelClass) {
            $results = $this->loadEagerRelations($results);
        }

        return $results;
    }

    /**
     * Get the first result or null.
     *
     * ```php
     * $user = db('users')->where('email', 'j@mail.com')->first();
     * ```
     */
    public function first(): mixed
    {
        $this->limitVal = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Find a record by primary key.
     *
     * ```php
     * $user = db('users')->find(1);
     * ```
     */
    public function find(int|string $id): mixed
    {
        return $this->where('id', $id)->first();
    }

    /**
     * Get all records (alias for get without conditions).
     *
     * ```php
     * $users = db('users')->all();
     * ```
     */
    public function all(): array
    {
        return $this->get();
    }

    /**
     * Get the value of a single column from the first result.
     *
     * ```php
     * $name = db('users')->where('id', 1)->value('name');
     * // 'João'
     * ```
     */
    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        if ($row === null) {
            return null;
        }
        return is_object($row) ? ($row->$column ?? null) : null;
    }

    /**
     * Get an array of values from a single column.
     *
     * ```php
     * $emails = db('users')->where('active', true)->pluck('email');
     * // ['a@mail.com', 'b@mail.com', ...]
     *
     * $roleMap = db('users')->pluck('name', 'id');
     * // [1 => 'João', 2 => 'Maria', ...]
     * ```
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $cols = $key ? [$column, $key] : [$column];
        $rows = $this->select($cols)->get();

        if ($key !== null) {
            $result = [];
            foreach ($rows as $row) {
                $row = (object) $row;
                $result[$row->$key] = $row->$column;
            }
            return $result;
        }

        return array_map(fn($row) => ((object) $row)->$column, $rows);
    }

    /**
     * Process results in chunks to handle large datasets efficiently.
     *
     * ```php
     * db('users')->chunk(100, function(array $users) {
     *     foreach ($users as $user) {
     *         // process in batches of 100
     *     }
     * });
     * ```
     *
     * @return bool Returns false if callback returns false (early stop).
     */
    public function chunk(int $size, callable $callback): bool
    {
        $page = 1;

        do {
            $results = $this->limit($size)->offset(($page - 1) * $size)->get();

            if (empty($results)) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while (count($results) === $size);

        return true;
    }

    // ─────────────────────────────────────────────
    // Aggregates
    // ─────────────────────────────────────────────

    /**
     * Count the number of matching records.
     *
     * ```php
     * $total = db('users')->where('active', true)->count();
     * ```
     */
    public function count(): int
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = "SELECT COUNT(*) as cnt FROM `{$this->table}`" . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get the sum of a column's values.
     *
     * ```php
     * $revenue = db('orders')->sum('total');
     * ```
     */
    public function sum(string $column): float
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = "SELECT SUM(`{$column}`) FROM `{$this->table}`" . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * Get the average of a column's values.
     *
     * ```php
     * $avgAge = db('users')->avg('age');
     * $avgPrice = db('products')->where('category', 'electronics')->avg('price');
     * ```
     */
    public function avg(string $column): float
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = "SELECT AVG(`{$column}`) FROM `{$this->table}`" . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * Get the maximum value of a column.
     *
     * ```php
     * $highest = db('products')->max('price');
     * ```
     */
    public function max(string $column): mixed
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = "SELECT MAX(`{$column}`) FROM `{$this->table}`" . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return $stmt->fetchColumn();
    }

    /**
     * Get the minimum value of a column.
     *
     * ```php
     * $lowest = db('products')->min('price');
     * ```
     */
    public function min(string $column): mixed
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = "SELECT MIN(`{$column}`) FROM `{$this->table}`" . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return $stmt->fetchColumn();
    }

    /**
     * Check if any matching records exist.
     *
     * ```php
     * if (db('users')->where('email', $email)->exists()) { ... }
     * ```
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if no matching records exist.
     *
     * ```php
     * if (db('users')->where('email', $email)->doesntExist()) { ... }
     * ```
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // ─────────────────────────────────────────────
    // Pagination
    // ─────────────────────────────────────────────

    /**
     * Paginate results with metadata.
     *
     * ```php
     * $page = db('users')->paginate(15);
     * // $page->data, $page->total, $page->current_page, $page->last_page, ...
     * ```
     */
    public function paginate(int $perPage = 15): object
    {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $total   = $this->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return (object) [
            'data'        => $items,
            'total'       => $total,
            'per_page'    => $perPage,
            'current_page'=> $page,
            'last_page'   => $lastPage,
            'from'        => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
            'to'          => min($page * $perPage, $total),
        ];
    }

    // ─────────────────────────────────────────────
    // Eager Loading
    // ─────────────────────────────────────────────

    /**
     * Eager-load relationships for the query results.
     * Requires models with defined relationship methods.
     *
     * ```php
     * User::query()->with('orders')->get();
     * User::query()->with('orders', 'profile')->get();
     * ```
     */
    public function with(string ...$relations): static
    {
        foreach ($relations as $relation) {
            $this->eagerLoads[$relation] = [];
        }
        return $this;
    }

    // ─────────────────────────────────────────────
    // Mutate
    // ─────────────────────────────────────────────

    /**
     * Insert a record and return the created row.
     *
     * ```php
     * $user = db('users')->create(['name' => 'João', 'email' => 'j@mail.com']);
     * ```
     */
    public function create(array $data): mixed
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT INTO `{$this->table}` ({$cols}) VALUES ({$phs})";

        $this->db->execute($sql, array_values($data));
        $id = $this->db->lastInsertId();

        return $this->find((int) $id) ?? (object) array_merge(['id' => (int) $id], $data);
    }

    /**
     * Insert a single record (returns true on success).
     *
     * ```php
     * db('logs')->insert(['action' => 'login', 'user_id' => 1]);
     * ```
     */
    public function insert(array $data): bool
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT INTO `{$this->table}` ({$cols}) VALUES ({$phs})";
        $this->db->execute($sql, array_values($data));
        return true;
    }

    /**
     * Insert multiple records in a single query.
     *
     * ```php
     * db('logs')->insertMany([
     *     ['action' => 'login', 'user_id' => 1],
     *     ['action' => 'login', 'user_id' => 2],
     *     ['action' => 'logout', 'user_id' => 3],
     * ]);
     * ```
     */
    public function insertMany(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $columns  = array_keys($rows[0]);
        $cols     = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $singlePh = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPh    = implode(', ', array_fill(0, count($rows), $singlePh));
        $sql      = "INSERT INTO `{$this->table}` ({$cols}) VALUES {$allPh}";

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        $this->db->execute($sql, $bindings);
        return true;
    }

    /**
     * Update matching records.
     *
     * ```php
     * db('users')->where('id', 1)->update(['name' => 'Novo Nome']);
     * ```
     */
    public function update(array $data): int
    {
        $set      = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $where    = $this->buildWhere();
        $sql      = "UPDATE `{$this->table}` SET {$set}" . $where[0];
        $bindings = array_merge(array_values($data), $where[1]);
        $stmt     = $this->db->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Update an existing record or create a new one.
     *
     * ```php
     * db('settings')->updateOrCreate(
     *     ['key' => 'theme'],        // search conditions
     *     ['value' => 'dark']        // values to set
     * );
     * ```
     */
    public function updateOrCreate(array $conditions, array $values): mixed
    {
        $query = new QueryBuilder($this->db, $this->table);
        $query->setModel($this->modelClass);
        foreach ($conditions as $col => $val) {
            $query->where($col, $val);
        }

        $existing = $query->first();

        if ($existing) {
            $updateQuery = new QueryBuilder($this->db, $this->table);
            foreach ($conditions as $col => $val) {
                $updateQuery->where($col, $val);
            }
            $updateQuery->update($values);

            // Re-fetch updated record
            $refetch = new QueryBuilder($this->db, $this->table);
            $refetch->setModel($this->modelClass);
            foreach ($conditions as $col => $val) {
                $refetch->where($col, $val);
            }
            return $refetch->first();
        }

        $insertQuery = new QueryBuilder($this->db, $this->table);
        $insertQuery->setModel($this->modelClass);
        return $insertQuery->create(array_merge($conditions, $values));
    }

    /**
     * Find the first record matching conditions, or create it.
     *
     * ```php
     * $user = db('users')->firstOrCreate(
     *     ['email' => 'j@mail.com'],
     *     ['name' => 'João']        // extra fields if creating
     * );
     * ```
     */
    public function firstOrCreate(array $conditions, array $values = []): mixed
    {
        $query = new QueryBuilder($this->db, $this->table);
        $query->setModel($this->modelClass);
        foreach ($conditions as $col => $val) {
            $query->where($col, $val);
        }

        $existing = $query->first();

        if ($existing) {
            return $existing;
        }

        $insertQuery = new QueryBuilder($this->db, $this->table);
        $insertQuery->setModel($this->modelClass);
        return $insertQuery->create(array_merge($conditions, $values));
    }

    /**
     * Delete matching records.
     *
     * ```php
     * db('sessions')->where('expired', true)->delete();
     * ```
     */
    public function delete(): int
    {
        $where = $this->buildWhere();
        $sql   = "DELETE FROM `{$this->table}`" . $where[0];
        $stmt  = $this->db->execute($sql, $where[1]);
        return $stmt->rowCount();
    }

    /**
     * Increment a column's value.
     *
     * ```php
     * db('products')->where('id', 1)->increment('views');
     * db('accounts')->where('id', 1)->increment('balance', 100);
     * ```
     */
    public function increment(string $column, int $by = 1): int
    {
        $where = $this->buildWhere();
        $sql   = "UPDATE `{$this->table}` SET `{$column}` = `{$column}` + ?" . $where[0];
        $stmt  = $this->db->execute($sql, array_merge([$by], $where[1]));
        return $stmt->rowCount();
    }

    /**
     * Decrement a column's value.
     *
     * ```php
     * db('accounts')->where('id', 1)->decrement('balance', 50);
     * ```
     */
    public function decrement(string $column, int $by = 1): int
    {
        return $this->increment($column, -$by);
    }

    // ─────────────────────────────────────────────
    // Debugging
    // ─────────────────────────────────────────────

    /**
     * Get the SQL string that would be executed without running it.
     * Useful for debugging complex queries.
     *
     * ```php
     * $sql = db('users')->where('active', true)->toSql();
     * // "SELECT * FROM `users` WHERE `active` = ?"
     * ```
     */
    public function toSql(): string
    {
        [$sql] = $this->buildSelect();
        return $sql;
    }

    /**
     * Get both the SQL string and its bindings.
     *
     * ```php
     * [$sql, $bindings] = db('users')->where('active', true)->toRawSql();
     * ```
     */
    public function toRawSql(): array
    {
        return $this->buildSelect();
    }

    // ─────────────────────────────────────────────
    // SQL building
    // ─────────────────────────────────────────────

    private function buildSelect(): array
    {
        $cols     = implode(', ', $this->selects);
        $sql      = "SELECT {$cols} FROM `{$this->table}`";

        // Joins
        $sql .= $this->buildJoinString();

        // Where
        [$w, $b]  = $this->buildWhere();
        $sql     .= $w;

        // Group By
        if ($this->groupByClause) {
            $sql .= " GROUP BY {$this->groupByClause}";
        }

        // Having
        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
            $b = array_merge($b, $this->havingBindings);
        }

        // Order By
        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        // Limit / Offset
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

        $sql = '';
        foreach ($this->wheres as $i => $where) {
            if ($i === 0) {
                $sql .= $where['clause'];
            } else {
                $sql .= " {$where['connector']} {$where['clause']}";
            }
        }

        return [' WHERE ' . $sql, $this->bindings];
    }

    private function buildWhereWithJoins(): array
    {
        [$w, $b] = $this->buildWhere();
        return [$w, array_merge($this->joinBindings, $b)];
    }

    private function buildJoinString(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        return ' ' . implode(' ', $this->joins);
    }

    private function addWhere(string $column, mixed $operatorOrValue, mixed $value, string $connector): static
    {
        if ($value === null) {
            $this->wheres[]   = ['clause' => "`{$column}` = ?", 'connector' => $connector];
            $this->bindings[] = $operatorOrValue;
        } else {
            $this->wheres[]   = ['clause' => "`{$column}` {$operatorOrValue} ?", 'connector' => $connector];
            $this->bindings[] = $value;
        }
        return $this;
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
        $model->syncOriginal();
        return $model;
    }

    // ─────────────────────────────────────────────
    // Eager Loading (internal)
    // ─────────────────────────────────────────────

    /**
     * Load eager relations onto a collection of hydrated models.
     */
    private function loadEagerRelations(array $models): array
    {
        foreach ($this->eagerLoads as $relation => $config) {
            if (!method_exists($models[0], $relation)) {
                continue;
            }

            // Collect parent IDs and call the relation on each model
            foreach ($models as $model) {
                if ($model instanceof Model) {
                    $model->setRelation($relation, $model->$relation());
                }
            }
        }

        return $models;
    }
}
