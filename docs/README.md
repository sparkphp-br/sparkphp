# SparkPHP — Documentacao

**Write what matters.** Framework PHP minimalista, file-based, zero-config.

Baseline atual: PHP 8.3+, SQLite 3.35+, MySQL 8.0+ e PostgreSQL 13+.

Versao publicada atual: `0.5.0` (`0.5.x`). O historico de releases fica em
`CHANGELOG.md` na raiz do projeto.

---

## Guia

| #  | Topico                                         | O que voce vai aprender                                     |
|----|------------------------------------------------|-------------------------------------------------------------|
| 01 | [Instalacao](01-installation.md)               | Requisitos, setup, estrutura do projeto, `.env`             |
| 02 | [Routing](02-routing.md)                       | File-based routing, parametros, verbos, guards, smart resolver |
| 03 | [Request & Response](03-request-response.md)   | Input, query, headers, JSON, uploads, Response factories    |
| 04 | [Views & Templates](04-views.md)               | Spark templates, layouts, pipes, loops, forms, componentes  |
| 05 | [Database](05-database.md)                     | QueryBuilder, Models, relacionamentos, migrations, seeds    |
| 06 | [Validation](06-validation.md)                 | Regras, mensagens, erros na view, old input                 |
| 07 | [Middleware](07-middleware.md)                  | Criando, aplicando com `_middleware.php`, por rota/diretorio |
| 08 | [Authentication](08-authentication.md)         | Login, logout, registro, protegendo rotas                   |
| 09 | [Session & Cache](09-session-cache.md)         | Session, flash, CSRF, cache, remember                       |
| 10 | [Events & Jobs](10-events-jobs.md)             | Eventos file-based, jobs, filas, workers                    |
| 11 | [Mail](11-mail.md)                             | SMTP, views, anexos, e-mail assincrono                      |
| 12 | [Helpers](12-helpers.md)                       | Referencia completa de todas as funcoes globais             |
| 13 | [CLI](13-cli.md)                               | Todos os comandos `php spark`, geradores, deploy            |
| 14 | [Releases & Compatibilidade](14-releases.md)   | SemVer, suporte, baseline, deprecacoes                      |
| 15 | [Upgrade Guide](15-upgrade-guide.md)           | Checklist oficial de upgrade e mudancas importantes         |
| 16 | [AI SDK](16-ai.md)                             | SDK unificado para texto, embeddings, imagem, audio e agentes |
| 17 | [AI Conventions](17-ai-conventions.md)         | `app/ai/*`, prompts nomeados, tools file-based e structured output |
| 18 | [Semantic Search & Retrieval](18-search.md)    | Busca vetorial, `pgvector`, retrieval em `ai()` e fluxo de RAG curto |

---

## Quick Start

```bash
# 1. Clone e instale
git clone https://github.com/seu-usuario/sparkphp.git meu-app
cd meu-app && composer install

# 2. Configure
cp .env.example .env
# edite .env com suas credenciais

# 3. Rode
php spark serve
```

Acesse `http://localhost:8000` — pronto.

## Sua primeira rota

```php
// app/routes/hello.php — acesse /hello

get(fn() => ['message' => 'Hello, SparkPHP!']);
```

Retorna JSON para APIs, renderiza view para browsers.

## Sua primeira rota com parametro

```php
// app/routes/users.[id].php — acesse /users/42

get(fn(User $user) => $user);

put(fn(User $user) => $user->update(input()));

delete(fn(User $user) => $user->delete());
```

## Sua primeira view

```html
<!-- app/views/hello.spark -->
@title('Hello')

<h1>{{ $message }}</h1>
<p>Data: {{ now() | date:'d/m/Y' }}</p>

@auth
    <p>Logado como {{ auth()->name }}</p>
@endauth
```

## Principios

1. **Arquivo e a convencao** — se existe, funciona. Sem registro.
2. **Configuracao obrigatoria minima** — o framework liga com `.env`; `app/config/` e opcional para agrupar valores da aplicacao.
3. **Zero boilerplate** — rotas sao closures, middlewares sao scripts, eventos sao arquivos.
4. **Smart defaults** — retorno de array vira JSON ou view automaticamente.
5. **Performance** — cache agressivo em production, lazy loading em tudo.
6. **PHP puro** — sem dependencias externas. Composer e opcional (dev tools).
