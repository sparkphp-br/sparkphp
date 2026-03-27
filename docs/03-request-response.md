# Request & Response

## Request

A classe `Request` encapsula toda a informacao da requisicao HTTP. Voce pode injeta-la via type-hint ou usar os helpers globais.

### Acessando input

```php
// Via helper global
$name  = input('name');               // $_POST['name'] ou JSON body
$all   = input();                     // todos os campos
$page  = query('page', 1);           // $_GET['page'] com default

// Via objeto Request
get(function (Request $request) {
    $name = $request->input('name');
    $all  = $request->all();
    $only = $request->only(['name', 'email']);
    $rest = $request->except(['password']);
    $has  = $request->has('name');     // bool
});
```

### JSON body

Quando o `Content-Type` e `application/json`, o SparkPHP faz parse automatico:

```php
// Cliente envia: {"name": "Ana", "age": 25}
post(function () {
    $name = input('name');  // 'Ana'
    $age  = input('age');   // 25
});
```

### Query string

```php
// GET /search?q=spark&page=2
get(function () {
    $q    = query('q');        // 'spark'
    $page = query('page', 1); // 2
    $all  = query();           // ['q' => 'spark', 'page' => '2']
});
```

### Upload de arquivos

```php
post(function (Request $request) {
    $file = $request->file('avatar');

    // $file e o array padrao do PHP:
    // ['name' => '...', 'type' => '...', 'tmp_name' => '...', 'size' => ...]

    if ($file) {
        move_uploaded_file($file['tmp_name'], 'storage/uploads/' . $file['name']);
    }
});
```

### Informacoes HTTP

```php
get(function (Request $request) {
    $request->method();      // 'GET'
    $request->path();        // '/users/42'
    $request->url();         // 'http://localhost:8000/users/42?page=1'
    $request->ip();          // '127.0.0.1'
    $request->userAgent();   // 'Mozilla/5.0 ...'
    $request->isSecure();    // false (HTTPS?)

    $request->header('Authorization');          // 'Bearer xxx'
    $request->header('X-Custom', 'default');    // com fallback

    $request->cookie('session_id');
});
```

### Deteccao de tipo

```php
$request->isJson();       // Content-Type contem application/json?
$request->isAjax();       // X-Requested-With: XMLHttpRequest?
$request->acceptsJson();  // Accept contem application/json?
$request->acceptsHtml();  // Accept contem text/html?

$request->isGet();
$request->isPost();
$request->isPut();
$request->isPatch();
$request->isDelete();
```

### Helper global `method()`

```php
$m = method();  // 'GET', 'POST', 'PUT', etc. (ja resolve _method override)
```

---

## Response

A classe `Response` monta e envia a resposta HTTP. Na maioria dos casos voce **nao precisa criar um Response manualmente** — o Smart Resolver cuida disso. Mas quando precisar de controle total:

### Fabricas estaticas

```php
// JSON
return Response::json(['users' => $users]);
return Response::json(['users' => $users], 200);

// HTML
return Response::html('<h1>Hello</h1>');

// Redirect
return Response::redirect('/dashboard');
return Response::redirect('/login', 301);

// 201 Created (JSON)
return Response::created(['id' => 1, 'name' => 'Ana']);

// 204 No Content
return Response::noContent();

// 404 Not Found (JSON)
return Response::notFound('Usuario nao encontrado');

// Erro generico (JSON)
return Response::error('Algo deu errado', 500);

// Download de arquivo
return Response::download('/storage/relatorio.pdf');
return Response::download('/storage/relatorio.pdf', 'meu-relatorio.pdf');
```

### Helpers globais

```php
return json(['ok' => true]);                // Response::json(...)
return json(['ok' => true], 201);

return redirect('/login');                  // Response::redirect(...)
return back();                              // redirect para HTTP_REFERER

return created(['id' => 1]);               // 201
return noContent();                        // 204
return notFound('Nao encontrado');         // 404
return download('/path/to/file.zip');

abort(403, 'Acesso negado');               // para a execucao com status code
abort(404);
```

### Fluent API

```php
return (new Response('OK'))
    ->status(200)
    ->header('X-Custom', 'value')
    ->header('Cache-Control', 'no-store');
```

### Smart Resolver (como funciona por baixo)

Quando voce retorna um valor da rota, o `Response::resolve()` decide o que fazer:

```php
// 1. Retornou array + Accept: application/json → JSON
get(fn() => ['users' => User::all()]);

// 2. Retornou array + Accept: text/html → busca view espelho
//    GET /users → tenta renderizar app/views/users.spark
//    com as variaveis do array
get(fn() => ['users' => User::all()]);

// 3. Se a view espelho nao existe → cai pra JSON como fallback

// 4. Retornou string → HTML direto
get(fn() => '<h1>Hello</h1>');

// 5. Retornou null em GET → 404
get(fn() => null);

// 6. Retornou null em POST → 204 No Content
post(fn() => null);

// 7. POST retornando dados → status 201
post(fn() => ['id' => 1, 'name' => 'Ana']);
```

Isso permite que a **mesma rota sirva API e web** sem logica condicional.

## Proximo passo

→ [Views & Templates](04-views.md)
