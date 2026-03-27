# Routing

O SparkPHP usa **file-based routing**: o caminho do arquivo dentro de `app/routes/` define a URL. Sem declaracao manual, sem registro, sem arrays de rotas.

## Como funciona

| Arquivo                          | URL resultante     |
|----------------------------------|--------------------|
| `app/routes/index.php`           | `/`                |
| `app/routes/about.php`           | `/about`           |
| `app/routes/api/health.php`      | `/api/health`      |
| `app/routes/users.php`           | `/users`           |
| `app/routes/users.[id].php`      | `/users/:id`       |
| `app/routes/posts.[slug].php`    | `/posts/:slug`     |

- `index.php` vira a raiz daquele diretorio
- `[nome]` no nome do arquivo vira parametro dinamico
- Subdiretorios viram segmentos de URL

## Definindo handlers por verbo HTTP

Dentro de cada arquivo de rota, use as funcoes globais `get()`, `post()`, `put()`, `patch()`, `delete()` ou `any()`:

```php
// app/routes/users.php

// GET /users — listar
get(fn() => User::all());

// POST /users — criar
post(function () {
    $data = validate([
        'name'  => 'required|min:3',
        'email' => 'required|email|unique:users,email',
    ]);

    return User::create($data);
});
```

Cada funcao recebe uma closure. Voce pode ter varios verbos no mesmo arquivo.

## Parametros dinamicos

Parametros sao definidos com `[nome]` no nome do arquivo:

```php
// app/routes/users.[id].php

// GET /users/42
get(fn(int $id) => User::findOrFail($id));

// PUT /users/42
put(fn(int $id) => User::findOrFail($id)->update(input()));

// DELETE /users/42
delete(fn(int $id) => User::findOrFail($id)->delete());
```

O nome do parametro na closure **deve coincidir** com o nome no arquivo (`$id` para `[id]`). O Container faz a injecao automatica e a conversao de tipo (`int`, `string`, etc.).

### Multiplos parametros

```php
// app/routes/teams.[teamId].members.[memberId].php
// URL: /teams/5/members/12

get(fn(int $teamId, int $memberId) => [
    'team'   => $teamId,
    'member' => $memberId,
]);
```

## Injecao de dependencias

O handler resolve automaticamente type-hints do Container:

```php
// app/routes/dashboard.php

get(function (Request $request, Session $session) {
    return [
        'user'   => auth(),
        'method' => $request->method(),
        'lang'   => $session->get('locale', 'pt-BR'),
    ];
});
```

Misture parametros de URL com servicos livremente:

```php
// app/routes/users.[id].php

get(fn(int $id, Request $request) => [
    'user'   => User::findOrFail($id),
    'format' => $request->query('format', 'json'),
]);
```

## Guard (middleware inline)

Alem de middlewares por diretorio (veja [Middleware](07-middleware.md)), voce pode aplicar guards diretamente na rota:

```php
// app/routes/admin/settings.php

get(fn() => ['settings' => true])->guard('auth', 'csrf');

post(fn() => ['saved' => true])->guard('auth', 'csrf', 'throttle:10');
```

O `guard()` e encadeavel e aceita multiplos middlewares:

```php
get(fn() => 'ok')->guard('auth')->guard('throttle:30');
```

## Retorno automatico (Smart Resolver)

O que voce retorna da closure define a response automaticamente:

| Retorno                  | Comportamento                                              |
|--------------------------|------------------------------------------------------------|
| `array` ou `object`     | JSON se `Accept: application/json`, senao busca view espelho |
| `string`                | HTML direto                                                |
| `Response`              | Enviado como esta                                          |
| `null` em GET           | 404                                                        |
| `null` em POST/PUT/etc  | 204 No Content                                             |
| `true`                  | 204 No Content                                             |
| `'redirect:/url'`       | Redirect 302                                               |

### Exemplos praticos

```php
// Retorna JSON para APIs, view para browsers
get(fn() => ['users' => User::all()]);

// Retorna HTML direto
get(fn() => '<h1>Hello</h1>');

// Retorna Response explicitamente
get(fn() => Response::json(['ok' => true], 200));

// Redireciona
get(fn() => 'redirect:/login');

// Redirect usando helper
post(function () {
    // ... processa ...
    return redirect('/dashboard');
});
```

## Rota catch-all (any)

```php
// app/routes/webhook.php

any(fn() => ['received' => true]);
```

Responde a GET, POST, PUT, PATCH e DELETE.

## Method override (PUT/PATCH/DELETE em formularios)

Formularios HTML so suportam GET e POST. Para PUT, PATCH ou DELETE, adicione `_method`:

```html
<form method="POST" action="/users/42">
    <input type="hidden" name="_method" value="DELETE">
    <input type="hidden" name="_csrf" value="{{ csrf() }}">
    <button type="submit">Excluir</button>
</form>
```

Ou no Spark Template:

```
@form('/users/42', 'DELETE')
    @submit('Excluir')
@endform
```

O `@form` gera automaticamente o campo `_method` e o token CSRF.

## 405 Method Not Allowed

Se a URL existe mas o verbo nao esta definido, o SparkPHP retorna 405 com o header `Allow` listando os metodos aceitos:

```
HTTP/1.1 405 Method Not Allowed
Allow: GET, POST
```

## Prioridade de rotas

Rotas exatas tem prioridade sobre rotas com parametros:

```
app/routes/users.php          → /users      (prioridade 1 — exata)
app/routes/users.[id].php     → /users/:id  (prioridade 10 — parametro)
```

`GET /users` casa com `users.php`, nunca com `users.[id].php`.

## Listando rotas

```bash
php spark routes:list
```

Saida:

```
  URL                                     Middlewares                   File
  ────────────────────────────────────────────────────────────────────────────
  /api/health                             —                            api/health.php
  /                                       —                            index.php
```

## Proximo passo

→ [Request & Response](03-request-response.md)
