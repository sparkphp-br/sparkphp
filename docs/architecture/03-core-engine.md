# SparkPHP — Core Engine (Arquitetura)

Este documento define a arquitetura interna do SparkPHP: os componentes do core, o fluxo de uma request, a estratégia de cache e como cada peça se conecta.

---

## Visão geral

O core do SparkPHP vive em `/core` e é composto por componentes independentes que se comunicam por interfaces simples. O desenvolvedor nunca edita o core — ele trabalha exclusivamente dentro de `/app`.

```
core/
├── Bootstrap.php          → orquestra o boot da aplicação
├── Autoloader.php         → escaneia e registra classes de /app
├── Router.php             → resolve URL → arquivo de rota
├── Request.php            → encapsula a request HTTP
├── Response.php           → monta e envia a response HTTP
├── Database.php           → conexão e query builder
├── Model.php              → base class para models
├── View.php               → compila e renderiza .spark
├── Middleware.php          → carrega e executa middlewares
├── EventEmitter.php       → dispara e escuta eventos
├── Validator.php          → validação de dados
├── Container.php          → injeção de dependências
├── Session.php            → gerenciamento de sessões
├── Cache.php              → cache de aplicação
└── helpers.php            → funções globais
```

---

## Fluxo de uma request

Cada request HTTP segue este caminho, da entrada à saída:

```
Request HTTP
    │
    ▼
┌──────────────┐
│  Bootstrap   │  → Carrega .env
│              │  → Registra Autoloader
│              │  → Inicializa Container
│              │  → Carrega helpers.php
│              │  → Inicializa Session
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   Router     │  → Resolve URL → arquivo em app/routes/
│              │  → Identifica parâmetros dinâmicos ([id])
│              │  → Identifica middlewares do caminho ([auth]/)
│              │  → Encontra a função do verbo HTTP (get, post, etc.)
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Middleware   │  → Executa cada middleware na ordem do caminho
│   Pipeline   │  → Se algum retorna response → para aqui
│              │  → Se todos passam → segue pro handler
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   Handler    │  → Executa a closure/função da rota
│   (Rota)     │  → Container resolve type-hints automaticamente
│              │  → Retorna dados (array, objeto, string, Response)
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Response    │  → Detecta tipo de retorno
│  Resolver    │  → Array/Objeto + Accept JSON → JSON
│              │  → Array/Objeto + Accept HTML → busca view espelho
│              │  → String → HTML direto
│              │  → null em GET → 404
│              │  → Objeto em POST → 201
│              │  → Define headers, status code, body
└──────┬───────┘
       │
       ▼
┌──────────────┐
│    View      │  → Se response é HTML:
│   Engine     │  → Localiza o .spark correspondente
│              │  → Compila diretivas → PHP
│              │  → Aplica layout (main.spark por padrão)
│              │  → Renderiza com variáveis
│              │  → Retorna HTML final
└──────┬───────┘
       │
       ▼
  Response HTTP
```

---

## Componentes do core

### 1. Bootstrap

O ponto de entrada da aplicação. Executado uma única vez no início de cada request.

**Responsabilidades:**
- Carrega e parseia o `.env` para variáveis de ambiente
- Registra o `Autoloader` com `spl_autoload_register`
- Instancia o `Container` (injeção de dependências)
- Registra os componentes do core no container como singletons
- Carrega `helpers.php` (funções globais)
- Inicializa a `Session`
- Configura error handling e exception handler
- Define timezone, locale e encoding baseado no `.env`

**Otimização:** em modo produção, o `.env` é parseado uma vez e cacheado em `storage/cache/env.php` como array PHP nativo (sem parsing a cada request).

### 2. Autoloader

Responsável por encontrar e carregar qualquer classe dentro de `/app` sem que o desenvolvedor escreva `use` ou `require`.

**Funcionamento:**

1. No boot, escaneia recursivamente todos os diretórios de `/app` (exceto `/routes`).
2. Para cada arquivo `.php`, extrai o nome da classe.
3. Monta um mapa `['NomeDaClasse' => '/caminho/do/arquivo.php']`.
4. Registra via `spl_autoload_register`: quando o PHP tenta usar uma classe não carregada, o autoloader consulta o mapa e faz o `require`.

