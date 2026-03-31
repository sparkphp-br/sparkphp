<?php

trait SparkQueryBuilderReads
{
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

        if (!empty($this->eagerLoads) && !empty($results) && $this->modelClass) {
            $results = $this->loadEagerRelations($results);
        }

        if (!empty($this->withCounts) && !empty($results) && $this->modelClass) {
            $results = $this->loadRelationCounts($results);
        }

        return $results;
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

    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        if ($row === null) {
            return null;
        }

        return is_object($row) ? ($row->$column ?? null) : null;
    }

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

    public function sum(string $column): float
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = 'SELECT SUM(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    public function avg(string $column): float
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = 'SELECT AVG(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    public function max(string $column): mixed
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = 'SELECT MAX(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return $stmt->fetchColumn();
    }

    public function min(string $column): mixed
    {
        [$w, $b] = $this->buildWhereWithJoins();
        $sql = 'SELECT MIN(' . $this->wrapIdentifier($column) . ') FROM ' . $this->wrapTable($this->table) . $this->buildJoinString() . $w;
        $stmt = $this->db->execute($sql, $b);
        return $stmt->fetchColumn();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

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

    public function toSql(): string
    {
        [$sql] = $this->buildSelect();
        return $sql;
    }

    public function toRawSql(): array
    {
        return $this->buildSelect();
    }
}
