# Database

O SparkPHP inclui um ORM completo com QueryBuilder, Models, Migrations e Seeds. Suporta MySQL, PostgreSQL e SQLite.

## Configuracao

No `.env`:

```env
DB=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=sparkphp
DB_USER=root
DB_PASS=
```

A conexao e **lazy** — so conecta quando voce faz a primeira query.

---

## Query Builder

### Obtendo uma instancia

```php
// Via helper global
$users = db('users')->get();

// Equivalente
$users = db()->table('users')->get();
```

### Select

```php
// Todos os registros
$users = db('users')->get();

// Com selecao de colunas
$users = db('users')->select('id', 'name', 'email')->get();

// Select com expressao SQL
$stats = db('orders')
    ->selectRaw('status, COUNT(*) as total, AVG(amount) as avg_amount')
    ->groupBy('status')
    ->get();

// Primeiro resultado
$user = db('users')->where('id', 1)->first();

// Encontrar por ID
$user = db('users')->find(42);

// Valor unico de uma coluna
$name = db('users')->where('id', 1)->value('name');

// Coluna como array
$emails = db('users')->pluck('email');
$map    = db('users')->pluck('name', 'id');  // [1 => 'Ana', 2 => 'Bob']
```

### Where

```php
// Igualdade
db('users')->where('status', 'active')->get();

// Com operador
db('users')->where('age', '>=', 18)->get();

// Multiplas condicoes
db('users')
    ->where('status', 'active')
    ->where('role', 'admin')
    ->get();

// OR
db('users')
    ->where('role', 'admin')
    ->orWhere('role', 'mod')
    ->get();

// IN
db('users')->whereIn('status', ['active', 'pending'])->get();

// NOT IN
db('users')->whereNotIn('status', ['banned', 'deleted'])->get();

// NULL checks
db('users')->whereNull('deleted_at')->get();
db('users')->whereNotNull('email_verified_at')->get();

// BETWEEN / NOT BETWEEN
db('orders')->whereBetween('total', [100, 500])->get();
db('users')->whereNotBetween('salary', [3000, 8000])->get();

// LIKE
db('users')->whereLike('name', '%silva%')->get();

// Raw WHERE (quando precisa de SQL puro)
db('users')->whereRaw('YEAR(created_at) = ?', [2026])->get();

// exists / doesntExist
if (db('users')->where('email', $email)->exists()) { ... }
if (db('users')->where('email', $email)->doesntExist()) { ... }
```

### Condicional fluente (when)

Aplica filtros condicionalmente — ideal para buscas com filtros opcionais:

```php
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

### OrderBy, Limit, Offset

```php
db('posts')
    ->where('published', true)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->offset(20)
    ->get();

