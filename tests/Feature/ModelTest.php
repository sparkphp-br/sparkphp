<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ─── Test models ─────────────────────────────────────────────────────────────

class TestUser extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name', 'email', 'role', 'age', 'active', 'password'];
    protected array $casts    = ['age' => 'int', 'active' => 'bool'];
    protected array $hidden   = ['password'];
    protected bool $timestamps = false;

    public function scopeActive($query): mixed
    {
        return $query->where('active', 1);
    }

    public function scopeAdmin($query): mixed
    {
        return $query->where('role', 'admin');
    }

    /**
     * Accessor: full_name
     */
    public function getFullNameAttribute(): string
    {
        return mb_strtoupper($this->getAttribute('name') ?? '');
    }

    /**
     * Mutator: password
     */
    public function setPasswordAttribute(?string $value): ?string
    {
        return $value !== null ? sha1($value) : null;
    }

    public function orders(): HasManyRelation
    {
        return $this->hasMany(TestOrder::class, 'user_id');
    }
}

class TestSoftUser extends Model
{
    protected string $table      = 'soft_users';
    protected array $fillable    = ['name', 'email'];
    protected bool $softDeletes  = true;
    protected bool $timestamps   = false;
}

class TestOrder extends Model
{
    protected string $table   = 'orders';
    protected array $fillable = ['user_id', 'total', 'status'];
    protected bool $timestamps = false;

    public function user(): BelongsToRelation
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
}

class TestProfile extends Model
{
    protected string $table   = 'profiles';
    protected array $fillable = ['user_id', 'bio'];
    protected bool $timestamps = false;
}

class TestRole extends Model
{
    protected string $table   = 'roles';
    protected array $fillable = ['name'];
    protected bool $timestamps = false;
}

class TestUserWithProfile extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name', 'email', 'role', 'age', 'active'];
    protected bool $timestamps = false;

    public function profile(): HasOneRelation
    {
        return $this->hasOne(TestProfile::class, 'user_id');
    }

    public function roles(): BelongsToManyRelation
    {
        return $this->belongsToMany(TestRole::class, 'role_user', 'user_id', 'role_id');
    }
}

// ─── Attribute-based test models ─────────────────────────────────────────────

#[HasMany(TestOrder::class, foreignKey: 'user_id')]
#[HasOne(TestProfile::class, foreignKey: 'user_id')]
#[BelongsToMany(TestRole::class, pivot: 'role_user', foreignPivotKey: 'user_id', relatedPivotKey: 'role_id')]
class TestAttrUser extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name', 'email', 'role', 'age', 'active'];
    protected bool $timestamps = false;
}

#[BelongsTo(TestUser::class, as: 'author', foreignKey: 'user_id')]
class TestAttrOrder extends Model
{
    protected string $table   = 'orders';
    protected array $fillable = ['user_id', 'total', 'status'];
    protected bool $timestamps = false;
}

// ─── Attribute-based scopes/accessors test models ────────────────────────────

#[Scope('published', column: 'active', value: 1)]
#[Scope('admins', column: 'role', value: 'admin')]
#[Scope('byRole', column: 'role')]
#[Scope('olderThan', column: 'age', op: '>', value: 25)]
class TestAttrScopedUser extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name', 'email', 'role', 'age', 'active'];
    protected bool $timestamps = false;
}

class TestAttrAccessorUser extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name', 'email', 'role', 'age', 'active', 'password'];
    protected array $hidden   = ['password'];
    protected bool $timestamps = false;

    #[Accessor]
    public function fullName(): string
    {
        return mb_strtoupper($this->getAttribute('name') ?? '');
    }

    #[Accessor('display_role')]
    public function computeRole(): string
    {
        return 'Role: ' . ($this->getAttribute('role') ?? '');
    }

    #[Mutator]
    public function password(string $value): string
    {
        return sha1($value);
    }
}

// ─── Test suite ──────────────────────────────────────────────────────────────

