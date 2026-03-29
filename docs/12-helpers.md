# Helpers (Funcoes Globais)

O SparkPHP fornece dezenas de funcoes globais para que voce nao precise importar nada. Todas estao disponiveis em rotas, middlewares, views e qualquer lugar do app.

## Aplicacao

| Funcao              | Descricao                                       |
|---------------------|-------------------------------------------------|
| `env('KEY')`        | Le variavel do `.env` (com cache em production) |
| `env('KEY', 'def')` | Com valor default                               |
| `config('app.name')`| Le valores opcionais de `app/config/*.php` com dot-notation |
| `app()`             | Instancia atual de `Bootstrap`                  |
| `app_path('x')`     | Caminho absoluto para `app/x`                   |
| `base_path('x')`    | Caminho absoluto para a raiz do projeto/`x`     |
| `storage_path('x')` | Caminho absoluto para `storage/x`               |
| `public_path('x')`  | Caminho absoluto para `public/x`                |
| `url('/path')`      | URL completa: `APP_URL` + `/path`               |

## Request & Input

| Funcao                  | Descricao                                     |
|-------------------------|-----------------------------------------------|
| `input('key')`          | Valor do body (POST/JSON)                     |
| `input('key', 'def')`   | Com fallback                                  |
| `input()`               | Todos os campos do body                       |
| `query('key')`          | Valor da query string                         |
| `query('key', 'def')`   | Com fallback                                  |
| `query()`               | Toda a query string como array                |
| `method()`              | Metodo HTTP (ja resolve `_method`)            |
| `ip()`                  | IP do cliente                                 |
| `request()`             | Instancia de `Request`                        |

## Response

| Funcao                          | Descricao                          |
|---------------------------------|------------------------------------|
| `json($data, $status)`         | Response JSON                      |
| `stream($callback, $status)`   | Response streamed                  |
| `redirect('/url')`             | Redirect 302                       |
| `redirect('/url', 301)`        | Redirect com status customizado    |
| `back()`                       | Redirect para HTTP_REFERER         |
| `created($data)`               | Response 201 JSON                  |
| `noContent()`                  | Response 204                       |
| `notFound('msg')`              | Response 404 JSON                  |
| `download('/path')`            | Response de download de arquivo    |
| `download('/path', 'nome.pdf')`| Com nome customizado               |
| `abort(403)`                   | Para execucao com status code      |
| `abort(404, 'Nao encontrado')` | Com mensagem                       |

## Views

| Funcao                                     | Descricao                                   |
|--------------------------------------------|---------------------------------------------|
| `view('name', ['key' => 'val'])`           | Renderiza view `.spark`                     |
| `view('users.index', $data)`              | Dot-notation para subdiretorios             |

## Database

| Funcao              | Descricao                                     |
|---------------------|-----------------------------------------------|
| `db('table')`       | QueryBuilder para a tabela                    |
| `db()`              | Instancia do Database                         |

## Session

| Funcao                          | Descricao                              |
|---------------------------------|----------------------------------------|
| `session('key')`                | Le valor da sessao                     |
| `session('key', 'def')`        | Com fallback                           |
| `session(['k' => 'v'])`        | Escreve na sessao                      |
| `flash('key', 'val')`          | Seta flash data                        |
| `flash('key')`                 | Le flash data da requisicao atual      |
| `old('field')`                 | Valor antigo de input (pos-validacao)  |
| `session_regenerate()`         | Regenera ID da sessao                  |

## Cache

| Funcao                                    | Descricao                                |
|-------------------------------------------|------------------------------------------|
| `cache('key')`                            | Le do cache                              |
| `cache('key', 'def')`                    | Com fallback                             |
| `cache(['key' => 'val'], $ttl)`          | Escreve no cache (TTL em segundos)       |
| `cache_remember('key', $ttl, $callback)` | Le ou gera e cacheia                     |
| `cache_flush()`                           | Limpa todo o cache                       |

## Auth

| Funcao          | Descricao                           |
|-----------------|-------------------------------------|
| `auth()`        | Usuario logado ou `null`            |
| `login($user)`  | Loga usuario                        |
| `logout()`      | Desloga                             |
| `policy($subject)` | Resolve a policy por convencao   |
| `can('update', $post)` | Verifica autorizacao         |
| `authorize('update', $post)` | Aborta com 403 se negar |
| `authorize('view', $post, $user)` | Usa actor explicito |

## Seguranca

| Funcao              | Descricao                           |
|---------------------|-------------------------------------|
| `csrf()`            | Token CSRF atual                    |
| `preventRequestForgery()` | Retorna `Response` 419 ou `null` |
| `verifyCsrf()`      | Alias legado que envia 419 se invalido |
| `e($string)`        | `htmlspecialchars` (XSS protection) |
| `uuid()`            | Gera UUID v4                        |

