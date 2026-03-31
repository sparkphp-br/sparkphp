<?php

trait SparkQueryBuilderSql
{
    private function buildSelect(bool $applyVectorSql = true, bool $applyLimitOffset = true, array $extraSelects = []): array
    {
        $selects = array_merge($this->selects, $extraSelects);

        if ($applyVectorSql && $this->shouldUseSqlVectorSearch() && $this->vectorSelect !== null) {
            $selects[] = $this->vectorScoreSql($this->vectorSelect) . ' AS ' . $this->wrapIdentifier($this->vectorSelect['alias']);
        }

        $cols = implode(', ', $selects);
        $sql = 'SELECT ' . $cols . ' FROM ' . $this->wrapTable($this->table);

        $sql .= $this->buildJoinString();

        [$w, $b] = $this->buildWhere();
        $sql .= $applyVectorSql && $this->shouldUseSqlVectorSearch() && $this->vectorSearch !== null
            ? $this->appendVectorWhereClause($w, $this->vectorSearch)
            : $w;

        if ($this->groupByClause) {
            $sql .= " GROUP BY {$this->groupByClause}";
        }

        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
            $b = array_merge($b, $this->havingBindings);
        }

        $orderBy = $this->orderBy;

        if ($applyVectorSql && $this->shouldUseSqlVectorSearch() && $this->vectorOrder !== null) {
            $vectorOrder = $this->vectorScoreSql($this->vectorOrder) . ' ' . $this->vectorOrder['direction'];
            $orderBy = $orderBy ? $vectorOrder . ', ' . $orderBy : $vectorOrder;
        }

        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }

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
