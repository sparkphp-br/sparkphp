<?php

trait SparkQueryBuilderClauses
{
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

    public function selectRaw(string $expression): static
    {
        $this->selects = [$expression];
        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addWhere($column, $operatorOrValue, $value, 'AND');
    }

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

    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) {
            $this->wheres[] = ['clause' => '1 = 0', 'connector' => 'AND'];
            return $this;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . " IN ({$placeholders})", 'connector' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        if (empty($values)) {
            return $this;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . " NOT IN ({$placeholders})", 'connector' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' IS NULL', 'connector' => 'AND'];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' IS NOT NULL', 'connector' => 'AND'];
        return $this;
    }

    public function whereBetween(string $column, array $range): static
    {
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' BETWEEN ? AND ?', 'connector' => 'AND'];
        $this->bindings[] = $range[0];
        $this->bindings[] = $range[1];
        return $this;
    }

    public function whereNotBetween(string $column, array $range): static
    {
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' NOT BETWEEN ? AND ?', 'connector' => 'AND'];
        $this->bindings[] = $range[0];
        $this->bindings[] = $range[1];
        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' LIKE ?', 'connector' => 'AND'];
        $this->bindings[] = $pattern;
        return $this;
    }

    public function orWhereLike(string $column, string $pattern): static
    {
        $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' LIKE ?', 'connector' => 'OR'];
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

    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->wheres[] = ['clause' => $expression, 'connector' => 'AND'];
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

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

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = 'INNER JOIN ' . $this->wrapTable($table) . " ON {$first} {$operator} {$second}";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = 'LEFT JOIN ' . $this->wrapTable($table) . " ON {$first} {$operator} {$second}";
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = 'RIGHT JOIN ' . $this->wrapTable($table) . " ON {$first} {$operator} {$second}";
        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier($c), $columns));
        $this->groupByClause = $cols;
        return $this;
    }

    public function having(string $column, string $operator, mixed $value): static
    {
        $this->havings[] = $this->wrapIdentifier($column) . " {$operator} ?";
        $this->havingBindings[] = $value;
        return $this;
    }

    public function havingRaw(string $expression, array $bindings = []): static
    {
        $this->havings[] = $expression;
        $this->havingBindings = array_merge($this->havingBindings, $bindings);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $col = $this->wrapIdentifier($column);
        $this->orderBy = "{$col} " . strtoupper($direction);
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
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

    private function addWhere(string $column, mixed $operatorOrValue, mixed $value, string $connector): static
    {
        if ($value === null) {
            $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . ' = ?', 'connector' => $connector];
            $this->bindings[] = $operatorOrValue;
        } else {
            $this->wheres[] = ['clause' => $this->wrapIdentifier($column) . " {$operatorOrValue} ?", 'connector' => $connector];
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
}