// Atalho para latest/oldest
db('posts')->latest()->get();              // ORDER BY created_at DESC
db('posts')->oldest()->get();              // ORDER BY created_at ASC
db('posts')->latest('published_at')->get();// coluna customizada
db('posts')->orderByDesc('created_at')->get();  // equivalente explicito
```

### Agregacoes

```php
db('users')->count();                          // total
db('orders')->where('user_id', 1)->sum('total');  // soma
db('products')->avg('price');                  // media
db('products')->max('price');                  // maximo
db('products')->min('price');                  // minimo
```

### Joins

```php
db('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->select('posts.*', 'users.name as author')
    ->get();

db('posts')
    ->leftJoin('comments', 'posts.id', '=', 'comments.post_id')
    ->select('posts.*', 'COUNT(comments.id) as total_comments')
    ->groupBy('posts.id')
    ->get();

db('users')
    ->rightJoin('orders', 'users.id', '=', 'orders.user_id')
    ->get();
```

### Group By e Having

```php
db('orders')
    ->select('user_id', 'SUM(total) as total_spent')
    ->groupBy('user_id')
    ->having('total_spent', '>', 1000)
    ->get();

// Having com SQL raw
db('orders')
    ->selectRaw('user_id, SUM(total) as revenue')
    ->groupBy('user_id')
    ->havingRaw('SUM(total) > 1000')
    ->get();
```

### Insert

```php
// Insert unico (retorna o ID)
$id = db('users')->insert([
    'name'  => 'Ana',
    'email' => 'ana@example.com',
]);

// Insert multiplo
db('users')->insertMany([
    ['name' => 'Ana', 'email' => 'ana@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
]);
```

### Update

```php
db('users')
    ->where('id', 42)
    ->update(['name' => 'Ana Silva']);

// Increment / Decrement
db('posts')->where('id', 1)->increment('views');
db('products')->where('id', 5)->decrement('stock', 3);
```

### Upsert

```php
// Cria se nao existe, atualiza se existe
db('users')->updateOrCreate(
    ['email' => 'ana@example.com'],       // busca por
    ['name' => 'Ana', 'role' => 'admin']  // dados
);

// Busca ou cria (nao atualiza se ja existe)
db('users')->firstOrCreate(
    ['email' => 'ana@example.com'],
    ['name' => 'Ana']
);
```

### Delete

```php
db('users')->where('id', 42)->delete();
```

### Paginacao

```php
// Pagina 1, 15 por pagina
$result = db('users')->paginate(15);

// $result retorna:
// [
//     'data'         => [...],
//     'current_page' => 1,
//     'per_page'     => 15,
//     'total'        => 150,
//     'last_page'    => 10,
//     'from'         => 1,
//     'to'           => 15,
// ]
```

### Chunk (processar em lotes)

```php
db('users')->orderBy('id')->chunk(100, function ($users, $page) {
    foreach ($users as $user) {
        // Processa 100 usuarios por vez
    }
});

// Retorne false para interromper o processamento
db('users')->chunk(100, function ($users) {
    if (someCondition()) return false;
});
```

### Debug

```php
$sql = db('users')->where('status', 'active')->toSql();
// "SELECT * FROM `users` WHERE `status` = ?"

$raw = db('users')->where('status', 'active')->toRawSql();
// "SELECT * FROM `users` WHERE `status` = 'active'"
```

### Statements raw

```php
db()->statement('ALTER TABLE users ADD COLUMN avatar VARCHAR(255)');
db()->select('SELECT * FROM users WHERE id = ?', [1]);
```

---

## Models

### Criando um model

```bash
php spark make:model User
```

Cria `app/models/User.php`:

```php
<?php

class User extends Model
{
    // O nome da tabela e resolvido automaticamente:
    // User → users
    // OrderItem → order_items
    // Category → categories
}
```

### Convencoes automaticas

| Classe      | Tabela         | Chave primaria |
|-------------|----------------|----------------|
| `User`      | `users`        | `id`           |
| `Post`      | `posts`        | `id`           |
| `OrderItem` | `order_items`  | `id`           |
| `Category`  | `categories`   | `id`           |

### CRUD basico

```php
// Criar
$user = User::create([
    'name'  => 'Ana',
    'email' => 'ana@example.com',
]);

// Buscar
$user = User::find(42);                       // por ID (ou null)
$user = User::findOrFail(42);                 // por ID (ou 404)
$users = User::all();                         // todos

// Atualizar
$user->update(['name' => 'Ana Silva']);

// Ou atribuir e salvar
$user->name = 'Ana Silva';
$user->save();

// Deletar
$user->delete();
```

### Mass Assignment

Controle quais campos podem ser preenchidos em massa:

```php
class User extends Model
{
    // Apenas esses campos podem ser usados em create/update com array
    protected array $fillable = ['name', 'email', 'password'];

    // OU: tudo menos esses
    protected array $guarded = ['id', 'is_admin'];
}
```

### Timestamps

Por padrao, `created_at` e `updated_at` sao gerenciados automaticamente:

```php
class User extends Model
{
    protected bool $timestamps = true; // padrao

    // Para desativar:
    // protected bool $timestamps = false;
}
```

### Casts

Converta atributos automaticamente ao ler/escrever:

```php
class User extends Model
{
    protected array $casts = [
        'is_admin'   => 'bool',
        'age'        => 'int',
        'balance'    => 'float',
        'settings'   => 'array',     // JSON ↔ array
        'metadata'   => 'json',      // alias de array
        'birthday'   => 'datetime',  // string → DateTime
    ];
}

$user->settings;  // array (decodificado do JSON no banco)
$user->is_admin;  // true/false (nao 0/1)
$user->birthday;  // DateTime object
```

### Hidden (ocultar do JSON/array)

Campos em `$hidden` sao omitidos de `toArray()` e `toJson()` — ideal para senhas e tokens:

```php
class User extends Model
{
    protected array $hidden = ['password', 'remember_token'];
}

$user->toArray();  // Sem 'password' e 'remember_token'
$user->toJson();   // Sem 'password' e 'remember_token'
```

### Soft Deletes

```php
class User extends Model
{
    protected bool $softDeletes = true;
}

$user->delete();           // Seta deleted_at, nao remove do banco
$user->forceDelete();      // Remove do banco de verdade

User::all();               // Exclui soft-deleted automaticamente
User::withTrashed()->get();    // Inclui soft-deleted
User::onlyTrashed()->get();   // Apenas soft-deleted

$user->restore();          // Remove deleted_at
$user->trashed();          // true se soft-deleted
```

### Scopes

Defina filtros reutilizaveis com `#[Scope]` direto na classe:

```php
#[Scope('published', column: 'published', value: true)]
#[Scope('active', column: 'active', value: true)]
#[Scope('byAuthor', column: 'user_id')]
#[Scope('adults', column: 'age', op: '>=', value: 18)]
#[Scope('verified', whereNotNull: 'email_verified_at')]
#[Scope('draft', whereNull: 'published_at')]
class Post extends Model {}

// Uso
Post::published()->get();
Post::byAuthor(1)->latest()->get();
Post::published()->active()->get();
```

#### Parametros do #[Scope]

| Parametro | Descricao |
|-----------|-----------|
| `column` | Coluna para o filtro WHERE |
| `value` | Valor fixo. Se omitido, o scope aceita um parametro: `Post::byAuthor(1)` |
| `op` | Operador (padrao `=`). Ex: `>=`, `<`, `!=`, `LIKE` |
| `whereNull` | Atalho para `WHERE coluna IS NULL` |
| `whereNotNull` | Atalho para `WHERE coluna IS NOT NULL` |

#### Sintaxe classica (metodos)

A forma anterior via metodos `scopeXxx()` continua funcionando:

```php
class Post extends Model
{
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    public function scopeByAuthor($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}

Post::published()->byAuthor(1)->latest()->get();
```

> **Quando usar metodos?** Quando o scope precisa de logica complexa (multiplos wheres,
> subqueries, joins). Para filtros simples, prefira `#[Scope]`.

### Accessors e Mutators

Defina propriedades computadas e transformacoes de escrita com `#[Accessor]` e `#[Mutator]`:

```php
class User extends Model
{
    #[Accessor]
    public function fullName(): string
    {
        return $this->name . ' ' . $this->surname;
    }

    #[Accessor('display_role')]
    public function computeRole(): string
    {
        return 'Role: ' . $this->role;
    }

    #[Mutator]
    public function password(string $value): string
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}

$user->full_name;     // "Ana Silva" (Accessor)
$user->display_role;  // "Role: admin" (Accessor com nome customizado)
$user->password = 'plain';  // armazena hash (Mutator)
```

O nome da propriedade e derivado automaticamente do nome do metodo (`fullName` → `full_name`).
Use o parametro opcional para nomes customizados: `#[Accessor('display_role')]`.

Accessors definidos com `#[Accessor]` tambem sao incluidos em `toArray()` e `toJson()`.

#### Sintaxe classica (metodos)

A convencao `get{Campo}Attribute` / `set{Campo}Attribute` continua funcionando.
O nome do campo usa PascalCase: `full_name` → `getFullNameAttribute`, `password` → `setPasswordAttribute`:

```php
class User extends Model
{
    public function getFullNameAttribute(): string
    {
        return $this->name . ' ' . $this->surname;
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }
}
```

> **Quando usar metodos?** Quando precisar de compatibilidade com codigo existente.
> Para novos models, prefira `#[Accessor]` e `#[Mutator]`.

### Relacionamentos

Defina relacionamentos usando PHP Attributes na classe.
Os attributes ficam acima da palavra `class`, mas pertencem a ela — e a sintaxe nativa do PHP 8:

```php
#[HasMany(Post::class)]           // ← pertence a classe User
#[HasOne(Profile::class)]         // ← pertence a classe User
#[BelongsToMany(Role::class, pivot: 'user_roles')]
class User extends Model
{
    protected array $fillable = ['name', 'email'];
}
```

```php
#[BelongsTo(User::class, as: 'author')]
class Post extends Model
{
    protected array $fillable = ['user_id', 'title', 'body'];
}
```

#### Attributes disponiveis

| Attribute | Descricao | Exemplo |
|-----------|-----------|---------|
| `#[HasMany]` | Um para muitos | `#[HasMany(Post::class)]` |
| `#[HasOne]` | Um para um | `#[HasOne(Profile::class)]` |
| `#[BelongsTo]` | Pertence a (inverso) | `#[BelongsTo(User::class)]` |
| `#[BelongsToMany]` | Muitos para muitos | `#[BelongsToMany(Role::class)]` |

#### Parametros opcionais

```php
// Nome customizado (por padrao, deriva do nome da classe)
#[HasMany(Comment::class, as: 'reviews')]

// Foreign key customizada
#[BelongsTo(User::class, as: 'author', foreignKey: 'author_id')]

// Tabela pivot customizada
#[BelongsToMany(Role::class, pivot: 'user_roles', foreignPivotKey: 'user_id', relatedPivotKey: 'role_id')]
```

> O nome do relacionamento e resolvido automaticamente a partir da classe:
> `Post::class` → `posts`, `Profile::class` → `profile`, `OrderItem::class` → `order_items`.
> Use o parametro `as:` para customizar.

#### Usando relacionamentos

```php
// Lazy loading (acessa como propriedade — resolve automaticamente)
$user = User::find(1);
$posts = $user->posts;         // executa query sob demanda
$name  = $post->author->name;

// Eager loading (evita N+1 com batch queries)
$users = User::with('posts')->get();
$posts = Post::with('author')->where('published', true)->get();

// Multiplos relacionamentos
$users = User::with('posts', 'profile', 'roles')->get();
```

#### Encadeando constraints no relacionamento

Ao chamar como **metodo** (`->posts()`), voce recebe um objeto Relation encadeavel:

```php
// Filtrar resultados do relacionamento
$paid = $user->orders()->where('status', 'paid')->get();

// Ordenar e limitar
$recent = $user->posts()->latest()->limit(5)->get();

// Contar
$total = $user->orders()->count();

// Paginar
$page = $user->posts()->paginate(10);

// Atualizar em massa
$user->orders()->where('status', 'pending')->update(['status' => 'cancelled']);

// Deletar relacionados
$user->posts()->delete();
```

> **Dica:** `$user->posts` (propriedade) executa e retorna os resultados.
> `$user->posts()` (metodo) retorna o Relation, permitindo encadear antes de executar.

#### Sintaxe via metodos

Tambem e possivel definir relacionamentos via metodos — util quando precisar de logica
customizada ou constraints padroes:

```php
class User extends Model
{
    public function posts(): HasManyRelation
    {
        return $this->hasMany(Post::class);
    }

    // Relacionamento com constraint fixa
    public function publishedPosts(): HasManyRelation
    {
        return $this->hasMany(Post::class)->where('published', true);
    }

    public function profile(): HasOneRelation
    {
        return $this->hasOne(Profile::class);
    }

    public function roles(): BelongsToManyRelation
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }
}
```

| Metodo | Retorno | Descricao |
|--------|---------|-----------|
| `$this->hasMany(Related::class, ?fk, ?localKey)` | `HasManyRelation` | Um para muitos |
| `$this->hasOne(Related::class, ?fk, ?localKey)` | `HasOneRelation` | Um para um |
| `$this->belongsTo(Related::class, ?fk, ?ownerKey)` | `BelongsToRelation` | Pertence a |
| `$this->belongsToMany(Related::class, ?pivot, ?fk, ?rfk)` | `BelongsToManyRelation` | Muitos para muitos |

> **Quando usar metodos?** Quando o relacionamento precisa de constraints fixas,
> logica condicional, ou parametros que a forma declarativa nao suporta.
> Para o dia a dia, prefira a forma declarativa com arrays.

### Eventos do model

```php
class User extends Model
{
    protected static function booted(): void
    {
        static::creating(function ($user) {
            $user->uuid = uuid();
        });

        static::updating(function ($user) {
            log_info("User {$user->id} updated");
        });

        static::deleting(function ($user) {
            $user->posts()->delete();
        });
    }
}
```

Eventos disponiveis: `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`.

### Dirty tracking

Verifique se o model foi modificado antes de salvar:

```php
$user = User::find(1);
$user->isDirty();          // false
$user->isClean();          // true

$user->name = 'Novo Nome';
$user->isDirty();          // true
$user->isDirty('name');    // true
$user->isDirty('email');   // false
$user->getDirty();         // ['name' => 'Novo Nome']
```

### Fresh e Replicate

```php
// Recarregar do banco (descarta mudancas locais)
$fresh = $user->fresh();

// Clonar model sem primary key (para duplicar registros)
$copy = $post->replicate();
$copy->title = 'Copia do post';
$copy->save();
```

---

## Migrations

### Criando uma migration

```bash
php spark make:migration create_posts_table
```

Cria `database/migrations/002_create_posts_table.php`:

```php
<?php

up(function () {
    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('title');
        $table->string('slug')->unique();
        $table->text('body');
        $table->boolean('published')->default(false);
        $table->datetime('published_at')->nullable();
        $table->timestamps();    // created_at + updated_at
        $table->softDeletes();   // deleted_at nullable
    });
});

down(function () {
    Schema::dropIfExists('posts');
});
```

### Tipos de coluna disponíveis

```php
$table->id();                          // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
$table->string('name');                // VARCHAR(255)
$table->string('code', 10);           // VARCHAR(10)
$table->text('body');                  // TEXT
$table->mediumText('content');         // MEDIUMTEXT (MySQL) / TEXT (PG/SQLite)
$table->longText('html');              // LONGTEXT (MySQL) / TEXT (PG/SQLite)
$table->boolean('active');             // TINYINT(1) / BOOLEAN
$table->tinyInteger('priority');       // TINYINT / SMALLINT
$table->smallInteger('qty');           // SMALLINT
$table->integer('quantity');           // INT
$table->bigInteger('views');           // BIGINT
$table->float('price');                // FLOAT
$table->decimal('total', 10, 2);      // DECIMAL(10,2)
$table->enum('status', ['draft', 'published']);
$table->json('metadata');              // JSON
$table->date('birthday');              // DATE
$table->datetime('event_at');          // DATETIME
$table->timestamp('verified_at');      // TIMESTAMP
$table->uuid('public_id');            // CHAR(36)
$table->binary('data');                // BLOB
$table->foreignId('user_id');          // BIGINT UNSIGNED (FK helper)
$table->timestamps();                  // created_at + updated_at
$table->softDeletes();                 // deleted_at DATETIME NULL
```

### Modificadores de coluna

```php
$table->string('bio')->nullable();
$table->string('email')->unique();
$table->integer('views')->default(0);
$table->integer('quantity')->unsigned();
$table->string('code')->primary();
$table->string('slug')->index();
$table->string('phone')->after('email');           // posicao (MySQL only)
$table->string('code')->comment('ISO code');       // comentario (MySQL only)
$table->foreignId('user_id')->constrained();       // FK → users.id
$table->foreignId('author_id')->constrained('users');  // FK → users.id
$table->foreignId('user_id')->constrained()->cascadeOnDelete();  // ON DELETE CASCADE
```

### Modificar tabela existente

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('phone')->nullable();          // Adicionar coluna
    $table->dropColumn('legacy_field');            // Remover coluna
    $table->renameColumn('name', 'full_name');     // Renomear coluna
    $table->dropIndex('users_email_index');        // Remover indice
    $table->dropUnique('users_email_unique');      // Remover unique
    $table->dropForeign('posts_user_id_foreign');  // Remover FK
});
```

### Dropar tabela

```php
Schema::dropIfExists('temp_data');
```

### Executando migrations

```bash
php spark migrate              # Executa migrations pendentes
php spark migrate --seed       # Migrate + seed
php spark migrate:status       # Mostra status de cada migration
php spark migrate:rollback     # Desfaz a ultima batch
php spark migrate:rollback 3   # Desfaz os ultimos 3 batches
php spark migrate:fresh        # Drop all + re-migrate (cuidado!)
php spark migrate:fresh --seed # Drop all + re-migrate + seed
```

---

## Seeds

### Criando um seeder

```bash
php spark make:seeder UserSeeder
```

```php
// database/seeds/UserSeeder.php
<?php

class UserSeeder
{
    public function run(): void
    {
        db('users')->insertMany([
            ['name' => 'Admin', 'email' => 'admin@app.com', 'password' => password_hash('secret', PASSWORD_DEFAULT)],
            ['name' => 'Ana',   'email' => 'ana@app.com',   'password' => password_hash('secret', PASSWORD_DEFAULT)],
        ]);
    }
}
```

### DatabaseSeeder (orquestrador)

```php
// database/seeds/DatabaseSeeder.php
<?php

class DatabaseSeeder
{
    public function run(): void
    {
        (new UserSeeder())->run();
        (new PostSeeder())->run();
    }
}
```

### Executando seeds

```bash
php spark seed                   # Executa DatabaseSeeder
php spark seed UserSeeder        # Executa seeder especifico
```

---

## Inspecao do banco

```bash
php spark db:show               # Mostra conexao e lista de tabelas
php spark db:table users        # Mostra colunas e indices da tabela
php spark db:wipe               # Drop ALL tables (cuidado!)
```

## Proximo passo

→ [Validation](06-validation.md)
