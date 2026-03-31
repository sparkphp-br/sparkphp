<?php

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

    /** @var array<string, array{constraint: callable|null, nested: array}> */
    private array $eagerLoads = [];

    /** @var array<string, callable|null> */
    private array $withCounts = [];
    private ?array $vectorSearch = null;
    private ?array $vectorSelect = null;
    private ?array $vectorOrder = null;

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
    public function select(array|string ...$columns): static
    {
        if (count($columns) === 1 && is_array($columns[0])) {
            $this->selects = $columns[0];
            return $this;
        }

        $normalized = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $normalized = array_merge($normalized, $column);
                continue;
            }

            $normalized[] = $column;
        }

        $this->selects = $normalized;
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

    public function whereColumn(string $first, mixed $operatorOrSecond, ?string $second = null): static
    {
        return $this->addColumnWhere($first, $operatorOrSecond, $second, 'AND');
    }

    public function orWhereColumn(string $first, mixed $operatorOrSecond, ?string $second = null): static
    {
        return $this->addColumnWhere($first, $operatorOrSecond, $second, 'OR');
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
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . " IN ({$placeholders})", 'connector' => 'AND'];
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
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . " NOT IN ({$placeholders})", 'connector' => 'AND'];
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
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' IS NULL', 'connector' => 'AND'];
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
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' IS NOT NULL', 'connector' => 'AND'];
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
        $this->wheres[]   = ['clause' => $this->wrapIdentifier($column) . ' BETWEEN ? AND ?', 'connector' => 'AND'];
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
        $this->wheres[]   = ['clause' => $this->wrapIdentifier($column) . ' NOT BETWEEN ? AND ?', 'connector' => 'AND'];
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
        $this->wheres[]   = ['clause' => $this->wrapIdentifier($column) . ' LIKE ?', 'connector' => 'AND'];
        $this->bindings[] = $pattern;
        return $this;
    }

    public function orWhereLike(string $column, string $pattern): static
    {
        $this->wheres[]   = ['clause' => $this->wrapIdentifier($column) . ' LIKE ?', 'connector' => 'OR'];
        $this->bindings[] = $pattern;
        return $this;
    }

    public function whereDate(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addDateWhere($column, $operatorOrValue, $value, 'AND');
    }

    public function orWhereDate(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addDateWhere($column, $operatorOrValue, $value, 'OR');
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

    public function nearestTo(string $column, string|array $input, string $metric = 'cosine'): static
    {
        $query = $this->buildVectorQuery($column, $input, $metric);
        $query['threshold'] = null;
        $this->vectorSearch = $query;
        $this->vectorOrder ??= array_merge($query, ['direction' => 'DESC']);

        return $this;
    }

    public function whereVectorSimilarTo(
        string $column,
        string|array $input,
        ?float $threshold = null,
        string $metric = 'cosine'
    ): static {
        $query = $this->buildVectorQuery($column, $input, $metric);
        $query['threshold'] = $threshold;
        $this->vectorSearch = $query;
        $this->vectorOrder ??= array_merge($query, ['direction' => 'DESC']);

        return $this;
    }

    public function selectVectorSimilarity(
        string $column,
        string|array $input,
        string $alias = 'vector_score',
        string $metric = 'cosine'
    ): static {
        $this->vectorSelect = array_merge(
            $this->buildVectorQuery($column, $input, $metric),
            ['alias' => $alias]
        );

        return $this;
    }

    public function orderByVectorSimilarity(
        string $column,
        string|array $input,
        string $direction = 'DESC',
        string $metric = 'cosine'
    ): static {
        $this->vectorOrder = array_merge(
            $this->buildVectorQuery($column, $input, $metric),
            ['direction' => strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC']
        );

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

    public function unless(mixed $condition, callable $callback, ?callable $fallback = null): static
    {
        if (!$condition) {
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
        $this->joins[] = 'INNER JOIN ' . $this->wrapTable($table) . " ON {$first} {$operator} {$second}";
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
        $this->joins[] = 'LEFT JOIN ' . $this->wrapTable($table) . " ON {$first} {$operator} {$second}";
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
        $this->joins[] = 'RIGHT JOIN ' . $this->wrapTable($table) . " ON {$first} {$operator} {$second}";
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
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier($c), $columns));
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
        $this->havings[]        = $this->wrapIdentifier($column) . " {$operator} ?";
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
        $col = $this->wrapIdentifier($column);
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
        if ($this->needsVectorPostProcessing()) {
            $results = $this->hydrateAll($this->executeVectorPostProcessedRows());
        } else {
            [$sql, $bindings] = $this->buildSelect();
            $stmt = $this->db->execute($sql, $bindings);
            $rows = $stmt->fetchAll();
            $results = $this->hydrateAll($rows);
        }

        // Eager load relationships
        if (!empty($this->eagerLoads) && !empty($results) && $this->modelClass) {
            $results = $this->loadEagerRelations($results);
        }

        if (!empty($this->withCounts) && !empty($results) && $this->modelClass) {
            $results = $this->loadRelationCounts($results);
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
        if ($this->needsVectorPostProcessing() && (($this->vectorSearch['threshold'] ?? null) !== null)) {
            return count($this->executeVectorPostProcessedRows(false));
        }

        [$w, $b] = $this->buildWhereWithJoins();

        if ($this->shouldUseSqlVectorSearch() && (($this->vectorSearch['threshold'] ?? null) !== null)) {
            $w = $this->appendVectorWhereClause($w, $this->vectorSearch);
        }

        $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
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
        $sql = 'SELECT SUM(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
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
        $sql = 'SELECT AVG(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
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
        $sql = 'SELECT MAX(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
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
        $sql = 'SELECT MIN(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
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
    public function paginate(int $perPage = 15, ?int $page = null): SparkPaginator
    {
        $page = max(1, $page ?? (int) ($_GET['page'] ?? 1));
        $total = $this->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $itemCount = count($items);
        $from = $itemCount > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to = $itemCount > 0 ? $from + $itemCount - 1 : 0;

        return new SparkPaginator(
            data: $items,
            total: $total,
            per_page: $perPage,
            current_page: $page,
            last_page: $lastPage,
            from: $from,
            to: $to,
            links: $this->paginationLinks($page, $lastPage),
            meta: [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
            ],
        );
    }

    private function paginationLinks(int $page, int $lastPage): array
    {
        $path = sparkRequestScheme() . '://' . sparkRequestHost() . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $query = $_GET ?? [];

        return [
            'self' => $this->paginationUrl($path, $query, $page),
            'first' => $this->paginationUrl($path, $query, 1),
            'last' => $this->paginationUrl($path, $query, $lastPage),
            'prev' => $page > 1 ? $this->paginationUrl($path, $query, $page - 1) : null,
            'next' => $page < $lastPage ? $this->paginationUrl($path, $query, $page + 1) : null,
        ];
    }

    private function paginationUrl(string $path, array $query, int $page): string
    {
        $query['page'] = max(1, $page);
        $queryString = http_build_query($query);

        return $queryString === '' ? $path : $path . '?' . $queryString;
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
    public function with(array|string ...$relations): static
    {
        foreach ($relations as $entry) {
            foreach ($this->normalizeRelationDefinitions($entry) as [$relation, $constraint]) {
                $this->registerEagerLoad($relation, $constraint);
            }
        }

        return $this;
    }

    public function withCount(array|string ...$relations): static
    {
        foreach ($relations as $entry) {
            foreach ($this->normalizeRelationDefinitions($entry) as [$relation, $constraint]) {
                $this->withCounts[$relation] = $this->composeConstraint(
                    $this->withCounts[$relation] ?? null,
                    $constraint
                );
            }
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
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c), array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $sql  = 'INSERT INTO ' . $this->wrapTable($this->table) . " ({$cols}) VALUES ({$phs})";

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
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c), array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $sql  = 'INSERT INTO ' . $this->wrapTable($this->table) . " ({$cols}) VALUES ({$phs})";
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
        $cols     = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c), $columns));
        $singlePh = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPh    = implode(', ', array_fill(0, count($rows), $singlePh));
        $sql      = 'INSERT INTO ' . $this->wrapTable($this->table) . " ({$cols}) VALUES {$allPh}";

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
        $set      = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c) . ' = ?', array_keys($data)));
        $where    = $this->buildWhere();
        $sql      = 'UPDATE ' . $this->wrapTable($this->table) . " SET {$set}" . $where[0];
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
        $sql   = 'DELETE FROM ' . $this->wrapTable($this->table) . $where[0];
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
        $wrapped = $this->wrapIdentifier($column);
        $sql   = 'UPDATE ' . $this->wrapTable($this->table) . " SET {$wrapped} = {$wrapped} + ?" . $where[0];
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

    public function getEagerLoads(): array
    {
        return $this->eagerLoads;
    }

    public function eagerLoadModels(array $models, ?array $loads = null): array
    {
        if ($models === []) {
            return $models;
        }

        return $this->loadEagerRelations($models, $loads);
    }

    public function eagerLoadCounts(array $models, ?array $counts = null): array
    {
        if ($models === []) {
            return $models;
        }

        return $this->loadRelationCounts($models, $counts);
    }

    // ─────────────────────────────────────────────
    // SQL building
    // ─────────────────────────────────────────────

    private function buildSelect(bool $applyVectorSql = true, bool $applyLimitOffset = true, array $extraSelects = []): array
    {
        $selects = array_merge($this->selects, $extraSelects);

        if ($applyVectorSql && $this->shouldUseSqlVectorSearch() && $this->vectorSelect !== null) {
            $selects[] = $this->vectorScoreSql($this->vectorSelect) . ' AS ' . $this->wrapIdentifier($this->vectorSelect['alias']);
        }

        $cols = implode(', ', $selects);
        $sql = 'SELECT ' . $cols . ' FROM ' . $this->wrapTable($this->table);

        // Joins
        $sql .= $this->buildJoinString();

        // Where
        [$w, $b]  = $this->buildWhere();
        $sql     .= $applyVectorSql && $this->shouldUseSqlVectorSearch() && $this->vectorSearch !== null
            ? $this->appendVectorWhereClause($w, $this->vectorSearch)
            : $w;

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
        $orderBy = $this->orderBy;

        if ($applyVectorSql && $this->shouldUseSqlVectorSearch() && $this->vectorOrder !== null) {
            $vectorOrder = $this->vectorScoreSql($this->vectorOrder) . ' ' . $this->vectorOrder['direction'];
            $orderBy = $orderBy ? $vectorOrder . ', ' . $orderBy : $vectorOrder;
        }

        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }

        // Limit / Offset
        if ($applyLimitOffset && $this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }
        if ($applyLimitOffset && $this->offsetVal !== null) {
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
            $this->wheres[]   = ['clause' => $this->wrapIdentifier($column) . ' = ?', 'connector' => $connector];
            $this->bindings[] = $operatorOrValue;
        } else {
            $this->wheres[]   = ['clause' => $this->wrapIdentifier($column) . " {$operatorOrValue} ?", 'connector' => $connector];
            $this->bindings[] = $value;
        }
        return $this;
    }

    private function addColumnWhere(string $first, mixed $operatorOrSecond, ?string $second, string $connector): static
    {
        $operator = '=';
        $right = $second;

        if ($second === null) {
            $right = (string) $operatorOrSecond;
        } else {
            $operator = (string) $operatorOrSecond;
        }

        $this->wheres[] = [
            'clause' => $this->wrapIdentifier($first) . " {$operator} " . $this->wrapIdentifier((string) $right),
            'connector' => $connector,
        ];

        return $this;
    }

    private function addDateWhere(string $column, mixed $operatorOrValue, mixed $value, string $connector): static
    {
        $operator = '=';
        $binding = $operatorOrValue;

        if ($value !== null) {
            $operator = (string) $operatorOrValue;
            $binding = $value;
        }

        if ($binding instanceof \DateTimeInterface) {
            $binding = $binding->format('Y-m-d');
        }

        $this->wheres[] = [
            'clause' => 'DATE(' . $this->wrapIdentifier($column) . ") {$operator} ?",
            'connector' => $connector,
        ];
        $this->bindings[] = $binding;

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
     * Uses batch queries (single query per relation) to avoid N+1.
     */
    private function loadEagerRelations(array $models, ?array $loads = null): array
    {
        $loads ??= $this->eagerLoads;

        foreach ($loads as $relation => $config) {
            $relationInstance = $this->resolveRelationInstance($models[0], $relation);

            if (!$relationInstance instanceof Relation) {
                continue;
            }

            $relationInstance->addEagerConstraints($models);
            $this->configureRelationQuery($relationInstance, $config);

            if ($relationInstance instanceof BelongsToManyRelation) {
                $relationInstance->match($models, [], $relation);
            } else {
                $results = $relationInstance->get();
                $relationInstance->match($models, $results, $relation);
            }
        }

        return $models;
    }

    private function loadRelationCounts(array $models, ?array $counts = null): array
    {
        $counts ??= $this->withCounts;

        foreach ($counts as $relation => $constraint) {
            $relationInstance = $this->resolveRelationInstance($models[0], $relation);

            if (!$relationInstance instanceof Relation) {
                continue;
            }

            $tempRelation = '__spark_count_' . $relation;

            $relationInstance->addEagerConstraints($models);

            if ($constraint !== null) {
                $constraint($relationInstance->getQuery());
            }

            if ($relationInstance instanceof BelongsToManyRelation) {
                $relationInstance->match($models, [], $tempRelation);
            } else {
                $results = $relationInstance->get();
                $relationInstance->match($models, $results, $tempRelation);
            }

            foreach ($models as $model) {
                if (!$model instanceof Model) {
                    continue;
                }

                $loaded = $model->getRelation($tempRelation);

                $count = match (true) {
                    $loaded instanceof Model => 1,
                    is_array($loaded) => count($loaded),
                    $loaded === null => 0,
                    default => 1,
                };

                $model->setAttribute($relation . '_count', $count);
                $model->unsetRelation($tempRelation);
            }
        }

        return $models;
    }

    private function resolveRelationInstance(mixed $model, string $relation): ?Relation
    {
        if (!$model instanceof Model) {
            return null;
        }

        try {
            $resolved = $model->$relation();
        } catch (\BadMethodCallException) {
            return null;
        }

        return $resolved instanceof Relation ? $resolved : null;
    }

    private function configureRelationQuery(Relation $relationInstance, array $config): void
    {
        $query = $relationInstance->getQuery();

        if (($config['constraint'] ?? null) !== null) {
            $config['constraint']($query);
        }

        if (!empty($config['nested'])) {
            $query->with($this->flattenEagerLoadTree($config['nested']));
        }
    }

    private function normalizeRelationDefinitions(array|string $entry): array
    {
        if (is_string($entry)) {
            return [[$entry, null]];
        }

        $normalized = [];

        foreach ($entry as $key => $value) {
            if (is_int($key)) {
                $normalized[] = [(string) $value, null];
                continue;
            }

            $normalized[] = [(string) $key, is_callable($value) ? $value : null];
        }

        return $normalized;
    }

    private function registerEagerLoad(string $relation, ?callable $constraint = null): void
    {
        $segments = array_values(array_filter(explode('.', $relation), static fn(string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return;
        }

        $cursor =& $this->eagerLoads;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!isset($cursor[$segment])) {
                $cursor[$segment] = [
                    'constraint' => null,
                    'nested' => [],
                ];
            }

            if ($index === $lastIndex) {
                $cursor[$segment]['constraint'] = $this->composeConstraint($cursor[$segment]['constraint'], $constraint);
                return;
            }

            $cursor =& $cursor[$segment]['nested'];
        }
    }

    private function composeConstraint(?callable $existing, ?callable $incoming): ?callable
    {
        if ($existing === null) {
            return $incoming;
        }

        if ($incoming === null) {
            return $existing;
        }

        return static function (QueryBuilder $query) use ($existing, $incoming): void {
            $existing($query);
            $incoming($query);
        };
    }

    private function flattenEagerLoadTree(array $tree, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($tree as $relation => $config) {
            $path = $prefix === '' ? $relation : $prefix . '.' . $relation;

            if (($config['constraint'] ?? null) !== null) {
                $flattened[$path] = $config['constraint'];
            } else {
                $flattened[] = $path;
            }

            if (!empty($config['nested'])) {
                $flattened = array_merge($flattened, $this->flattenEagerLoadTree($config['nested'], $path));
            }
        }

        return $flattened;
    }

    private function executeVectorPostProcessedRows(bool $applySlice = true): array
    {
        $temporaryColumns = $this->vectorTemporaryColumns();
        [$sql, $bindings] = $this->buildSelect(
            applyVectorSql: false,
            applyLimitOffset: false,
            extraSelects: array_column($temporaryColumns, 'select')
        );

        $stmt = $this->db->execute($sql, $bindings);
        $rows = $stmt->fetchAll();

        $processed = [];

        foreach ($rows as $row) {
            $searchScore = $this->vectorSearch !== null
                ? $this->resolveRowVectorScore($row, $this->vectorSearch, $temporaryColumns)
                : null;

            if (($this->vectorSearch['threshold'] ?? null) !== null && ($searchScore === null || $searchScore < $this->vectorSearch['threshold'])) {
                continue;
            }

            if ($this->vectorSelect !== null) {
                $row->{$this->vectorSelect['alias']} = $this->resolveRowVectorScore($row, $this->vectorSelect, $temporaryColumns);
            }

            $processed[] = $row;
        }

        if ($this->vectorOrder !== null) {
            $direction = $this->vectorOrder['direction'];
            usort($processed, function (object $left, object $right) use ($temporaryColumns, $direction): int {
                $leftScore = $this->resolveRowVectorScore($left, $this->vectorOrder, $temporaryColumns) ?? -INF;
                $rightScore = $this->resolveRowVectorScore($right, $this->vectorOrder, $temporaryColumns) ?? -INF;

                $comparison = $leftScore <=> $rightScore;

                return $direction === 'ASC' ? $comparison : -$comparison;
            });
        }

        foreach ($processed as $row) {
            foreach ($temporaryColumns as $temporary) {
                unset($row->{$temporary['alias']});
            }
        }

        if (!$applySlice) {
            return $processed;
        }

        if ($this->offsetVal !== null || $this->limitVal !== null) {
            $processed = array_slice(
                $processed,
                $this->offsetVal ?? 0,
                $this->limitVal ?? null
            );
        }

        return $processed;
    }

    private function resolveRowVectorScore(object $row, array $query, array $temporaryColumns): ?float
    {
        $alias = $temporaryColumns[$query['column']]['alias'] ?? $this->vectorPropertyName($query['column']);
        $stored = $row->{$alias} ?? null;
        $candidate = $this->parseVectorValue($stored);

        if ($candidate === null) {
            return null;
        }

        return $this->vectorScore($candidate, $query['vector'], $query['metric']);
    }

    private function vectorTemporaryColumns(): array
    {
        $queries = array_filter([$this->vectorSearch, $this->vectorSelect, $this->vectorOrder]);
        $temporary = [];

        foreach ($queries as $query) {
            $column = $query['column'];
            if (isset($temporary[$column])) {
                continue;
            }

            $alias = '__spark_vector_' . count($temporary);
            $temporary[$column] = [
                'alias' => $alias,
                'select' => $this->wrapIdentifier($column) . ' AS ' . $this->wrapIdentifier($alias),
            ];
        }

        return $temporary;
    }

    private function shouldUseSqlVectorSearch(): bool
    {
        return $this->db->driver() === 'pgsql'
            && ($this->vectorSearch !== null || $this->vectorSelect !== null || $this->vectorOrder !== null);
    }

    private function needsVectorPostProcessing(): bool
    {
        return !$this->shouldUseSqlVectorSearch()
            && ($this->vectorSearch !== null || $this->vectorSelect !== null || $this->vectorOrder !== null);
    }

    private function appendVectorWhereClause(string $whereSql, array $query): string
    {
        if (($query['threshold'] ?? null) === null) {
            return $whereSql;
        }

        $clause = $this->vectorScoreSql($query) . ' >= ' . $this->formatVectorFloat((float) $query['threshold']);

        if ($whereSql === '') {
            return ' WHERE ' . $clause;
        }

        return $whereSql . ' AND ' . $clause;
    }

    private function vectorScoreSql(array $query): string
    {
        $column = $this->wrapIdentifier($query['column']);
        $vector = $this->vectorSqlLiteral($query['vector']);

        return match ($query['metric']) {
            'cosine' => '(1 - (' . $column . ' <=> ' . $vector . '))',
            'l2' => '(1 / (1 + (' . $column . ' <-> ' . $vector . ')))',
            'inner_product' => '(-(' . $column . ' <#> ' . $vector . '))',
            default => throw new RuntimeException('Unsupported vector metric [' . $query['metric'] . '].'),
        };
    }

    private function buildVectorQuery(string $column, string|array $input, string $metric): array
    {
        return [
            'column' => $column,
            'vector' => $this->normalizeVectorInput($input),
            'metric' => $this->normalizeVectorMetric($metric),
        ];
    }

    private function normalizeVectorInput(string|array $input): array
    {
        if (is_string($input)) {
            $vector = ai()->embeddings($input)->generate()->first();

            return array_map(static fn(mixed $value): float => (float) $value, $vector);
        }

        if ($input === []) {
            throw new RuntimeException('Vector queries require at least one dimension.');
        }

        foreach ($input as $value) {
            if (!is_numeric($value)) {
                throw new RuntimeException('Vector arrays must contain only numeric dimensions.');
            }
        }

        return array_map(static fn(mixed $value): float => (float) $value, array_values($input));
    }

    private function normalizeVectorMetric(string $metric): string
    {
        $normalized = strtolower(trim($metric));

        return match ($normalized) {
            'cosine', 'cos' => 'cosine',
            'l2', 'euclidean' => 'l2',
            'inner_product', 'inner-product', 'dot' => 'inner_product',
            default => throw new RuntimeException('Unsupported vector metric [' . $metric . '].'),
        };
    }

    private function parseVectorValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_map(static fn(mixed $dimension): float => (float) $dimension, array_values($value));
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return array_map(static fn(mixed $dimension): float => (float) $dimension, array_values($decoded));
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $trimmed = trim($trimmed, '[]');
        }

        $parts = array_filter(array_map('trim', explode(',', $trimmed)), static fn(string $part): bool => $part !== '');
        if ($parts === []) {
            return null;
        }

        return array_map(static fn(string $dimension): float => (float) $dimension, $parts);
    }

    private function vectorScore(array $candidate, array $query, string $metric): float
    {
        $length = min(count($candidate), count($query));

        if ($length === 0) {
            return 0.0;
        }

        $candidate = array_slice($candidate, 0, $length);
        $query = array_slice($query, 0, $length);

        return match ($metric) {
            'cosine' => $this->cosineSimilarity($candidate, $query),
            'l2' => 1 / (1 + $this->euclideanDistance($candidate, $query)),
            'inner_product' => $this->innerProduct($candidate, $query),
            default => 0.0,
        };
    }

    private function cosineSimilarity(array $candidate, array $query): float
    {
        $dot = $this->innerProduct($candidate, $query);
        $leftNorm = sqrt($this->innerProduct($candidate, $candidate));
        $rightNorm = sqrt($this->innerProduct($query, $query));

        if ($leftNorm == 0.0 || $rightNorm == 0.0) {
            return 0.0;
        }

        return $dot / ($leftNorm * $rightNorm);
    }

    private function euclideanDistance(array $candidate, array $query): float
    {
        $sum = 0.0;

        foreach ($candidate as $index => $value) {
            $delta = $value - $query[$index];
            $sum += $delta * $delta;
        }

        return sqrt($sum);
    }

    private function innerProduct(array $candidate, array $query): float
    {
        $sum = 0.0;

        foreach ($candidate as $index => $value) {
            $sum += $value * $query[$index];
        }

        return $sum;
    }

    private function vectorSqlLiteral(array $vector): string
    {
        $serialized = implode(', ', array_map(fn(float $value): string => $this->formatVectorFloat($value), $vector));

        return "'[" . $serialized . "]'::vector";
    }

    private function formatVectorFloat(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function vectorPropertyName(string $column): string
    {
        $segments = explode('.', $column);

        return end($segments) ?: $column;
    }

    private function wrapTable(string $table): string
    {
        if (str_contains($table, ' ') || str_contains($table, '(')) {
            return $table;
        }

        return $this->wrapIdentifier($table);
    }

    private function identifierQuote(): string
    {
        return $this->db->driver() === 'pgsql' ? '"' : '`';
    }

    private function wrapIdentifier(string $column): string
    {
        if ($column === '*' || str_contains($column, '(') || str_contains($column, ' ')) {
            return $column;
        }

        $segments = explode('.', $column);
        $quote = $this->identifierQuote();

        return implode('.', array_map(
            fn(string $segment) => $segment === '*' ? '*' : $quote . $segment . $quote,
            $segments
        ));
    }
}
