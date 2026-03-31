<?php

trait SparkQueryBuilderWrites
{
    public function create(array $data): mixed
    {
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c), array_keys($data)));
        $phs = implode(', ', array_fill(0, count($data), '?'));
        $sql = 'INSERT INTO ' . $this->wrapTable($this->table) . " ({$cols}) VALUES ({$phs})";

        $this->db->execute($sql, array_values($data));
        $id = $this->db->lastInsertId();

        return $this->find((int) $id) ?? (object) array_merge(['id' => (int) $id], $data);
    }

    public function insert(array $data): bool
    {
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c), array_keys($data)));
        $phs = implode(', ', array_fill(0, count($data), '?'));
        $sql = 'INSERT INTO ' . $this->wrapTable($this->table) . " ({$cols}) VALUES ({$phs})";
        $this->db->execute($sql, array_values($data));
        return true;
    }

    public function insertMany(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $columns = array_keys($rows[0]);
        $cols = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c), $columns));
        $singlePh = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPh = implode(', ', array_fill(0, count($rows), $singlePh));
        $sql = 'INSERT INTO ' . $this->wrapTable($this->table) . " ({$cols}) VALUES {$allPh}";

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        $this->db->execute($sql, $bindings);
        return true;
    }

    public function update(array $data): int
    {
        $set = implode(', ', array_map(fn($c) => $this->wrapIdentifier((string) $c) . ' = ?', array_keys($data)));
        $where = $this->buildWhere();
        $sql = 'UPDATE ' . $this->wrapTable($this->table) . " SET {$set}" . $where[0];
        $bindings = array_merge(array_values($data), $where[1]);
        $stmt = $this->db->execute($sql, $bindings);
        return $stmt->rowCount();
    }

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

    public function delete(): int
    {
        $where = $this->buildWhere();
        $sql = 'DELETE FROM ' . $this->wrapTable($this->table) . $where[0];
        $stmt = $this->db->execute($sql, $where[1]);
        return $stmt->rowCount();
    }

    public function increment(string $column, int $by = 1): int
    {
        $where = $this->buildWhere();
        $wrapped = $this->wrapIdentifier($column);
        $sql = 'UPDATE ' . $this->wrapTable($this->table) . " SET {$wrapped} = {$wrapped} + ?" . $where[0];
        $stmt = $this->db->execute($sql, array_merge([$by], $where[1]));
        return $stmt->rowCount();
    }

    public function decrement(string $column, int $by = 1): int
    {
        return $this->increment($column, -$by);
    }
}
