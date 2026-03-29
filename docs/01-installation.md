# Instalacao

## Requisitos

- PHP 8.3 ou superior
- Extensoes: `pdo`, `mbstring`, `openssl`, `json`
- Driver PDO do banco escolhido:
  - `pdo_sqlite` para SQLite
  - `pdo_mysql` para MySQL
  - `pdo_pgsql` para PostgreSQL
- Opcional para semantic search em PostgreSQL: extensao `pgvector`
- Composer (para dependencias de desenvolvimento)

## Baseline suportada

- PHP: 8.3+
- SQLite: 3.35+
- MySQL: 8.0+
- PostgreSQL: 13+

Essa baseline acompanha o core atual do SparkPHP, incluindo operacoes de schema como `DROP COLUMN` e `RENAME COLUMN`, alem do uso de tipos modernos como `JSON` / `JSONB` e `UUID`.

## Criando um projeto

```bash
# Clone o repositorio
git clone https://github.com/seu-usuario/sparkphp.git meu-projeto
cd meu-projeto

# Instale dependencias (phpunit, etc.)
composer install

# Copie o .env
cp .env.example .env

# Gere uma chave de aplicacao (troque o valor de APP_KEY no .env)
# Use qualquer string aleatoria de 32+ caracteres

# Inicie o servidor de desenvolvimento
php spark serve
```

Acesse `http://localhost:8000` e voce vera a pagina inicial do SparkPHP.
Ao subir com `php spark serve`, o banner do servidor tambem mostra a versao atual
publicada do framework lida de `VERSION`.

## Porta customizada

```bash
php spark serve --port=3000
```

## Estrutura do projeto

```
meu-projeto/
├── app/
│   ├── ai/
│   │   ├── agents/      ← agentes por convencao
│   │   ├── prompts/     ← prompts nomeados/templates
│   │   └── tools/       ← tools file-based para agentes
│   ├── config/         ← arquivos de configuracao (retornam arrays)
│   ├── events/         ← handlers de eventos (nome = evento)
│   ├── jobs/           ← classes de jobs para filas
│   │   └── _queue.php  ← manifesto opcional de defaults/rotas da fila
│   ├── middleware/      ← middlewares (nome do arquivo = alias)
│   ├── models/         ← models (nome do arquivo = nome da classe)
│   ├── routes/         ← rotas (caminho do arquivo = URL, `_middleware.php` = middleware herdado)
│   ├── services/       ← classes de servico
│   └── views/
│       ├── layouts/    ← layouts (.spark)
│       ├── partials/   ← partials e componentes (.spark)
│       └── errors/     ← paginas de erro (404.spark, 419.spark, 500.spark)
├── core/               ← engine do framework (nao edite)
├── database/
│   ├── migrations/     ← migrations class-based com timestamp
│   └── seeds/          ← DatabaseSeeder e seeders especificos
├── public/             ← document root (index.php, assets)
├── storage/
│   ├── cache/          ← cache de views, rotas, classes
│   ├── logs/           ← logs da aplicacao
│   ├── queue/          ← jobs da fila (driver file)
│   └── sessions/       ← sessions (driver file)
├── .env                ← configuracao do ambiente
├── VERSION             ← fonte oficial da versao publicada do framework/projeto
├── CHANGELOG.md        ← historico publico das releases
├── spark               ← CLI do framework
└── composer.json
```

O arquivo `VERSION` na raiz e a fonte de verdade da versao publicada do SparkPHP no
projeto. Ele alimenta `php spark version`, `php spark about`, a rota raiz padrao e a
spec OpenAPI gerada pelo CLI. O `CHANGELOG.md` registra o historico humano de produto
da linha publicada.

Ao rodar `php spark init`, essa estrutura tambem prepara `app/ai/agents`,
`app/ai/prompts` e `app/ai/tools`, deixando a camada de AI pronta para seguir a
mesma filosofia file-based do restante do framework.

## Configuracao (.env)