final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['DB']      = 'sqlite';
        $_ENV['DB_NAME'] = ':memory:';

        Database::reset();

        $db = Database::getInstance();

        $db->pdo()->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                role VARCHAR(50) DEFAULT "user",
                age INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1,
                password VARCHAR(255),
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->pdo()->exec('
            CREATE TABLE soft_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                deleted_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->pdo()->exec('
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                total NUMERIC(10,2) DEFAULT 0,
                status VARCHAR(50) DEFAULT "pending"
            )
        ');

        // Seed
        $db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('João', 'j@mail.com', 'admin', 30, 1)");
        $db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('Maria', 'm@mail.com', 'editor', 25, 1)");
        $db->pdo()->exec("INSERT INTO users (name, email, role, age, active) VALUES ('Pedro', 'p@mail.com', 'user', 40, 0)");

        $db->pdo()->exec("INSERT INTO soft_users (name, email) VALUES ('Alice', 'alice@mail.com')");
        $db->pdo()->exec("INSERT INTO soft_users (name, email) VALUES ('Bob', 'bob@mail.com')");

        $db->pdo()->exec("INSERT INTO orders (user_id, total, status) VALUES (1, 100.50, 'completed')");
        $db->pdo()->exec("INSERT INTO orders (user_id, total, status) VALUES (1, 200.00, 'pending')");

        $db->pdo()->exec('
            CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                bio TEXT
            )
        ');

        $db->pdo()->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255)
            )
        ');

        $db->pdo()->exec('
            CREATE TABLE role_user (
                user_id INTEGER,
                role_id INTEGER
            )
        ');

        $db->pdo()->exec("INSERT INTO profiles (user_id, bio) VALUES (1, 'Admin bio')");
        $db->pdo()->exec("INSERT INTO roles (name) VALUES ('admin')");
        $db->pdo()->exec("INSERT INTO roles (name) VALUES ('editor')");
        $db->pdo()->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");
        $db->pdo()->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 2)");
    }

    // ─── Basic operations ────────────────────────────

    public function testFindReturnsModel(): void
    {
        $user = TestUser::find(1);
        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertSame('João', $user->name);
        $this->assertTrue($user->exists);
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $user = TestUser::find(999);
        $this->assertNull($user);
    }

    public function testAllReturnsAllModels(): void
    {
        $users = TestUser::all();
        $this->assertCount(3, $users);
        $this->assertInstanceOf(TestUser::class, $users[0]);
    }

    public function testWhereReturnsQueryBuilder(): void
    {
        $admins = TestUser::where('role', 'admin')->get();
        $this->assertCount(1, $admins);
        $this->assertSame('João', $admins[0]->name);
    }

    public function testCreateInsertsNewRecord(): void
    {
        $user = TestUser::create(['name' => 'Test', 'email' => 'test@mail.com', 'age' => 20]);
        $this->assertTrue($user->exists);
        $this->assertSame('Test', $user->name);
        $this->assertNotNull($user->id);
    }

    public function testUpdateModificatesExistingRecord(): void
    {
        $user = TestUser::find(1);
        $user->update(['name' => 'João Updated']);

        $fresh = TestUser::find(1);
        $this->assertSame('João Updated', $fresh->name);
    }

    public function testDeleteRemovesRecord(): void
    {
        $user = TestUser::find(1);
        $user->delete();
        $this->assertNull(TestUser::find(1));
    }

    // ─── Scopes ──────────────────────────────────────

    public function testScopeFiltersResults(): void
    {
        $activeUsers = TestUser::active()->get();
        $this->assertCount(2, $activeUsers);
    }

    public function testScopesCanBeChained(): void
    {
        $activeAdmins = TestUser::active()->where('role', 'admin')->get();
        $this->assertCount(1, $activeAdmins);
        $this->assertSame('João', $activeAdmins[0]->name);
    }

    public function testUndefinedScopeThrowsException(): void
    {
        $this->expectException(BadMethodCallException::class);
        TestUser::nonExistentScope();
    }

    // ─── Accessors & Mutators ────────────────────────

    public function testAccessorTransformsValueOnRead(): void
    {
        $user = TestUser::find(1);
        $this->assertSame('JOÃO', $user->full_name);
    }

    public function testMutatorTransformsValueOnWrite(): void
    {
        $user = new TestUser();
        $user->password = 'secret123';

        // Password should be sha1 hashed by the mutator
        $this->assertSame(sha1('secret123'), $user->getAttribute('password'));
    }

    // ─── Hidden ──────────────────────────────────────

    public function testHiddenFieldsExcludedFromToArray(): void
    {
        $user = TestUser::create([
            'name' => 'Hidden Test',
            'email' => 'h@mail.com',
            'password' => 'secret',
        ]);

        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayHasKey('name', $array);
    }

    public function testHiddenFieldsExcludedFromToJson(): void
    {
        $user = TestUser::create([
            'name' => 'JSON Test',
            'email' => 'json@mail.com',
            'password' => 'secret',
        ]);

        $json = $user->toJson();
        $data = json_decode($json, true);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayHasKey('name', $data);
    }

    // ─── Casts ───────────────────────────────────────

    public function testCastsConvertTypes(): void
    {
        $user = TestUser::find(1);
        $this->assertIsInt($user->age);
        $this->assertIsBool($user->active);
    }

    // ─── Dirty tracking ──────────────────────────────

    public function testIsDirtyDetectsChanges(): void
    {
        $user = TestUser::find(1);
        $this->assertFalse($user->isDirty());

        $user->name = 'Changed';
        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
    }

    public function testIsCleanDetectsNoChanges(): void
    {
        $user = TestUser::find(1);
        $this->assertTrue($user->isClean());

        $user->name = 'Changed';
        $this->assertFalse($user->isClean());
    }

    // ─── firstOrCreate / updateOrCreate ──────────────

    public function testFirstOrCreateFindsExisting(): void
    {
        $before = count(TestUser::all());
        $user = TestUser::firstOrCreate(['email' => 'j@mail.com'], ['name' => 'Nope']);
        $this->assertSame($before, count(TestUser::all()));
        $this->assertSame('João', $user->name);
    }

    public function testFirstOrCreateCreatesNew(): void
    {
        $before = count(TestUser::all());
        $user = TestUser::firstOrCreate(['email' => 'new@test.com'], ['name' => 'New User']);
        $this->assertSame($before + 1, count(TestUser::all()));
        $this->assertSame('New User', $user->name);
    }

    public function testUpdateOrCreateUpdatesExisting(): void
    {
        $before = count(TestUser::all());
        $user = TestUser::updateOrCreate(
            ['email' => 'j@mail.com'],
            ['name' => 'João Updated']
        );
        $this->assertSame($before, count(TestUser::all()));
        $this->assertSame('João Updated', $user->name);
    }

    public function testUpdateOrCreateCreatesNew(): void
    {
        $before = count(TestUser::all());
        $user = TestUser::updateOrCreate(
            ['email' => 'brand.new@test.com'],
            ['name' => 'Brand New']
        );
        $this->assertSame($before + 1, count(TestUser::all()));
        $this->assertSame('Brand New', $user->name);
    }

    // ─── fresh / replicate ───────────────────────────

    public function testFreshReloadsFromDatabase(): void
    {
        $user = TestUser::find(1);
        $user->name = 'Not Saved';

        $fresh = $user->fresh();
        $this->assertSame('João', $fresh->name);
    }

    public function testReplicateCreatesUnsavedCopy(): void
    {
        $user = TestUser::find(1);
        $copy = $user->replicate();

        $this->assertNull($copy->getAttribute('id'));
        $this->assertSame('João', $copy->name);
        $this->assertSame('j@mail.com', $copy->email);
        $this->assertFalse($copy->exists);
    }

    // ─── Soft Deletes ────────────────────────────────

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $user = TestSoftUser::find(1);
        $this->assertNotNull($user);

        $user->delete();

        // Should not appear in normal query
        $found = TestSoftUser::find(1);
        $this->assertNull($found);
    }

    public function testWithTrashedIncludesSoftDeleted(): void
    {
        $user = TestSoftUser::find(1);
        $user->delete();

        $allUsers = TestSoftUser::withTrashed()->get();
        $this->assertCount(2, $allUsers);
    }

    public function testOnlyTrashedReturnsSoftDeletedOnly(): void
    {
        $user = TestSoftUser::find(1);
        $user->delete();

        $trashed = TestSoftUser::onlyTrashed()->get();
        $this->assertCount(1, $trashed);
        $this->assertSame('Alice', $trashed[0]->name);
    }

    public function testRestoreRestogesSoftDeletedModel(): void
    {
        $user = TestSoftUser::find(1);
        $user->delete();

        // Restore
        $trashedUser = TestSoftUser::withTrashed()->find(1);
        $trashedUser->restore();

        // Should be visible again
        $restored = TestSoftUser::find(1);
        $this->assertNotNull($restored);
        $this->assertSame('Alice', $restored->name);
    }

    public function testTrashedReturnsTrueForSoftDeleted(): void
    {
        $user = TestSoftUser::find(1);
        $this->assertFalse($user->trashed());

        $user->delete();

        $deleted = TestSoftUser::withTrashed()->find(1);
        $this->assertTrue($deleted->trashed());
    }

    public function testForceDeleteActuallyRemovesRecord(): void
    {
        $user = TestSoftUser::find(1);
        $user->forceDelete();

        // Should not exist even with trashed
        $found = TestSoftUser::withTrashed()->find(1);
        $this->assertNull($found);
    }

    // ─── Relationships ───────────────────────────────

    public function testHasManyReturnsRelationObject(): void
    {
        $user = TestUser::find(1);
        $relation = $user->orders();
        $this->assertInstanceOf(HasManyRelation::class, $relation);
    }

    public function testHasManyGetResults(): void
    {
        $user = TestUser::find(1);
        $orders = $user->orders;  // lazy load via __get
        $this->assertCount(2, $orders);
        $this->assertInstanceOf(TestOrder::class, $orders[0]);
    }

    public function testHasManyChainConstraints(): void
    {
        $user = TestUser::find(1);
        $completed = $user->orders()->where('status', 'completed')->get();
        $this->assertCount(1, $completed);
        $this->assertEquals('completed', $completed[0]->status);
    }

    public function testHasManyCount(): void
    {
        $user = TestUser::find(1);
        $this->assertEquals(2, $user->orders()->count());
    }

    public function testBelongsToRelationship(): void
    {
        $order = TestOrder::find(1);
        $relation = $order->user();
        $this->assertInstanceOf(BelongsToRelation::class, $relation);

        $user = $order->user;  // lazy load
        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertSame('João', $user->name);
    }

    public function testHasOneRelationship(): void
    {
        $user = TestUserWithProfile::find(1);
        $relation = $user->profile();
        $this->assertInstanceOf(HasOneRelation::class, $relation);

        $profile = $user->profile;  // lazy load
        $this->assertInstanceOf(TestProfile::class, $profile);
        $this->assertSame('Admin bio', $profile->bio);
    }

    public function testHasOneReturnsNullWhenNotFound(): void
    {
        $user = TestUserWithProfile::find(2);  // Maria has no profile
        $this->assertNull($user->profile);
    }

    public function testBelongsToManyRelationship(): void
    {
        $user = TestUserWithProfile::find(1);
        $relation = $user->roles();
        $this->assertInstanceOf(BelongsToManyRelation::class, $relation);

        $roles = $user->roles;  // lazy load
        $this->assertCount(2, $roles);
        $this->assertInstanceOf(TestRole::class, $roles[0]);
    }

    public function testBelongsToManyEmptyResult(): void
    {
        $user = TestUserWithProfile::find(2);  // Maria has no roles
        $roles = $user->roles;
        $this->assertIsArray($roles);
        $this->assertCount(0, $roles);
    }

    public function testEagerLoadingHasMany(): void
    {
        $users = TestUser::with('orders')->get();
        $this->assertCount(3, $users);

        // User 1 has 2 orders
        $this->assertTrue($users[0]->relationLoaded('orders'));
        $this->assertCount(2, $users[0]->getRelation('orders'));

        // Users 2 and 3 have 0 orders
        $this->assertTrue($users[1]->relationLoaded('orders'));
        $this->assertCount(0, $users[1]->getRelation('orders'));
    }

    public function testRelationDelete(): void
    {
        $user = TestUser::find(1);
        $user->orders()->delete();

        $this->assertCount(0, TestOrder::all());
    }

    // ─── findOrFail ──────────────────────────────────

    public function testFindOrFailReturnsModel(): void
    {
        $user = TestUser::findOrFail(1);
        $this->assertSame('João', $user->name);
    }

    // ─── Save ────────────────────────────────────────

    public function testSaveInsertsNewModel(): void
    {
        $user = new TestUser();
        $user->name = 'Save Test';
        $user->email = 'save@mail.com';
        $result = $user->save();

        $this->assertTrue($result);
        $this->assertTrue($user->exists);
    }

    // ─── Attribute-based Relationships ──────────────────

    public function testAttrHasManyLazyLoad(): void
    {
        $user = TestAttrUser::find(1);
        $orders = $user->test_orders;
        $this->assertCount(2, $orders);
        $this->assertInstanceOf(TestOrder::class, $orders[0]);
    }

    public function testAttrHasManyChaining(): void
    {
        $user = TestAttrUser::find(1);
        $completed = $user->test_orders()->where('status', 'completed')->get();
        $this->assertCount(1, $completed);
    }

    public function testAttrHasOneLazyLoad(): void
    {
        $user = TestAttrUser::find(1);
        $profile = $user->test_profile;
        $this->assertInstanceOf(TestProfile::class, $profile);
        $this->assertSame('Admin bio', $profile->bio);
    }

    public function testAttrHasOneReturnsNull(): void
    {
        $user = TestAttrUser::find(2);
        $this->assertNull($user->test_profile);
    }

    public function testAttrBelongsToLazyLoad(): void
    {
        $order = TestAttrOrder::find(1);
        $author = $order->author;
        $this->assertInstanceOf(TestUser::class, $author);
        $this->assertSame('João', $author->name);
    }

    public function testAttrBelongsToManyLazyLoad(): void
    {
        $user = TestAttrUser::find(1);
        $roles = $user->test_roles;
        $this->assertCount(2, $roles);
        $this->assertInstanceOf(TestRole::class, $roles[0]);
    }

    public function testAttrBelongsToManyEmpty(): void
    {
        $user = TestAttrUser::find(2);
        $roles = $user->test_roles;
        $this->assertIsArray($roles);
        $this->assertCount(0, $roles);
    }

    public function testAttrEagerLoading(): void
    {
        $users = TestAttrUser::with('test_orders')->get();
        $this->assertCount(3, $users);
        $this->assertTrue($users[0]->relationLoaded('test_orders'));
        $this->assertCount(2, $users[0]->getRelation('test_orders'));
        $this->assertCount(0, $users[1]->getRelation('test_orders'));
    }

    public function testAttrRelationDelete(): void
    {
        $user = TestAttrUser::find(1);
        $user->test_orders()->delete();
        $this->assertCount(0, TestOrder::all());
    }

    // ─── Attribute-based Scopes ─────────────────────────

    public function testAttrScopeFixedValue(): void
    {
        $active = TestAttrScopedUser::published()->get();
        $this->assertCount(2, $active);
    }

    public function testAttrScopeFixedValueAdmins(): void
    {
        $admins = TestAttrScopedUser::admins()->get();
        $this->assertCount(1, $admins);
        $this->assertSame('João', $admins[0]->name);
    }

    public function testAttrScopeParameterized(): void
    {
        $editors = TestAttrScopedUser::byRole('editor')->get();
        $this->assertCount(1, $editors);
        $this->assertSame('Maria', $editors[0]->name);
    }

    public function testAttrScopeWithOperator(): void
    {
        $older = TestAttrScopedUser::olderThan()->get();
        $this->assertCount(2, $older); // João (30) and Pedro (40)
    }

    public function testAttrScopeChaining(): void
    {
        $activeAdmins = TestAttrScopedUser::published()->where('role', 'admin')->get();
        $this->assertCount(1, $activeAdmins);
    }

    // ─── Attribute-based Accessors & Mutators ───────────

    public function testAttrAccessor(): void
    {
        $user = TestAttrAccessorUser::find(1);
        $this->assertSame('JOÃO', $user->full_name);
    }

    public function testAttrAccessorCustomName(): void
    {
        $user = TestAttrAccessorUser::find(1);
        $this->assertSame('Role: admin', $user->display_role);
    }

    public function testAttrAccessorInToArray(): void
    {
        $user = TestAttrAccessorUser::find(1);
        $data = $user->toArray();
        $this->assertSame('JOÃO', $data['full_name']);
        $this->assertSame('Role: admin', $data['display_role']);
    }

    public function testAttrMutator(): void
    {
        $user = new TestAttrAccessorUser();
        $user->password = 'secret123';
        $this->assertSame(sha1('secret123'), $user->getAttribute('password'));
    }

    public function testAttrMutatorOnCreate(): void
    {
        $user = TestAttrAccessorUser::create([
            'name' => 'Test',
            'email' => 'mut@test.com',
            'password' => 'mypass',
        ]);
        $this->assertSame(sha1('mypass'), $user->getAttribute('password'));
    }

}
