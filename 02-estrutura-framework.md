# SparkPHP — Estrutura do Framework

Este documento define a estrutura completa de diretórios e arquivos do SparkPHP, as convenções de nomeação e o mapa de resolução automática de cada camada.

Baseline atual do framework:

- PHP 8.3+
- SQLite 3.35+
- MySQL 8.0+
- PostgreSQL 13+

---

## Árvore de diretórios

```
/meu-projeto
│
├── app/
│   ├── routes/
│   │   ├── _middleware.php                  → middleware global herdado por todas as rotas
│   │   ├── index.php                          → GET /
│   │   ├── about.php                          → GET /about
│   │   ├── contact.php                        → GET|POST /contact
│   │   └── api/
│   │       ├── _middleware.php               → middleware herdado por `/api/*`
│   │       ├── health.php                     → GET /api/health (público)
│   │       ├── [auth]/
│   │       │   ├── users.php                  → /api/users
│   │       │   ├── users.[id].php             → /api/users/:id
│   │       │   ├── products.php               → /api/products
│   │       │   └── [admin]/
│   │       │       └── reports.php            → /api/reports (auth + admin)
│   │       └── [auth+throttle]/
│   │           └── payments.php               → /api/payments
│   │
│   ├── views/
│   │   ├── layouts/
│   │   │   ├── main.spark                     → layout padrão
│   │   │   └── admin.spark                    → layout alternativo
│   │   ├── partials/
│   │   │   ├── header.spark
│   │   │   ├── footer.spark
│   │   │   └── user/
│   │   │       ├── card.spark                 → partial('user/card')
│   │   │       └── actions.spark              → partial('user/actions')
│   │   ├── emails/
│   │   │   ├── welcome.spark
│   │   │   └── reset.spark
│   │   ├── errors/
│   │   │   ├── 404.spark
│   │   │   ├── 500.spark
│   │   │   └── 503.spark
│   │   ├── index.spark                        → view de GET /
│   │   ├── about.spark                        → view de GET /about
│   │   ├── contact.spark                      → view de GET /contact
│   │   └── users/
│   │       ├── index.spark                    → view de GET /users
│   │       └── show.spark                     → view alternativa
│   │
│   ├── models/
│   │   ├── User.php
│   │   ├── Product.php
│   │   └── Order.php
│   │
│   ├── services/
│   │   ├── AuthService.php
│   │   ├── PaymentService.php
│   │   └── MailService.php
│   │
│   ├── config/
│   │   └── app.php                           → valores opcionais lidos via config('app.*')
│   │
│   ├── middleware/
│   │   ├── auth.php
│   │   ├── admin.php
│   │   ├── throttle.php
│   │   ├── cors.php
│   │   └── csrf.php
│   │
│   ├── events/
│   │   ├── users.created.php
│   │   ├── users.deleted.php
│   │   └── order.completed.php
│   │
│   └── jobs/
│       ├── SendReport.php
│       └── CleanExpiredTokens.php
│
├── storage/
│   ├── cache/
│   │   ├── routes.php                         → mapa de rotas cacheado
│   │   ├── classes.php                        → mapa de autoloading cacheado
│   │   └── views/                             → views .spark compiladas para .php
│   ├── logs/
│   │   └── app.log
│   ├── sessions/                              → sessões (se driver=file)
│   └── uploads/                               → arquivos enviados por usuários
│
├── public/
│   ├── css/
│   │   └── app.css
│   ├── js/
│   │   └── app.js
│   ├── images/
│   ├── index.php                              → front controller (produção com Apache/Nginx)
│   └── .htaccess                              → rewrite rules (Apache)
│
├── database/
│   ├── migrations/
│   │   ├── 20260327000000_create_users_table.php
│   │   ├── 20260327010000_create_products_table.php
│   │   └── 20260327020000_create_orders_table.php
│   └── seeds/
│       ├── DatabaseSeeder.php
│       └── UserSeeder.php
│
├── core/                                      → engine do framework (não se mexe)
│   ├── Bootstrap.php
│   ├── Autoloader.php
│   ├── Router.php
│   ├── Request.php
│   ├── Response.php
│   ├── Database.php
│   ├── Model.php
│   ├── View.php
│   ├── Middleware.php
│   ├── EventEmitter.php
│   ├── Validator.php
│   ├── Container.php
│   ├── Session.php
│   ├── Cache.php
│   └── helpers.php                            → funções globais (app, env, db, etc.)
│
├── .env                                       → configuração obrigatória do projeto
├── .env.example                               → template de configuração
├── spark                                      → CLI de entrada
├── composer.json
└── README.md
```

---

## O que NÃO existe (intencionalmente)

O SparkPHP elimina por design os seguintes elementos comuns em outros frameworks:

| Elemento ausente | Justificativa |
|---|---|
| `/config/*.php` | Não existe diretório raiz de config do framework. O `.env` cobre a configuração obrigatória; `app/config/` é opcional e só agrupa valores da aplicação |
| `/bootstrap/` | O bootstrap é interno ao `core/`. O desenvolvedor não precisa tocá-lo |
| `routes/web.php` / `routes/api.php` | Rotas são definidas pelo sistema de arquivos em `app/routes/` |
| `ServiceProvider` | Serviços são resolvidos por convenção e type-hint. Sem registro manual |
| `Kernel.php` | Middleware global e por diretório existem por convenção em `app/routes/_middleware.php` e pastas da arvore |
| `AppServiceProvider` | Não existe bootstrap customizável pelo dev nessa camada |
| `RouteServiceProvider` | O Router escaneia `app/routes/` automaticamente |

---

## Convenções de resolução

### Rotas

| Arquivo | URL | Verbo |
|---|---|---|
| `app/routes/index.php` | `/` | Definido dentro do arquivo |
| `app/routes/about.php` | `/about` | Definido dentro do arquivo |
| `app/routes/api/users.php` | `/api/users` | Definido dentro do arquivo |
| `app/routes/_middleware.php` | `—` | Middleware global herdado, nao vira rota |
| `app/routes/api/_middleware.php` | `—` | Middleware herdado por `/api/*`, nao vira rota |
| `app/routes/api/users.[id].php` | `/api/users/:id` | Definido dentro do arquivo |
| `app/routes/api/[auth]/users.php` | `/api/users` | Protegido por middleware `auth` |
| `app/routes/api/[auth+throttle]/payments.php` | `/api/payments` | Protegido por `auth` e `throttle` |
| `app/routes/api/[auth]/[admin]/reports.php` | `/api/reports` | Protegido por `auth` e `admin` (aninhado) |

**Regras:**

- O path do arquivo, relativo a `app/routes/`, define a URL.
- `_middleware.php` aplica middleware herdado e nao gera rota.
- Pastas com `[colchetes]` aplicam middleware — não aparecem na URL.
- Parâmetros dinâmicos usam notação de ponto: `users.[id].php` → `/users/:id`.
- Múltiplos parâmetros: `orders.[orderId].items.[itemId].php` → `/orders/:orderId/items/:itemId`.
- Os verbos HTTP (`get`, `post`, `put`, `delete`, `patch`) são funções chamadas dentro do arquivo.

**Dentro de um arquivo de rota:**

```php
<?php
// app/routes/api/users.php

get(fn() => User::all());

post(fn() => User::create(input()));

put(fn($id) => User::find($id)->update(input()));

delete(fn($id) => User::find($id)->delete());
```

O path não é declarado. O framework já sabe pelo nome e localização do arquivo.

### Middleware

| Arquivo | Apelido |
|---|---|
| `app/middleware/auth.php` | `auth` |
| `app/middleware/admin.php` | `admin` |
| `app/middleware/throttle.php` | `throttle` |
| `app/middleware/cors.php` | `cors` |
| `app/middleware/csrf.php` | `csrf` |

**Regras:**

- O nome do arquivo (sem extensão) é o apelido.
- Middleware e aplicado por `_middleware.php`, por diretorio (`[auth]/`) ou inline (`.guard('auth')`).
- Sem registro manual em nenhum lugar.

**Dentro de um middleware:**

```php
<?php
// app/middleware/auth.php

if (!session('user')) {
    return redirect('/login');
}

// Se não retorna nada → segue adiante
// Se retorna response/redirect → bloqueia
// Se lança exceção → bloqueia com erro
```

### Models

| Arquivo | Classe | Tabela inferida |
|---|---|---|
| `app/models/User.php` | `User` | `users` |
| `app/models/Product.php` | `Product` | `products` |
| `app/models/OrderItem.php` | `OrderItem` | `order_items` |

**Regras:**

- Nome da classe em PascalCase → tabela em snake_case plural.
- Fillable é inferido das colunas da tabela (com cache).
- Timestamps são auto-detectados se `created_at` / `updated_at` existem.
- Relacionamentos são inferidos por foreign keys no schema.
- Só declara o que foge da convenção.

**Inferência de relacionamentos:**

| Coluna na tabela | Método gerado | Tipo |
|---|---|---|
| `users.company_id` | `User::company()` | `belongsTo(Company)` |
| `orders.user_id` | `User::orders()` | `hasMany(Order)` |
| tabela `role_user` | `User::roles()` | `belongsToMany(Role)` |

### Views

| Arquivo | Rota espelho |
|---|---|
| `app/views/index.spark` | `GET /` |
| `app/views/about.spark` | `GET /about` |
| `app/views/users/index.spark` | `GET /users` |
| `app/views/users/show.spark` | chamada via `view('users/show')` |

**Regras:**

- Se a rota retorna dados (array/objeto) e o request aceita HTML, o framework procura a view espelho automaticamente.
- Se a rota retorna dados e o request aceita JSON (API/fetch), retorna JSON.
- Views explícitas são chamadas com `view('nome', $dados)`.
- Layout `main.spark` é aplicado automaticamente. Troca com `@layout('outro')`.

### Events

| Arquivo | Evento |
|---|---|
| `app/events/users.created.php` | Disparado em `User::create()` |
| `app/events/users.deleted.php` | Disparado em `User::delete()` |
| `app/events/order.completed.php` | Disparado manualmente com `emit('order.completed', $data)` ou `event('order.completed', $data)` |

**Regras:**

- Padrão `{tabela}.{ação}.php` → auto-vinculado ao ciclo de vida do model.
- Nomes customizados → disparados com `emit('nome.do.evento', $dados)` ou `event('nome.do.evento', $dados)`.
- Sem registro manual.

### Services

| Arquivo | Classe | Resolução |
|---|---|---|
| `app/services/AuthService.php` | `AuthService` | Type-hint → auto-injeção |
| `app/services/PaymentService.php` | `PaymentService` | Type-hint → auto-injeção |

**Regras:**

- Classes em `app/services/` são registradas automaticamente no container.
- Dependências no construtor são resolvidas por type-hint.
- Uso em rotas: `get(fn(PaymentService $p) => $p->charge(input('amount')))`.
- Sem `bind()`, sem `singleton()`, sem registro manual.

### Jobs

| Arquivo | Classe | Uso |
|---|---|---|
| `app/jobs/SendReport.php` | `SendReport` | `dispatch(SendReport::class, $data)` |
| `app/jobs/CleanExpiredTokens.php` | `CleanExpiredTokens` | `dispatch(CleanExpiredTokens::class)` |

### Migrations

| Arquivo | Ordem |
|---|---|
| `database/migrations/20260327000000_create_users_table.php` | Primeira |
| `database/migrations/20260327010000_create_products_table.php` | Segunda |
| `database/migrations/20260327020000_create_orders_table.php` | Terceira |

**Regras:**

- Prefixo timestamp ordenável define a ordem de execução.
- Cada arquivo declara uma classe que estende `Migration`.
- O estado de execução é salvo na tabela interna `spark_migrations`.
- Executadas com `php spark migrate`.
- Rollback com `php spark migrate:rollback`.

---

## Configuração

O `.env` é a **configuração obrigatória** do projeto:

```env
# Aplicação
APP_NAME=MeuApp
APP_ENV=dev
APP_PORT=8000
APP_KEY=auto
APP_LANG=pt-BR
APP_URL=http://localhost:8000

# Banco de dados
DB=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=meu_app
DB_USER=root
DB_PASS=

# Sessão
SESSION=file

# Cache
CACHE=file

# E-mail
MAIL=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USER=
MAIL_PASS=

# Log
LOG_LEVEL=debug
```

Opcionalmente, você pode agrupar valores da aplicação em `app/config/*.php` e lê-los com `config('arquivo.chave')`. Esses arquivos não registram serviços, rotas ou middlewares; servem apenas para organizar valores da aplicação.

Drivers suportados oficialmente nesta baseline:

- `sqlite` com SQLite 3.35+
- `mysql` com MySQL 8.0+
- `pgsql` com PostgreSQL 13+

Acesso no código:

```php
env('DB_HOST');                // localhost
env('APP_ENV');                // dev
env('CUSTOM_VAR', 'default'); // com fallback
```

---

## CLI — Comandos `spark`

| Comando | Descrição |
|---|---|
| `php spark serve` | Inicia o servidor de desenvolvimento |
| `php spark serve --port=3000` | Inicia em porta customizada |
| `php spark migrate` | Executa migrations pendentes |
| `php spark migrate --seed` | Executa migrations e roda o `DatabaseSeeder` |
| `php spark migrate:rollback` | Reverte a última migration |
| `php spark db:fresh --seed` | Apaga tudo, reexecuta migrations e roda seeders |
| `php spark seed` | Executa o `DatabaseSeeder` |
| `php spark seed UserSeeder` | Executa um seeder específico |
| `php spark views:cache` | Pré-compila todas as views `.spark` |
| `php spark views:clear` | Limpa o cache de views |
| `php spark routes:cache` | Gera cache do mapa de rotas |
| `php spark routes:clear` | Limpa o cache de rotas |
| `php spark routes:list` | Lista todas as rotas registradas |
| `php spark cache:clear` | Limpa todo o cache da aplicação |
| `php spark make:model User` | Cria um model em `app/models/` |
| `php spark make:middleware auth` | Cria um middleware em `app/middleware/` |
| `php spark make:migration create_users_table` | Cria uma migration class-based com timestamp |
| `php spark make:seeder UserSeeder` | Cria um seeder em `database/seeds/` |
| `php spark make:service PaymentService` | Cria um service em `app/services/` |
| `php spark make:job SendReport` | Cria um job em `app/jobs/` |
| `php spark make:event order.completed` | Cria um evento em `app/events/` |

---

## Mapa unificado de convenções

| Camada | Convenção | Exemplo |
|---|---|---|
| Rota | caminho do arquivo = URL | `routes/api/users.php` → `/api/users` |
| Parâmetro | ponto com colchetes no nome | `users.[id].php` → `/users/:id` |
| Middleware (global) | `routes/_middleware.php` | aplica a arvore inteira |
| Middleware (dir) | `_middleware.php` ou pasta com colchetes | `api/_middleware.php` ou `[auth]/` |
| Middleware (múlt.) | `+` separa nomes | `[auth+throttle]/` |
| Middleware (inline) | `.guard()` na rota | `->guard('auth', 'throttle:30')` |
| View automática | espelho da rota | `views/about.spark` ↔ `routes/about.php` |
| View explícita | chamada com `view()` | `view('users/show', $dados)` |
| Partial | subpasta partials | `partial('user/card')` → `views/partials/user/card.spark` |
| Layout | padrão: `main.spark` | `views/layouts/main.spark` auto-aplicado |
| Layout alternativo | `@layout('nome')` | `@layout('admin')` → `views/layouts/admin.spark` |
| Model → Tabela | PascalCase → snake_case plural | `User` → `users`, `OrderItem` → `order_items` |
| Relacionamento | foreign key → método | coluna `company_id` → `$user->company` |
| Event automático | `{tabela}.{ação}.php` | `users.created.php` → auto-dispara em `User::create()` |
| Event manual | `emit()` / `event()` + nome do arquivo | `event('order.completed', $data)` |
| Service | type-hint = injeção | `fn(PaymentService $p)` → auto-resolvido |
| Migration | timestamp + classe = ordem | `20260327000000_create_users_table.php` executa primeiro |
| Erro HTTP | `views/errors/{code}.spark` | `errors/404.spark` renderizado em erro 404 |
| Configuração | `.env` é o único arquivo | `env('DB_HOST')` acessa o valor |

---

## Regra de ouro

> Se você precisa registrar, declarar ou configurar algo manualmente, é um bug no design do framework.