**Cache:** o mapa é serializado em `storage/cache/classes.php`. Em produção, o scan de diretórios não acontece — usa o cache direto. Regenerado com `php spark cache:clear`.

**Conflito de nomes:** se duas classes têm o mesmo nome em diretórios diferentes, o autoloader lança um erro claro no boot indicando o conflito e os caminhos dos dois arquivos.

### 3. Router

Resolve a URL da request para o arquivo de rota correspondente e identifica middlewares no caminho.

**Funcionamento:**

1. Escaneia `app/routes/` recursivamente.
2. Para cada arquivo, converte o caminho em padrão de URL:
   - `routes/api/users.php` → `/api/users`
   - `routes/api/users.[id].php` → `/api/users/([^/]+)` (regex)
   - Pastas `[auth]/` são ignoradas no path mas registradas como middleware
3. Monta um mapa com: URL pattern, arquivo, middlewares, parâmetros.

**Resolução de request:**

1. Recebe a URL e o método HTTP.
2. Percorre o mapa buscando match (exato primeiro, regex depois).
3. Extrai parâmetros dinâmicos.
4. Carrega o arquivo da rota.
5. Dentro do arquivo, procura a chamada da função correspondente ao verbo (`get()`, `post()`, etc.).
6. Retorna: handler (closure), parâmetros, lista de middlewares.

**Cache:** o mapa completo é cacheado em `storage/cache/routes.php`. Regenerado com `php spark routes:cache`.

**Prioridade de rotas:**
- Rotas exatas têm prioridade sobre rotas com parâmetros.
- `users.php` vence `users.[id].php` para a URL `/api/users`.
- `users.[id].php` captura `/api/users/123`.

### 4. Request

Encapsula a request HTTP atual com uma API limpa.

**API pública (acessível via helpers globais):**

```php
input('name')              // $_POST['name'] ou JSON body
input('name', 'default')   // com fallback
input()                    // todos os inputs como array
query('page')              // $_GET['page']
query('page', 1)           // com fallback
header('Authorization')    // header da request
method()                   // GET, POST, PUT, etc.
url()                      // URL completa
path()                     // path sem query string
isJson()                   // true se Content-Type é JSON
isAjax()                   // true se X-Requested-With: XMLHttpRequest
acceptsJson()              // true se Accept contém application/json
acceptsHtml()              // true se Accept contém text/html
ip()                       // IP do cliente
file('avatar')             // arquivo uploaded
has('name')                // true se o campo existe no input
cookie('token')            // valor do cookie
```

**Detecção de input:** o `input()` unifica `$_POST`, `$_GET` e JSON body. Se o `Content-Type` é `application/json`, decodifica o body automaticamente. `$_POST` tem prioridade sobre `$_GET` em caso de conflito.

### 5. Response

Monta e envia a response HTTP. Funciona como resolver inteligente do retorno da rota.

**Resolução automática de retorno:**

| Retorno da rota | Accept Header | Resultado |
|---|---|---|
| `array` ou `object` | `application/json` | JSON 200 |
| `array` ou `object` | `text/html` | Busca view espelho → HTML 200 |
| `null` (em GET) | qualquer | 404 |
| `object` (em POST) | qualquer | JSON 201 |
| `string` | qualquer | HTML 200 |
| `Response` object | qualquer | Envia como definido |

**Helpers de response:**

```php
json($data, $status)       // response JSON explícita
redirect('/url')           // redirect 302
redirect('/url', 301)      // redirect permanente
created($data)             // 201 com dados
noContent()                // 204 sem body
notFound('mensagem')       // 404
abort(403)                 // erro HTTP com status
download('path/file.pdf')  // response de download
```

### 6. Database

Camada de acesso ao banco. Conexão configurada automaticamente via `.env`.

**Query builder:**

```php
db('users')->all()
db('users')->find($id)
db('users')->where('active', true)->get()
db('users')->where('age', '>', 18)->orderBy('name')->limit(10)->get()
db('users')->create(['name' => 'João', 'email' => 'j@mail.com'])
db('users')->where('id', $id)->update(['name' => 'Novo Nome'])
db('users')->where('id', $id)->delete()
db('users')->count()
db('users')->sum('balance')
db('users')->paginate(15)
```