Todo o SparkPHP e configurado por um unico arquivo `.env` na raiz:

```env
# Aplicacao
APP_NAME=SparkPHP
APP_ENV=dev                              # dev | production
APP_PORT=8000
APP_KEY=change-me-to-a-random-secret-32-chars
APP_URL=http://localhost:8000
APP_TIMEZONE=America/Sao_Paulo

# Banco de dados
DB=mysql                                 # mysql | pgsql | sqlite
DB_HOST=localhost
DB_PORT=3306
DB_NAME=sparkphp
DB_USER=root
DB_PASS=

# Sessao
SESSION=file                             # file
SESSION_LIFETIME=7200
SESSION_SECURE=auto                      # auto | true | false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=Lax                   # Lax | Strict | None

# HTTP / proxies confiaveis
TRUSTED_PROXIES=                        # ex: 127.0.0.1,10.0.0.0/8

# CSRF / Request Forgery Protection
CSRF_REQUIRE_ORIGIN=false
CSRF_TRUSTED_ORIGINS=                   # ex: https://admin.exemplo.com

# Cache
CACHE=file                               # file | memory

# E-mail (SMTP)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=
MAIL_PASS=
MAIL_FROM=
MAIL_FROM_NAME="${APP_NAME}"

# Fila
QUEUE=sync                               # sync | file

# AI
AI_DRIVER=fake                           # fake
AI_TEXT_MODEL=spark-text
AI_EMBEDDING_MODEL=spark-embedding
AI_IMAGE_MODEL=spark-image
AI_AUDIO_MODEL=spark-audio
AI_AGENT_MODEL=spark-agent

# Log
LOG_LEVEL=debug
```

## Configuracao opcional da aplicacao

O `.env` cobre a configuracao obrigatoria do framework. Se quiser agrupar valores da sua aplicacao sem espalhar `env()` pelo codigo, voce pode criar arquivos PHP opcionais em `app/config/`:

```php
// app/config/app.php
<?php

return [
    'name'     => env('APP_NAME', 'SparkPHP'),
    'env'      => env('APP_ENV', 'dev'),
    'url'      => env('APP_URL', 'http://localhost:8000'),
    'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),
];
```

Acesse com dot-notation:

```php
config('app.name');          // 'SparkPHP'
config('app.timezone');      // 'America/Sao_Paulo'
config('app.missing', 'x');  // 'x' (default)
```

Esses arquivos sao uma camada de conveniencia da aplicacao. Eles nao substituem o `.env` e nao funcionam como um diretorio central de wiring do framework.

Para wiring file-based de jobs e filas, o Spark usa `app/jobs/_queue.php` em vez de
`app/config/queue.php`. Isso mantem a regra de que `app/config/` continua opcional e
voltado a valores da aplicacao, enquanto o comportamento operacional da fila fica perto
dos proprios jobs.

## Producao e proxies

Se o projeto roda atras de Nginx, Traefik, Cloudflare, load balancer ou outra camada reversa, configure `TRUSTED_PROXIES` corretamente. O Spark so passa a confiar em `X-Forwarded-For`, `X-Forwarded-Proto` e `X-Forwarded-Host` quando o `REMOTE_ADDR` bate com essa allowlist.

Isso afeta diretamente:

- `request()->ip()` / `ip()`
- `request()->url()` / `request()->isSecure()`
- cookies de sessao com `SESSION_SECURE=auto`
- validacao de origem do `PreventRequestForgery`

## Ambientes

| `APP_ENV`    | Comportamento |
|--------------|---------------|
| `dev`        | Erros detalhados na tela, cache desabilitado, recompilacao automatica de views e rotas |
| `production` | Erros genericos, cache de `.env`, rotas e views ativo, logs de excecoes |

Para otimizar em producao:

```bash
php spark optimize
```

Isso gera cache de rotas, views compiladas e limpa caches antigos.

## Proximo passo

→ [Routing](02-routing.md)
