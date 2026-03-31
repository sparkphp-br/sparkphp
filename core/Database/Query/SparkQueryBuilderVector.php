<?php

trait SparkQueryBuilderVector
{
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
        $candidate = SparkVectorMath::parseVectorValue($stored);

        if ($candidate === null) {
            return null;
        }

        return SparkVectorMath::score($candidate, $query['vector'], $query['metric']);
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

        $clause = $this->vectorScoreSql($query) . ' >= ' . SparkVectorMath::formatFloat((float) $query['threshold']);

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

    private function vectorSqlLiteral(array $vector): string
    {
        $serialized = implode(', ', array_map(
            fn(float $value): string => SparkVectorMath::formatFloat($value),
            $vector
        ));

        return "'[" . $serialized . "]'::vector";
    }

    private function vectorPropertyName(string $column): string
    {
        $segments = explode('.', $column);

        return end($segments) ?: $column;
    }
}
