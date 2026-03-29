# Middleware

Middlewares no SparkPHP sao **arquivos PHP simples** em `app/middleware/`. O nome do arquivo e o alias. Sem classes, sem registro.

## Middleware incluidos

| Arquivo          | Alias      | O que faz                                    |
|------------------|------------|----------------------------------------------|
| `auth.php`       | `auth`     | Redireciona para `/login` se nao autenticado |
| `csrf.php`       | `csrf`     | Verifica token CSRF em POST/PUT/PATCH/DELETE |
| `cors.php`       | `cors`     | Define headers CORS e trata preflight        |
| `throttle.php`   | `throttle` | Rate limiting por IP (default: 60 req/min)   |

## Criando um middleware

Crie um arquivo em `app/middleware/`:

```php
// app/middleware/admin.php
<?php

if (!auth() || !auth()->is_admin) {
    return json(['error' => 'Forbidden'], 403);
}

// Retornar null (ou nada) = continuar para a rota
```

**Regra**: se o middleware retorna algo (Response, array, string), a execucao **para ali**. Se retorna `null` ou nao retorna nada, a requisicao **segue para a rota**.

## Aplicando middlewares

### 1. Middleware global

Voce pode aplicar middleware em **todas** as rotas com `app/routes/_middleware.php`:

```php
// app/routes/_middleware.php
<?php

return ['cors'];
```

O arquivo pode retornar:

- `null` para nao aplicar nada
- uma `string` com um alias
- um `array` com aliases e parametros, como `['auth', 'throttle:60']`

Esse arquivo e uma convencao do Router. Ele **nao vira rota** e **nao aparece na URL**.

### 2. Middleware por diretorio com `_middleware.php`

Cada pasta pode ter seu proprio `_middleware.php`:

```php
// app/routes/api/_middleware.php
<?php

return ['auth'];
```

```php
// app/routes/api/admin/_middleware.php
<?php

return ['role:admin'];
```

Tudo que estiver dentro dessas pastas herda os middlewares na ordem em que as pastas sao percorridas.

### 3. Middleware por diretorio via pastas entre colchetes

Voce tambem pode aplicar middleware por estrutura de pastas:

```text
app/routes/[auth]/dashboard.php
app/routes/api/[auth+throttle]/payments.php
app/routes/api/[auth]/[admin]/reports.php
```

Regras:

- Pastas como `[auth]` aplicam middleware e nao aparecem na URL
- Voce pode combinar varios middlewares: `[auth+throttle]`
- Pastas aninhadas acumulam middleware na ordem em que aparecem

### 4. Guard inline (por rota)

```php
// app/routes/admin/settings.php

get(fn() => ['settings' => true])->guard('auth', 'csrf');

post(fn() => ['saved' => true])->guard('auth', 'csrf', 'throttle:10');
```

O `guard()` e sempre aplicado **depois** dos middlewares herdados da arvore de rotas.

## Middleware com parametros

Use a sintaxe `alias:param1,param2` e acesse via `$params`:

```php
// app/middleware/throttle.php
<?php

$limit = (int) ($params[0] ?? 60);
$key   = 'throttle:' . ip();

$current = (int) (cache($key) ?? 0);

if ($current >= $limit) {
    return json(['error' => 'Too many requests. Please slow down.'], 429);
}

cache([$key => $current + 1], 60);
```

Uso:

```php
get(fn() => 'ok')->guard('throttle:30');   // 30 req/min
get(fn() => 'ok')->guard('throttle:120');  // 120 req/min
```

## Middleware com role

```php
// app/middleware/role.php
<?php

$role = $params[0] ?? null;

if (!auth() || auth()->role !== $role) {
    abort(403);
}
```

Uso:

```php
get(fn() => 'admin area')->guard('auth', 'role:admin');
get(fn() => 'editor area')->guard('auth', 'role:editor');
```

## Exemplo completo: CORS

```php
// app/middleware/cors.php
<?php

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';
$allowed = env('CORS_ORIGIN', '*');

header('Access-Control-Allow-Origin: ' . ($allowed === '*' ? '*' : $origin));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
```

## Exemplo completo: Auth

```php
// app/middleware/auth.php
<?php

if (!auth()) {
    $request = request();

    if ($request->acceptsJson()) {
        return json(['error' => 'Unauthenticated.'], 401);
    }

    return redirect('/login');
}
```

Note como o mesmo middleware funciona para APIs (retorna JSON 401) e web (redireciona para login).

## Ordem de execucao

1. `app/routes/_middleware.php`
2. Cada `_middleware.php` das pastas percorridas, da raiz para a folha
3. Pastas com `[auth]`, `[auth+throttle]`, etc., na ordem em que aparecem
4. Guards da rota (`.guard('auth', 'csrf')`)
5. Handler da rota

### Exemplo de composicao

```text
app/routes/_middleware.php                  -> ['cors']
app/routes/api/_middleware.php              -> ['auth']
app/routes/api/[admin]/reports.php          -> guard('throttle:10')
```

A ordem efetiva sera:

```php
['cors', 'auth', 'admin', 'throttle:10']
```

Se o mesmo middleware aparecer mais de uma vez, o Spark preserva a **primeira ocorrencia** e remove duplicatas.

## Proximo passo

→ [Authentication](08-authentication.md)
