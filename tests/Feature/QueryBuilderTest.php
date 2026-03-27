<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Use in-memory SQLite for testing
        $_ENV['DB']      = 'sqlite';
        $_ENV['DB_NAME'] = ':memory:';

        // Reset singleton for each test
        Database::reset();
        $this->db = Database::getInstance();

        // Create test tables
        $this->db->pdo()->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                role VARCHAR(50) DEFAULT "user",
                age INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1,
                deleted_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $this->db->pdo()->exec('
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                total NUMERIC(10,2) DEFAULT 0,
                status VARCHAR(50) DEFAULT "pending",
                created_at TEXT
            )
        ');

        // Seed test data
        $this->db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('João', 'j@mail.com', 'admin', 30, 1)");
        $this->db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('Maria', 'm@mail.com', 'editor', 25, 1)");
        $this->db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('Pedro', 'p@mail.com', 'user', 40, 0)");
        $this->db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('Ana', 'a@mail.com', 'admin', 35, 1)");

        $this->db->pdo()->exec("INSERT INTO orders (user_id, total, status) VALUES (1, 100.50, 'completed')");
        $this->db->pdo()->exec("INSERT INTO orders (user_id, total, status) VALUES (1, 200.00, 'completed')");
        $this->db->pdo()->exec("INSERT INTO orders (user_id, total, status) VALUES (2, 50.00, 'pending')");
        $this->db->pdo()->exec("INSERT INTO orders (user_id, total, status) VALUES (3, 75.25, 'cancelled')");
    }

    // ─── orWhere ─────────────────────────────────────

    public function testOrWhereReturnsMatchingRecords(): void
    {
        $results = db('users')->where('name', 'João')->orWhere('name', 'Maria')->get();
        $this->assertCount(2, $results);
    }

    public function testOrWhereWithOperator(): void
    {
        $results = db('users')->where('age', '<', 26)->orWhere('age', '>', 35)->get();
        $this->assertCount(2, $results);
    }

    // ─── whereBetween ────────────────────────────────

    public function testWhereBetweenFiltersRange(): void
    {
        $results = db('users')->whereBetween('age', [25, 35])->get();
        $this->assertCount(3, $results);
    }

    public function testWhereNotBetweenFiltersOutsideRange(): void
    {
        $results = db('users')->whereNotBetween('age', [25, 35])->get();
        $this->assertCount(1, $results);
        $this->assertSame('Pedro', $results[0]->name);
    }

    // ─── whereLike ───────────────────────────────────

    public function testWhereLikeMatchesPattern(): void
    {
        $results = db('users')->whereLike('email', '%@mail.com')->get();
        $this->assertCount(4, $results);

        $results = db('users')->whereLike('name', 'J%')->get();
        $this->assertCount(1, $results);
        $this->assertSame('João', $results[0]->name);
    }

    // ─── whereNotIn ──────────────────────────────────

    public function testWhereNotInExcludesValues(): void
    {
        $results = db('users')->whereNotIn('role', ['admin'])->get();
        $this->assertCount(2, $results);
    }

    public function testWhereInWithEmptyArrayReturnsNothing(): void
    {
        $results = db('users')->whereIn('role', [])->get();
        $this->assertCount(0, $results);
    }

    public function testWhereNotInWithEmptyArrayReturnsAll(): void
    {
        $results = db('users')->whereNotIn('role', [])->get();
        $this->assertCount(4, $results);
    }

    // ─── whereRaw ────────────────────────────────────

    public function testWhereRawExecutesRawExpression(): void
    {
        $results = db('users')->whereRaw('age > ? AND active = ?', [30, 1])->get();
        $this->assertCount(1, $results);
        $this->assertSame('Ana', $results[0]->name);
    }

    // ─── when (conditional) ──────────────────────────

    public function testWhenAppliesCallbackOnTruthyCondition(): void
    {
        $role = 'admin';
        $results = db('users')->when($role, fn($q) => $q->where('role', $role))->get();
        $this->assertCount(2, $results);
    }

    public function testWhenSkipsCallbackOnFalsyCondition(): void
    {
        $role = '';
        $results = db('users')->when($role, fn($q) => $q->where('role', 'nonexistent'))->get();
        $this->assertCount(4, $results); // no filter applied
    }

    public function testWhenAppliesFallbackOnFalsyCondition(): void
    {
        $role = null;
        $results = db('users')
            ->when(
                $role,
                fn($q) => $q->where('role', 'admin'),
                fn($q) => $q->where('active', 1)
            )
            ->get();
        $this->assertCount(3, $results); // fallback: only active
    }

    // ─── join / leftJoin / rightJoin ─────────────────

    public function testJoinCombinesTables(): void
    {
        $results = db('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select(['orders.*', 'users.name as user_name'])
            ->get();

        $this->assertCount(4, $results);
        $this->assertObjectHasProperty('user_name', $results[0]);
    }

    public function testLeftJoinIncludesAllFromLeft(): void
    {
        $results = db('users')
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->select(['users.name', 'orders.total'])
            ->get();

        $this->assertTrue(count($results) >= 4);
    }

    // ─── groupBy / having ────────────────────────────

    public function testGroupByAggregatesResults(): void
    {
        $results = db('orders')
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->get();

        $this->assertCount(3, $results);
    }

    public function testHavingFiltersGroups(): void
    {
        // Note: SQLite has a known limitation with bound params in HAVING.
        // We inline the value to validate the HAVING clause generation works.
        $results = db('orders')
            ->selectRaw('user_id, SUM(total) as revenue')
            ->groupBy('user_id')
            ->havingRaw('SUM(total) > 100')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->user_id);
    }

    // ─── selectRaw ───────────────────────────────────

    public function testSelectRawAllowsRawExpressions(): void
    {
        $results = db('users')->selectRaw('COUNT(*) as total')->get();
        $this->assertSame(4, (int) $results[0]->total);
    }

    // ─── pluck ───────────────────────────────────────

    public function testPluckReturnsArrayOfValues(): void
    {
        $names = db('users')->pluck('name');
        $this->assertCount(4, $names);
        $this->assertContains('João', $names);
        $this->assertContains('Maria', $names);
    }

    public function testPluckWithKeyReturnsKeyedArray(): void
    {
        $names = db('users')->pluck('name', 'id');
        $this->assertSame('João', $names[1]);
        $this->assertSame('Maria', $names[2]);
    }

    // ─── value ───────────────────────────────────────

    public function testValueReturnsSingleColumnValue(): void
    {
        $name = db('users')->where('id', 1)->value('name');
        $this->assertSame('João', $name);
    }

    public function testValueReturnsNullWhenNotFound(): void
    {
        $name = db('users')->where('id', 999)->value('name');
        $this->assertNull($name);
    }

    // ─── chunk ───────────────────────────────────────

    public function testChunkProcessesInBatches(): void
    {
        $chunks = [];
        db('users')->orderBy('id')->chunk(2, function ($users, $page) use (&$chunks) {
            $chunks[$page] = count($users);
        });

        $this->assertCount(2, $chunks);
        $this->assertSame(2, $chunks[1]);
        $this->assertSame(2, $chunks[2]);
    }

    public function testChunkStopsEarlyWhenCallbackReturnsFalse(): void
    {
        $processed = 0;
        $result = db('users')->chunk(2, function ($users) use (&$processed) {
            $processed++;
            return false; // stop after first chunk
        });

        $this->assertFalse($result);
        $this->assertSame(1, $processed);
    }

    // ─── insertMany ──────────────────────────────────

    public function testInsertManyInsertsMultipleRows(): void
    {
        $before = db('users')->count();

        db('users')->insertMany([
            ['name' => 'Test1', 'email' => 't1@mail.com', 'role' => 'user', 'age' => 20, 'active' => 1],
            ['name' => 'Test2', 'email' => 't2@mail.com', 'role' => 'user', 'age' => 21, 'active' => 1],
            ['name' => 'Test3', 'email' => 't3@mail.com', 'role' => 'user', 'age' => 22, 'active' => 1],
        ]);

        $this->assertSame($before + 3, db('users')->count());
    }

    public function testInsertManyWithEmptyArrayDoesNothing(): void
    {
        $before = db('users')->count();
        db('users')->insertMany([]);
        $this->assertSame($before, db('users')->count());
    }

    // ─── updateOrCreate ──────────────────────────────

    public function testUpdateOrCreateCreatesNewRecord(): void
    {
        $before = db('users')->count();

        db('users')->updateOrCreate(
            ['email' => 'new@mail.com'],
            ['name' => 'NewUser', 'role' => 'user', 'age' => 18, 'active' => 1]
        );

        $this->assertSame($before + 1, db('users')->count());
        $user = db('users')->where('email', 'new@mail.com')->first();
        $this->assertSame('NewUser', $user->name);
    }

    public function testUpdateOrCreateUpdatesExistingRecord(): void
    {
        $before = db('users')->count();

        db('users')->updateOrCreate(
            ['email' => 'j@mail.com'],
            ['name' => 'João Updated']
        );

        $this->assertSame($before, db('users')->count()); // no new row
        $user = db('users')->where('email', 'j@mail.com')->first();
        $this->assertSame('João Updated', $user->name);
    }

    // ─── firstOrCreate ───────────────────────────────

    public function testFirstOrCreateFindsExistingRecord(): void
    {
        $before = db('users')->count();

        $user = db('users')->firstOrCreate(
            ['email' => 'j@mail.com'],
            ['name' => 'Should Not Appear']
        );

        $this->assertSame($before, db('users')->count());
        $this->assertSame('João', $user->name);
    }

    public function testFirstOrCreateCreatesWhenNotFound(): void
    {
        $before = db('users')->count();

        $user = db('users')->firstOrCreate(
            ['email' => 'brand.new@mail.com'],
            ['name' => 'Brand New', 'role' => 'user', 'age' => 19, 'active' => 1]
        );

        $this->assertSame($before + 1, db('users')->count());
        $this->assertSame('Brand New', $user->name);
    }

    // ─── avg ─────────────────────────────────────────

    public function testAvgCalculatesAverage(): void
    {
        $avg = db('users')->avg('age');
        $this->assertEqualsWithDelta(32.5, $avg, 0.1);
    }

    // ─── doesntExist ─────────────────────────────────

    public function testDoesntExistReturnsTrueWhenNoMatch(): void
    {
        $this->assertTrue(db('users')->where('name', 'NonExistent')->doesntExist());
    }

    public function testDoesntExistReturnsFalseWhenMatchExists(): void
    {
        $this->assertFalse(db('users')->where('name', 'João')->doesntExist());
    }

    // ─── orderByDesc / latest / oldest ───────────────

    public function testOrderByDescSortsDescending(): void
    {
        $users = db('users')->orderByDesc('age')->get();
        $this->assertSame('Pedro', $users[0]->name); // age 40
    }

    public function testLatestSortsByCreatedAtDesc(): void
    {
        $sql = db('users')->latest()->toSql();
        $this->assertStringContainsString('ORDER BY `created_at` DESC', $sql);
    }

    public function testOldestSortsByCreatedAtAsc(): void
    {
        $sql = db('users')->oldest()->toSql();
        $this->assertStringContainsString('ORDER BY `created_at` ASC', $sql);
    }

    // ─── toSql / toRawSql ────────────────────────────

    public function testToSqlReturnsQueryString(): void
    {
        $sql = db('users')->where('active', 1)->where('role', 'admin')->toSql();
        $this->assertStringContainsString('SELECT * FROM `users`', $sql);
        $this->assertStringContainsString('`active` = ?', $sql);
        $this->assertStringContainsString('AND', $sql);
    }

    public function testToRawSqlReturnsQueryAndBindings(): void
    {
        [$sql, $bindings] = db('users')->where('active', 1)->toRawSql();
        $this->assertStringContainsString('`active` = ?', $sql);
        $this->assertSame([1], $bindings);
    }

    public function testToSqlIncludesJoinsGroupByHaving(): void
    {
        $sql = db('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->selectRaw('users.name, COUNT(*) as total')
            ->groupBy('users.name')
            ->havingRaw('COUNT(*) > ?', [1])
            ->toSql();

        $this->assertStringContainsString('INNER JOIN', $sql);
        $this->assertStringContainsString('GROUP BY', $sql);
        $this->assertStringContainsString('HAVING', $sql);
    }

    // ─── Pagination edge case: empty table ───────────

    public function testPaginateHandlesEmptyTable(): void
    {
        $this->db->pdo()->exec('DELETE FROM orders');
        $page = db('orders')->paginate(10);

        $this->assertSame(0, $page->total);
        $this->assertSame(1, $page->last_page);
        $this->assertSame(0, $page->from);
    }
}