**Raw queries:**

```php
db()->raw('SELECT * FROM users WHERE age > ?', [18])
db()->transaction(function() {
    db('accounts')->where('id', 1)->decrement('balance', 100);
    db('accounts')->where('id', 2)->increment('balance', 100);
})
```

**Conexão:** usa PDO internamente. A conexão é lazy — só abre quando a primeira query é executada.

### 7. Model

Classe base para models em `app/models/`.

**O que é automático:**
- Nome da tabela: `User` → `users`, `OrderItem` → `order_items`
- Fillable: inferido das colunas da tabela (cacheado)
- Timestamps: detectados se `created_at`/`updated_at` existem
- Relacionamentos: inferidos das foreign keys

**O que o dev declara (só se necessário):**

```php
class User extends Model {
    // Só se o nome da tabela foge da convenção
    protected $table = 'tb_usuarios';

    // Só se quiser restringir (por padrão aceita todas as colunas)
    protected $fillable = ['name', 'email'];

    // Só se quiser bloquear campos específicos
    protected $guarded = ['is_admin'];

    // Só se não quiser timestamps
    protected $timestamps = false;

    // Métodos customizados
    public function fullName(): string {
        return "{$this->first_name} {$this->last_name}";
    }
}
```

**Relacionamentos automáticos:**

Quando o schema possui uma coluna `company_id` na tabela `users`, o framework gera automaticamente `User::company()` como `belongsTo`. Quando `orders` tem `user_id`, gera `User::orders()` como `hasMany`. Tabelas pivot no padrão `{tabela1}_{tabela2}` (ordem alfabética) geram `belongsToMany`.

Relacionamentos explícitos (quando a convenção não resolve):

```php
class User extends Model {
    public function supervisor() {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
```

**Ciclo de vida e events:**

Ao executar `User::create($data)`, o model verifica se existe `app/events/users.created.php` e o dispara automaticamente após a inserção, passando o model criado como `$data`.

### 8. View (Spark Template Engine)

O compilador que transforma `.spark` em PHP executável.

**Processo de compilação:**

1. Lê o arquivo `.spark`.
2. Processa diretivas de metadados: `@title`, `@layout`, `@bodyClass`, `@css`, `@js`.
3. Compila diretivas → código PHP:
   - `{{ $var }}` → `<?php echo escape($var, $context) ?>`
   - `{{ $var | pipe }}` → `<?php echo pipe($var, 'pipe') ?>`
   - `@if(...)` → `<?php if (...): ?>`
   - `@foreach(...)` → `<?php foreach (...): ?>`
   - `@partial('x')` → `<?php echo $__view->partial('x') ?>`
   - etc.
4. Salva o PHP compilado em `storage/cache/views/`.
5. Na renderização, faz `include` do PHP compilado passando as variáveis via `extract()`.
6. Envolve o resultado no layout, substituindo `@content`.

**Escape por contexto:**

O compilador analisa onde a expressão `{{ }}` aparece no HTML e aplica o escape adequado:

| Contexto | Escape aplicado |
|---|---|
| Conteúdo HTML (`<p>{{ $x }}</p>`) | `htmlspecialchars()` |
| Atributo HTML (`href="{{ $x }}"`) | `htmlspecialchars()` + URL encode se href/src |
| Dentro de `<script>` | `json_encode()` + escape JS |
| Dentro de `style` | sanitização CSS |

**Cache:** em modo dev, verifica o timestamp do `.spark` e recompila se mudou. Em produção, usa cache direto. `php spark views:cache` pré-compila tudo.

### 9. Middleware

Carrega e executa middlewares como pipeline.

**Funcionamento:**

1. O Router identifica quais middlewares se aplicam (por diretório e por `.guard()`).
2. O Middleware engine carrega cada arquivo de `app/middleware/`.
3. Executa em sequência (pipeline).
4. Se algum retorna uma response (redirect, json, abort) → interrompe.
5. Se todos passam sem retorno → a request chega ao handler da rota.

**Middleware com parâmetros:**

