<?php

trait SparkQueryBuilderRelations
{
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
}