## Validacao

| Funcao                                    | Descricao                          |
|-------------------------------------------|------------------------------------|
| `validate($rules)`                        | Valida input e retorna dados       |
| `validate($rules, $messages)`             | Com mensagens customizadas         |
| `errors()`                                | Array de erros da ultima validacao |

## Events & Jobs

| Funcao                                      | Descricao                             |
|---------------------------------------------|---------------------------------------|
| `emit('Name', $data)`                       | Dispara evento                        |
| `event('Name', $data)`                      | Alias de `emit()`                     |
| `on('Name', $callback)`                     | Registra listener in-memory           |
| `off('Name', $callback)`                    | Remove listener                       |
| `dispatch(Job::class, $data)`               | Despacha usando a rota/default do job |
| `dispatch(Job::class, $data, 'emails')`     | Despacha forçando uma fila especifica |
| `dispatch_later(Job::class, $data, $delay)` | Despacha com delay (segundos)         |
| `queue()`                                   | Retorna a instancia de `Queue`        |
| `queue(Job::class, $data)`                  | Enfileira manualmente                 |

As helpers de job respeitam a configuracao final resolvida pelo Spark:

- defaults internos
- `app/jobs/_queue.php`
- propriedades / atributos da classe do job
- fila informada inline no `dispatch(..., 'emails')`

## Mail

| Funcao    | Descricao                                  |
|-----------|--------------------------------------------|
| `mailer()` | Nova instancia do Mailer (fluent API)     |

## Logging

| Funcao                      | Descricao                                |
|-----------------------------|------------------------------------------|
| `log_debug('msg', $ctx)`   | Log nivel debug                          |
| `log_info('msg', $ctx)`    | Log nivel info                           |
| `log_notice('msg', $ctx)`  | Log nivel notice                         |
| `log_warning('msg', $ctx)` | Log nivel warning                        |
| `log_error('msg', $ctx)`   | Log nivel error                          |
| `log_critical('msg', $ctx)`| Log nivel critical                       |

Logs sao gravados em `storage/logs/spark-YYYY-MM-DD.log`.

O nivel minimo e controlado por `LOG_LEVEL` no `.env`.

## Markdown

| Funcao                                              | Descricao                                      |
|-----------------------------------------------------|-------------------------------------------------|
| `markdown($text)`                                   | Converte Markdown em HTML                       |
| `markdown($text, copyable(['php', 'bash']))`            | Com botao copiar nos blocos dessas linguagens    |
| `markdown($text, copyable(['*']))`                      | Com botao copiar em todos os blocos de codigo    |
| `copyable(['php', 'bash', 'js'])`                       | Helper que define quais linguagens tem copiar    |

### Exemplo em rota

```php
// app/routes/docs.[slug].php
get(function (string $slug) {
    $raw = file_get_contents("docs/{$slug}.md");

    return view('docs/show', [
        'content' => markdown($raw, copyable(['php', 'bash', 'env', 'html'])),
    ]);
});
```

### Exemplo em view

```html
<!-- Renderiza HTML do markdown com output nao-escapado -->
{!! $content !!}

<!-- Ou inline via pipe -->
{{ $post->body | markdown }}
```

### Copiar codigo

Quando `copy()` e passado, blocos de codigo fenced que correspondem as linguagens listadas recebem automaticamente:

- Label da linguagem no header do bloco (ex: `PHP`, `BASH`)
- Botao "Copiar" com feedback visual ("Copiado!") ao clicar
- Copia o conteudo do bloco para a area de transferencia via `navigator.clipboard`

```php
// Copiar apenas em blocos PHP e Bash
markdown($text, copyable(['php', 'bash']))

// Copiar em TODOS os blocos, independente da linguagem
markdown($text, copyable(['*']))

// Sem copiar (padrao)
markdown($text)
```

## Debug

| Funcao        | Descricao                                     |
|---------------|-----------------------------------------------|
| `dd($vars)`   | Dump & die — exibe variaveis e para execucao  |
| `dump($vars)` | Dump sem parar execucao                       |

## Rotas (dentro de arquivos de rota)

| Funcao                  | Descricao                        |
|-------------------------|----------------------------------|
| `get($handler)`         | Handler para GET                 |
| `post($handler)`        | Handler para POST                |
| `put($handler)`         | Handler para PUT                 |
| `patch($handler)`       | Handler para PATCH               |
| `delete($handler)`      | Handler para DELETE              |
| `any($handler)`         | Handler para todos os metodos    |

## Migrations (dentro de arquivos de migration)

| Funcao           | Descricao                    |
|------------------|------------------------------|
| `up($callback)`  | Define o que a migration faz |
| `down($callback)`| Define o rollback            |

## Proximo passo

→ [CLI](13-cli.md)