```php
// Uso: ->guard('throttle:30') ou pasta [throttle:30]/
// app/middleware/throttle.php recebe $params = ['30']

$limit = $params[0] ?? 60;
$key = 'throttle:' . ip();

if (cache()->get($key) >= $limit) {
    return json(['error' => 'Too many requests'], 429);
}

cache()->increment($key);
cache()->expire($key, 60);
```

### 10. EventEmitter

Sistema de eventos baseado em convenção de arquivos.

**Eventos automáticos (ciclo de vida do model):**

| Arquivo | Quando dispara |
|---|---|
| `users.creating.php` | Antes de `User::create()` |
| `users.created.php` | Após `User::create()` |
| `users.updating.php` | Antes de `User::update()` |
| `users.updated.php` | Após `User::update()` |
| `users.deleting.php` | Antes de `User::delete()` |
| `users.deleted.php` | Após `User::delete()` |

**Eventos manuais:**

```php
emit('order.completed', $order);
emit('payment.failed', ['order_id' => $id, 'reason' => $reason]);
```

Dispara `app/events/order.completed.php` passando o segundo argumento como `$data`.

**Eventos `.before` (canceláveis):**

Se o evento `*.creating` ou `*.updating` retorna `false`, a operação é cancelada.

### 11. Validator

Validação de dados com sintaxe compacta.

**Uso:**

```php
$data = validate([
    'name'  => 'required|min:3|max:255',
    'email' => 'required|email|unique:users',
    'age'   => 'optional|int|between:18,120',
    'role'  => 'required|in:admin,user,editor',
    'avatar'=> 'optional|file|image|max_size:2mb',
]);
```

Se a validação falha:
- Em request JSON → retorna 422 com array de erros.
- Em request HTML → redireciona back com `old()` e `errors()` na session.

**Regras disponíveis:**

| Regra | Descrição |
|---|---|
| `required` | Campo obrigatório |
| `optional` | Campo opcional (pula validação se vazio) |
| `string` | Deve ser string |
| `int` | Deve ser inteiro |
| `float` | Deve ser float |
| `bool` | Deve ser booleano |
| `email` | Formato de e-mail válido |
| `url` | Formato de URL válida |
| `min:N` | Mínimo de N caracteres (string) ou valor (número) |
| `max:N` | Máximo de N caracteres ou valor |
| `between:N,M` | Entre N e M |
| `in:a,b,c` | Deve ser um dos valores listados |
| `unique:table` | Único na tabela |
| `unique:table,column` | Único na coluna específica |
| `exists:table` | Deve existir na tabela |
| `confirmed` | Deve ter campo `{name}_confirmation` igual |
| `date` | Formato de data válido |
| `before:date` | Antes da data |
| `after:date` | Após a data |
| `file` | Deve ser arquivo uploaded |
| `image` | Deve ser imagem (jpg, png, gif, webp) |
| `max_size:2mb` | Tamanho máximo do arquivo |
| `regex:/pattern/` | Match com expressão regular |

### 12. Container

Injeção de dependências automática.

**Funcionamento:**

1. O Autoloader registra todas as classes de `app/services/` e `app/models/`.
2. Quando uma rota declara type-hints nos parâmetros da closure, o Container resolve:
   - Tipos primitivos com nome de parâmetro de rota → injeta o valor da URL.
   - Classes → instancia, resolvendo dependências do construtor recursivamente.
3. Singletons: serviços são instanciados uma vez e reutilizados na mesma request.

**Exemplo de resolução:**

```php
// app/routes/api/payments.php
post(fn(PaymentService $payment, int $amount) => $payment->charge($amount));

// O Container:
// 1. Vê PaymentService → instancia
// 2. PaymentService pede MailService no construtor → instancia
// 3. Vê int $amount → pega de input('amount')
```

### 13. Session

Gerenciamento de sessões com API simples.

```php
session('key')                // ler
session('key', 'default')     // ler com fallback
session(['key' => 'value'])   // escrever
session()->forget('key')      // remover
session()->flush()            // limpar tudo
session()->flash('msg', 'Salvo!')  // dado que dura 1 request
```

**Drivers:** `file` (padrão), `database`, `redis`. Configurado no `.env` com `SESSION=file`.

