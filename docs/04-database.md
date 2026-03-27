# 04 - Database

SparkPHP oferece uma camada de banco de dados poderosa, intuitiva e moderna.
**"Escreva o que importa"** — a filosofia se estende a cada consulta, modelo e migração.

---

## Sumário

1. [Query Builder](#query-builder)
2. [Model (ORM)](#model-orm)
3. [Schema & Blueprint](#schema--blueprint)
4. [CLI de Banco de Dados](#cli-de-banco-de-dados)

---

## Query Builder

O Query Builder oferece uma interface fluente para construir consultas SQL sem escrever SQL diretamente.

### Uso básico

```php
// Buscar todos os usuários
$users = db('users')->get();

// Buscar com condição
$admins = db('users')->where('role', 'admin')->get();

// Buscar com operador
$adults = db('users')->where('age', '>', 18)->get();

// Primeiro resultado
$user = db('users')->where('email', 'j@mail.com')->first();

// Encontrar por ID
$user = db('users')->find(1);
```

### Condições avançadas

```php
// orWhere
$users = db('users')
    ->where('role', 'admin')
    ->orWhere('role', 'editor')
    ->get();

// whereBetween / whereNotBetween
$users = db('users')->whereBetween('age', [18, 65])->get();
$outliers = db('users')->whereNotBetween('salary', [3000, 8000])->get();

// whereIn / whereNotIn
$selected = db('users')->whereIn('id', [1, 2, 3])->get();
$others = db('users')->whereNotIn('status', ['banned', 'suspended'])->get();

// whereNull / whereNotNull
$unverified = db('users')->whereNull('email_verified_at')->get();
$verified = db('users')->whereNotNull('email_verified_at')->get();

// whereLike
$matches = db('users')->whereLike('name', 'João%')->get();

// whereRaw — SQL direto quando necessário
$results = db('users')->whereRaw('YEAR(created_at) = ?', [2025])->get();
```

### Condicional fluente

```php
// Aplica filtro apenas quando $role tem valor
$users = db('users')
    ->when($role, fn($q) => $q->where('role', $role))
    ->when($active, fn($q) => $q->where('active', true))
    ->get();

// Com fallback (else)
$users = db('users')
    ->when(
        $search,
        fn($q) => $q->whereLike('name', "%{$search}%"),
        fn($q) => $q->orderByDesc('created_at')
    )
    ->get();
```

### Select

```php
// Selecionar colunas específicas
$users = db('users')->select(['name', 'email'])->get();

// Select com expressão SQL
$stats = db('orders')
    ->selectRaw('status, COUNT(*) as total, AVG(amount) as avg_amount')
    ->groupBy('status')
    ->get();
```

### Joins

```php
// Inner join
$data = db('orders')
    ->join('users', 'orders.user_id', '=', 'users.id')
    ->select(['orders.*', 'users.name as user_name'])
    ->get();

// Left join
$data = db('users')
    ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
    ->get();

// Right join
$data = db('orders')
    ->rightJoin('products', 'orders.product_id', '=', 'products.id')
    ->get();
```

### Agrupamento e Having

```php
// Group by
$stats = db('orders')
    ->selectRaw('user_id, COUNT(*) as total')
    ->groupBy('user_id')
    ->get();

// Having
$bigBuyers = db('orders')
    ->selectRaw('user_id, SUM(total) as revenue')
    ->groupBy('user_id')
    ->havingRaw('SUM(total) > 1000')
    ->get();
```

### Ordenação e paginação

```php
// Ordenar
$users = db('users')->orderBy('name')->get();
$users = db('users')->orderByDesc('created_at')->get();
$users = db('users')->latest()->get();   // ORDER BY created_at DESC
$users = db('users')->oldest()->get();   // ORDER BY created_at ASC

// Limitar
$top10 = db('users')->limit(10)->get();
$page2 = db('users')->limit(10)->offset(10)->get();

// Paginar automaticamente
$page = db('users')->paginate(15, page: 2);
// Retorna: { data, total, per_page, current_page, last_page, from, to }
```

### Agregação

```php
$total = db('users')->count();
$avg   = db('orders')->avg('total');
$sum   = db('orders')->sum('total');
$max   = db('users')->max('age');
$min   = db('users')->min('age');
```

### Helpers úteis

```php
// pluck — extrair uma coluna
$names = db('users')->pluck('name');           // ['João', 'Maria', ...]
$names = db('users')->pluck('name', 'id');     // [1 => 'João', 2 => 'Maria']

// value — valor de uma única coluna do primeiro resultado
$email = db('users')->where('id', 1)->value('email');

// exists / doesntExist
if (db('users')->where('email', $email)->exists()) { ... }

// chunk — processar em lotes
db('users')->orderBy('id')->chunk(100, function ($users, $page) {
    foreach ($users as $user) {
        // processar...
    }
});

// Retorne false para parar:
db('users')->chunk(100, function ($users) {
    if (someCondition()) return false;
});
```

### Inserção e atualização

```php
// Criar registro
$user = db('users')->create(['name' => 'João', 'email' => 'j@mail.com']);

// Inserir múltiplos registros
db('users')->insertMany([
    ['name' => 'A', 'email' => 'a@mail.com'],
    ['name' => 'B', 'email' => 'b@mail.com'],
    ['name' => 'C', 'email' => 'c@mail.com'],
]);

// Atualizar
db('users')->where('id', 1)->update(['name' => 'Novo Nome']);

// updateOrCreate — atualiza se existe, cria se não
db('settings')->updateOrCreate(
    ['key' => 'theme'],
    ['value' => 'dark']
);

// firstOrCreate — retorna existente ou cria novo
$user = db('users')->firstOrCreate(
    ['email' => 'j@mail.com'],
    ['name' => 'João', 'role' => 'user']
);

// Incrementar / decrementar
db('posts')->where('id', 1)->increment('views');
db('products')->where('id', 5)->decrement('stock', 3);
```

### Debug de queries

```php
// Ver SQL gerado (com placeholders)
$sql = db('users')->where('active', 1)->toSql();
// "SELECT * FROM `users` WHERE `active` = ?"

// Ver SQL + bindings
[$sql, $bindings] = db('users')->where('active', 1)->toRawSql();
// ["SELECT * FROM `users` WHERE `active` = ?", [1]]
```

---

## Model (ORM)

O Model do SparkPHP é um ORM elegante que transforma tabelas em classes PHP com funcionalidades modernas.

### Definindo um modelo

```php
class User extends Model
{
    protected string $table      = 'users';   // Opcional, auto-resolve
    protected string $primaryKey = 'id';       // Padrão
    protected bool $timestamps   = true;       // Gerencia created_at / updated_at

    protected array $fillable = ['name', 'email', 'role', 'password'];
    protected array $guarded  = ['id'];        // Campos protegidos de mass-assignment
    protected array $casts    = [
        'age'    => 'int',
        'active' => 'bool',
        'meta'   => 'array',    // JSON ↔ array automaticamente
    ];
    protected array $hidden   = ['password'];  // Omitido do toArray/toJson
}
```

> **Resolução de tabela:** `User` → `users`, `OrderItem` → `order_items` (automático)

### CRUD básico

```php
// Buscar
$user = User::find(1);
$user = User::findOrFail(1);         // 404 se não encontrar
$users = User::all();
$admins = User::where('role', 'admin')->get();

// Criar
$user = User::create(['name' => 'João', 'email' => 'j@mail.com']);

// Atualizar
$user->update(['name' => 'Novo Nome']);

// Deletar
$user->delete();

// Salvar (insert ou update automático)
$user = new User();
$user->name = 'João';
$user->save();         // INSERT
$user->name = 'Pedro';
$user->save();         // UPDATE
```

### firstOrCreate / updateOrCreate

```php
$user = User::firstOrCreate(
    ['email' => 'j@mail.com'],
    ['name' => 'João']
);

$setting = Setting::updateOrCreate(
    ['key' => 'theme'],
    ['value' => 'dark']
);
```

### Soft Deletes

Habilite exclusão suave — registros "deletados" mantêm-se no banco com `deleted_at`:

```php
class Post extends Model
{
    protected bool $softDeletes = true;
}

$post->delete();          // Marca deleted_at, não remove do banco
$post->trashed();         // true
$post->restore();         // Remove deleted_at

$post->forceDelete();     // Remove de verdade

// Queries
Post::all();              // Só registros não deletados
Post::withTrashed()->get();   // Todos
Post::onlyTrashed()->get();   // Só os deletados
```

### Scopes

Defina filtros reutilizáveis como métodos `scope*`:

```php
class User extends Model
{
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeRole($query, string $role)
    {
        return $query->where('role', $role);
    }
}

// Uso:
$active = User::active()->get();
$admins = User::active()->role('admin')->get();
```

### Accessors & Mutators

Transforme valores na leitura (acessor) ou escrita (mutador):

```php
class User extends Model
{
    // Accessor: $user->full_name → MAIÚSCULO
    public function getFullNameAttribute(): string
    {
        return mb_strtoupper($this->getAttribute('name') ?? '');
    }

    // Mutator: $user->password = 'secret' → hash
    public function setPasswordAttribute(?string $value): ?string
    {
        return $value !== null ? password_hash($value, PASSWORD_BCRYPT) : null;
    }
}
```

### Atributos Hidden

Campos em `$hidden` são omitidos de `toArray()` e `toJson()`:

```php
class User extends Model
{
    protected array $hidden = ['password', 'remember_token'];
}

$user->toArray();  // Sem 'password' e 'remember_token'
$user->toJson();   // Sem 'password' e 'remember_token'
```

### Cast de tipos

```php
protected array $casts = [
    'age'         => 'int',       // (int)
    'price'       => 'float',     // (float)
    'active'      => 'bool',      // (bool)
    'metadata'    => 'array',     // JSON ↔ array
    'settings'    => 'json',      // JSON ↔ array (alias)
    'published_at'=> 'datetime',  // DateTimeImmutable
    'name'        => 'string',    // (string)
];
```

### Dirty tracking

```php
$user = User::find(1);
$user->isDirty();              // false
$user->isClean();              // true

$user->name = 'Novo Nome';
$user->isDirty();              // true
$user->isDirty('name');        // true
$user->isDirty('email');       // false
$user->getDirty();             // ['name' => 'Novo Nome']
```

### Fresh & Replicate

```php
// Recarregar do banco
$fresh = $user->fresh();

// Clonar sem primary key
$copy = $post->replicate();
$copy->title = 'Cópia do post';
$copy->save();
```

### Relacionamentos

```php
class User extends Model
{
    // Um usuário tem um perfil
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    // Um usuário tem muitos pedidos
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Um usuário pertence a uma empresa
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // Muitos-para-muitos via pivot
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}

// Uso:
$user->profile;       // Carrega automaticamente na primeira leitura
$user->orders;        // Array de pedidos
$user->company;       // Modelo Company
```

### Eager loading

```php
// Evitar N+1 queries:
$users = User::with('orders', 'profile')->get();
```

---

## Schema & Blueprint

Use o Schema Builder para criar e modificar tabelas de forma agnóstica ao banco.

### Criar tabela

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
    $table->json('metadata')->nullable();
    $table->boolean('featured')->default(false);
    $table->timestamps();
    $table->softDeletes();
});
```

### Tipos de coluna disponíveis

| Método | Descrição | MySQL | PostgreSQL | SQLite |
|--------|-----------|-------|------------|--------|
| `id()` | Primary key auto-increment | BIGINT UNSIGNED AI | BIGSERIAL | INTEGER PK AI |
| `string('name', 255)` | Texto curto | VARCHAR | VARCHAR | VARCHAR |
| `text('body')` | Texto longo | TEXT | TEXT | TEXT |
| `mediumText('content')` | Texto médio | MEDIUMTEXT | TEXT | TEXT |
| `longText('html')` | Texto muito longo | LONGTEXT | TEXT | TEXT |
| `boolean('active')` | Verdadeiro/falso | BOOLEAN | BOOLEAN | INTEGER |
| `tinyInteger('priority')` | Inteiro pequeno | TINYINT | SMALLINT | INTEGER |
| `smallInteger('qty')` | Inteiro curto | SMALLINT | SMALLINT | INTEGER |
| `integer('age')` | Inteiro | INT | INTEGER | INTEGER |
| `bigInteger('views')` | Inteiro grande | BIGINT | BIGINT | BIGINT |
| `float('lat', 10, 7)` | Ponto flutuante | FLOAT | DOUBLE PRECISION | REAL |
| `decimal('price', 10, 2)` | Decimal preciso | DECIMAL | NUMERIC | NUMERIC |
| `enum('status', [...])` | Valores fixos | ENUM | VARCHAR(255) | TEXT |
| `json('data')` | Dados JSON | JSON | JSONB | TEXT |
| `date('birthday')` | Data | DATE | DATE | TEXT |
| `datetime('published_at')` | Data e hora | DATETIME | TIMESTAMP | TEXT |
| `timestamp('verified_at')` | Timestamp | TIMESTAMP | TIMESTAMP | TEXT |
| `uuid('external_id')` | UUID | CHAR(36) | UUID | TEXT |
| `binary('file_data')` | Dados binários | BLOB | BYTEA | BLOB |
| `foreignId('user_id')` | FK BIGINT | BIGINT UNSIGNED | BIGINT | INTEGER |

### Modificadores de coluna

```php
$table->string('name')->nullable();
$table->boolean('active')->default(true);
$table->string('email')->unique();
$table->string('username')->index();
$table->integer('priority')->unsigned();
$table->string('phone')->after('email');      // MySQL only
$table->string('code')->comment('ISO code');  // MySQL only
```

### Timestamps e soft deletes

```php
$table->timestamps();    // created_at + updated_at
$table->softDeletes();   // deleted_at (TIMESTAMP NULL)
```

### Chaves estrangeiras

```php
// Automático (resolva tabela pelo nome da coluna)
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

// Explícito
$table->foreignId('category_id')
    ->references('id')
    ->on('categories')
    ->onDelete('SET NULL');
```

### Modificar tabela existente

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('phone')->nullable();      // Adicionar coluna
    $table->dropColumn('legacy_field');        // Remover coluna
    $table->renameColumn('name', 'full_name'); // Renomear coluna
    $table->dropIndex('users_email_index');    // Remover índice
    $table->dropUnique('users_email_unique');  // Remover unique
    $table->dropForeign('posts_user_id_foreign'); // Remover FK
});
```

### Dropar tabela

```php
Schema::dropIfExists('temp_data');
```

---

## CLI de Banco de Dados

O SparkPHP inclui comandos CLI para gerenciar seu banco diretamente pelo terminal.

### Migrações

```bash
# Rodar migrações pendentes
php spark migrate

# Rodar migrações + seed
php spark migrate --seed

# Rollback do último batch
php spark migrate:rollback

# Rollback dos últimos N batches
php spark migrate:rollback 3

# Status das migrações
php spark migrate:status

# Resetar banco (drop + re-migrate)
php spark db:fresh

# Resetar banco + seed
php spark db:fresh --seed
```

### Inspeção do banco

```bash
# Visão geral do banco (todas as tabelas + contagem de linhas)
php spark db:show

# ⚡ Database Overview  [mysql:myapp]
#
# Table                                   Columns     Rows
# ────────────────────────────────────────────────────────────
# migrations                              4           12
# users                                   8           1,234
# orders                                  6           5,678
# ...

# Estrutura de uma tabela específica
php spark db:table users

# ⚡ Table: users  [mysql]
#
# Column                      Type                        Nullable    Default           Key
# ────────────────────────────────────────────────────────────────────────────────────────
# id                          bigint unsigned             NO          —                 PRI
# name                        varchar(255)                NO          —
# email                       varchar(255)                YES         —
# ...
```

### Operações destrutivas

```bash
# Apagar todas as tabelas (sem re-migrar)
php spark db:wipe
```

### Seeds

```bash
# Rodar DatabaseSeeder
php spark seed

# Rodar um seeder específico
php spark seed UsersSeeder
```

### Scaffolding

```bash
# Criar nova migração
php spark make:migration create_posts_table

# Criar novo modelo
php spark make:model Post

# Criar novo seeder
php spark make:seeder PostsSeeder
```