### 14. Cache

Cache de aplicação com a mesma API simples.

```php
cache('key')                  // ler
cache('key', 'default')       // ler com fallback
cache(['key' => 'value'])     // escrever (sem TTL)
cache(['key' => 'value'], 3600)  // escrever com TTL em segundos
cache()->forget('key')        // remover
cache()->flush()              // limpar tudo
cache()->increment('key')     // incrementar
cache()->decrement('key')     // decrementar
```

**Drivers:** `file` (padrão), `redis`, `memory`. Configurado no `.env` com `CACHE=file`.

### 15. Helpers (funções globais)

Todas as funções globais disponíveis em qualquer arquivo do projeto:

| Helper | Descrição |
|---|---|
| `app()` | Instância da aplicação |
| `env('KEY', 'default')` | Variável de ambiente |
| `db('table')` | Query builder para a tabela |
| `input('field')` | Dado do request |
| `query('field')` | Parâmetro da query string |
| `session('key')` | Sessão |
| `cache('key')` | Cache |
| `auth()` | Usuário autenticado ou null |
| `view('name', $data)` | Renderiza view explícita |
| `redirect('/url')` | Response de redirect |
| `json($data, $status)` | Response JSON |
| `abort($code)` | Interrompe com erro HTTP |
| `validate($rules)` | Valida input e retorna dados limpos |
| `emit('event', $data)` | Dispara evento |
| `dispatch(Job::class, $data)` | Despacha job |
| `old('field')` | Valor anterior do input |
| `errors('field')` | Erro de validação do campo |
| `csrf()` | Token CSRF atual |
| `url('/path')` | URL absoluta |
| `asset('path')` | URL de asset público |
| `config('app.key')` | Lê valores opcionais de `app/config/*.php` |
| `now()` | Data/hora atual como Carbon-like |
| `dump($var)` | Debug: exibe variável formatada |
| `dd($var)` | Debug: dump and die |
| `logger('message')` | Escreve no log |
| `encrypt($data)` | Encripta string |
| `decrypt($data)` | Decripta string |
| `hash_password($password)` | Hash de senha (bcrypt) |
| `verify($password, $hash)` | Verifica hash |

---

## Estratégia de cache

O SparkPHP usa cache agressivo em produção para minimizar trabalho repetitivo:

| Cache | Arquivo | Gerado por | Conteúdo |
|---|---|---|---|
| Env | `storage/cache/env.php` | Boot (auto) | `.env` parseado como array PHP |
| Classes | `storage/cache/classes.php` | `php spark cache:clear` (regenera) | Mapa classe → caminho |
| Rotas | `storage/cache/routes.php` | `php spark routes:cache` | Mapa URL → arquivo + middlewares |
| Views | `storage/cache/views/*.php` | `php spark views:cache` | Templates `.spark` compilados |
| Schema | `storage/cache/schema.php` | Boot (auto, se não existe) | Colunas e FK de cada tabela |

**Em modo dev (`APP_ENV=dev`):**
- Env é parseado a cada request (sem cache).
- Classes são escaneadas a cada request (sem cache).
- Rotas são escaneadas a cada request (sem cache).
- Views são recompiladas se o `.spark` foi modificado.
- Schema é cacheado (só muda com migration).

**Em modo produção (`APP_ENV=production`):**
- Tudo usa cache. Nenhum scan de diretório acontece em runtime.
- Deploy exige: `php spark optimize` (gera todos os caches de uma vez).

---

## Ciclo de vida completo (resumo)

```
1. php spark serve (ou Nginx/Apache apontando pro public/index.php)
2. spark → require vendor/autoload.php → core/Bootstrap.php
3. Bootstrap:
   a. Parseia .env
   b. Registra Autoloader (scan /app ou usa cache)
   c. Inicializa Container
   d. Carrega helpers.php
   e. Inicializa Session
4. app()->run()
5. Router resolve URL → arquivo de rota + middlewares + params
6. Middleware pipeline executa na ordem
7. Handler da rota executa (Container resolve type-hints)
8. Response Resolver detecta tipo de retorno
9. Se HTML: View engine compila .spark → renderiza com layout
10. Response enviada ao cliente
```
