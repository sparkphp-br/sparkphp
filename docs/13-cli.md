# CLI (Spark Commands)

O SparkPHP inclui uma CLI completa via o comando `php spark`. Sem dependencias — e um unico arquivo PHP.

## Uso geral

```bash
php spark <comando> [opcoes]
```

---

## Comandos disponiveis

### Projeto

| Comando              | Descricao                                              |
|----------------------|--------------------------------------------------------|
| `php spark serve`    | Inicia o servidor de desenvolvimento com banner de versao |
| `php spark serve --port=3000` | Com porta customizada                         |
| `php spark serve --dry-run` | Mostra o banner/configuracao sem abrir o servidor |
| `php spark init`     | Inicializa um novo projeto (copia .env, cria diretorios, inclui `app/ai/*`) |
| `php spark init --starter=docs --force` | Aplica um starter ao projeto atual |
| `php spark new ../meu-app` | Cria um projeto novo a partir do scaffold atual do SparkPHP |
| `php spark new ../meu-app --starter=saas` | Cria projeto novo ja com preset first-party |
| `php spark upgrade`  | Audita o scaffold do projeto atual                     |
| `php spark upgrade --sync` | Sincroniza partes seguras do scaffold e do `.env` |
| `php spark starter:list` | Lista os starter kits first-party versionados no runtime |
| `php spark version`  | Exibe a versao atual do framework e a release line     |
| `php spark about`    | Exibe diagnosticos do ambiente, PHP, extensoes, banco  |
| `php spark benchmark`| Roda benchmark de performance do framework             |

### AI

| Comando                        | Descricao                                                  |
|--------------------------------|------------------------------------------------------------|
| `php spark ai:status`          | Mostra driver, models e configuracao de trace de AI        |
| `php spark ai:status --json`   | Mesmo diagnostico em JSON                                  |
| `php spark ai:smoke-test`      | Executa smoke test das capacidades de AI configuradas      |
| `php spark ai:smoke-test --capability=text` | Roda apenas uma capacidade especifica     |
| `php spark ai:smoke-test --json` | Emite o relatorio em JSON                                |

### Migrations

| Comando                    | Descricao                                     |
|----------------------------|-----------------------------------------------|
| `php spark migrate`        | Executa migrations pendentes                  |
| `php spark migrate:status` | Mostra status de cada migration               |
| `php spark migrate:rollback`| Desfaz a ultima batch de migrations          |
| `php spark migrate:fresh`  | Apaga todas as tabelas e re-executa tudo      |

### Database

| Comando                     | Descricao                                    |
|-----------------------------|----------------------------------------------|
| `php spark db:show`         | Mostra conexao ativa e lista de tabelas      |
| `php spark db:table users`  | Mostra colunas e indices de uma tabela       |
| `php spark db:wipe`         | Apaga todas as tabelas (CUIDADO!)            |

### Seeds

| Comando                        | Descricao                              |
|--------------------------------|----------------------------------------|
| `php spark seed`               | Executa `DatabaseSeeder`               |
| `php spark seed UserSeeder`    | Executa seeder especifico              |

### Queue (Fila)

| Comando                  | Descricao                                |
|--------------------------|------------------------------------------|
| `php spark queue:work`   | Inicia worker que processa jobs          |
| `php spark queue:work --queue=emails` | Worker para fila especifica   |
| `php spark queue:list`   | Lista filas com `ready`, `delayed` e `total` |
| `php spark queue:inspect <id>` | Inspeciona um job pendente ou com falha |
| `php spark queue:retry <id>` | Reenvia um job da fila `failed`        |
| `php spark queue:retry --all` | Reenvia todos os jobs da fila `failed` |
| `php spark queue:clear`  | Remove todos os jobs da fila             |
| `php spark queue:clear default --job=SendMailJob` | Remove jobs filtrando por classe |
| `php spark queue:clear default --id=job_...` | Remove um job especifico por ID |

### Cache & Otimizacao

| Comando                     | Descricao                                 |
|-----------------------------|-------------------------------------------|
| `php spark cache:clear`     | Limpa todo o cache de aplicacao           |
| `php spark views:cache`     | Pre-compila todas as views `.spark`       |
| `php spark views:clear`     | Limpa cache de views compiladas           |
| `php spark routes:cache`    | Gera cache de rotas                       |
| `php spark routes:clear`    | Limpa cache de rotas                      |
| `php spark routes:list`     | Lista rotas com a ordem efetiva dos middlewares |
| `php spark api:spec`        | Gera `storage/api/openapi.json` a partir das rotas |
| `php spark optimize`        | Gera cache de rotas + views (para deploy) |

### Spark Inspector

| Comando                        | Descricao                            |
|--------------------------------|--------------------------------------|
| `php spark inspector:clear`    | Limpa historico do Inspector         |
| `php spark inspector:status`   | Mostra status e configuracao atual   |

### Geradores (make)

| Comando                                  | Cria                                            |
|------------------------------------------|-------------------------------------------------|
| `php spark make:model User`              | `app/models/User.php`                           |
| `php spark make:migration create_posts`  | `database/migrations/002_create_posts.php`       |
| `php spark make:seeder PostSeeder`       | `database/seeds/PostSeeder.php`                  |
| `php spark make:job SendEmailJob`        | `app/jobs/SendEmailJob.php`                      |
| `php spark make:event OrderPlaced`       | `app/events/OrderPlaced.php`                     |

---

## Exemplos de uso

### Setup inicial de um projeto

```bash
# Criar projeto e configurar
git clone https://github.com/seu-usuario/sparkphp.git meu-app
cd meu-app
composer install
php spark init

# Editar .env com suas credenciais de banco
# ...

# Criar tabelas
php spark migrate

# Popular com dados iniciais
php spark seed

# Iniciar servidor
php spark serve
```

Ao subir o servidor, o banner mostra a versao atual do SparkPHP lida de `VERSION`.
O mesmo bootstrap ja prepara a estrutura `app/ai/agents`, `app/ai/prompts` e
`app/ai/tools` para a camada file-based de AI.

### Criando um projeto novo pelo CLI

Quando voce ja esta dentro de uma instalacao do SparkPHP e quer abrir outro projeto
rapidamente, o CLI pode scaffoldar um app inteiro:

```bash
php spark new ../meu-novo-app
php spark new ../minha-api --starter=api
php spark new ../meu-saas --starter=saas
```

O comando:

- copia o scaffold publico do runtime atual
- cria `.env` a partir de `.env.example`
- gera `APP_KEY`
- prepara `storage/`, `app/ai/*` e `DatabaseSeeder`

Variantes:

```bash
php spark new ../meu-novo-app --force
php spark new ../meu-novo-app --no-init
php spark new ../meu-novo-app --json
php spark new ../meu-novo-app --starter=admin
```

Use `--no-init` quando quiser apenas copiar o scaffold e decidir depois quando gerar
`.env`, `APP_KEY` e diretorios de runtime.

### Catalogo de starter kits

Os starters first-party do Spark vivem dentro do proprio runtime, entao o catalogo
sempre acompanha a versao do framework instalada no projeto atual.

```bash
php spark starter:list
php spark starter:list --json
```

Starters atuais:

- `api`: entrada em `/api`, exemplos REST e DX JSON-first
- `saas`: landing + pricing + dashboard inicial
- `admin`: painel interno com visao operacional
- `docs`: portal de documentacao com raiz em `/documents`

Para aplicar um preset em um projeto ja existente:

```bash
php spark init --starter=docs --force
```

Use `--force` quando o starter precisar sobrescrever arquivos base como
`app/routes/index.php`.

### Fluxo de desenvolvimento

```bash
# Criar model + migration
php spark make:model Post
php spark make:migration create_posts_table

# Editar a migration, depois executar
php spark migrate

# Criar seeder e popular
php spark make:seeder PostSeeder
php spark seed PostSeeder

# Ver as rotas
php spark routes:list

# A saida inclui middlewares globais, por diretorio e inline

# Ver a versao atual do framework
php spark version

# Testar o banner do servidor sem abrir a porta
php spark serve --dry-run

# Criar um projeto novo a partir do scaffold atual
php spark new ../cliente-acme
php spark new ../painel-interno --starter=admin

# Auditar o projeto atual contra o scaffold publicado
php spark upgrade

# Aplicar sincronizacao segura de dirs/.env
php spark upgrade --sync

# Ver o catalogo local de starters
php spark starter:list

# Gerar spec OpenAPI da API
php spark api:spec

# Verificar estado atual da camada de AI
php spark ai:status

# Smoke test completo do provider configurado
php spark ai:smoke-test

# Smoke test apenas de retrieval
php spark ai:smoke-test --capability=retrieval

# Rodar a suite comparativa de benchmark
php spark benchmark --iterations=50 --warmup=5

# Verificar o banco
php spark db:show
php spark db:table posts
```

### OpenAPI por convencao

O comando `php spark api:spec` gera uma spec OpenAPI 3.1 em JSON, por padrao em:

```bash
storage/api/openapi.json
```

O campo `info.version` da spec e preenchido automaticamente a partir do arquivo
`VERSION` na raiz do projeto.

Opcoes:

```bash
php spark api:spec
php spark api:spec --output=public/openapi.json
php spark api:spec --all
```

Por padrao, o comando foca em rotas `/api/*`. Com `--all`, ele inclui qualquer rota
que o router conheca.

O gerador usa convencoes do Spark para inferir a spec a partir de:

- rotas file-based e `path()`
- parametros dinamicos como `[id]`
- route model binding implicito
- `validate([...])` para `requestBody`
- retorno do handler para respostas comuns (`Model`, array, paginação, JSON:API)
- guards e middlewares como `auth` para `security`

#### O que ele consegue inferir bem

- `GET /api/users/[id]` com `fn(User $user) => $user`
- `POST/PUT/PATCH` com `validate([...])`
- respostas com `Model`, `Model::create(...)` e `Model::query()->paginate(...)`
- envelopes padrao de erro `401`, `403`, `404` e `422` quando aplicavel

#### Limites da inferencia

Se a rota montar a resposta de forma muito dinamica, a spec pode cair para um schema
mais generico. O objetivo do Spark aqui e cobrir muito bem o caso convencional,
sem obrigar anotacoes verbosas em cada endpoint.

### Benchmark comparativo versionado

O comando `php spark benchmark` agora gera uma suite com metadados de produto:

- `spark_version` e `spark_release_line`
- perfil da fixture benchmarkada
- cenarios agrupados (`bootstrap`, `routing`, `views`, `http`, `container`)
- resumo com cenario mais rapido e mais lento

Exemplos:

```bash
# Rodar com configuracao padrao e salvar em storage/benchmarks/latest.json
php spark benchmark

# Suite curta para smoke test local
php spark benchmark --iterations=20 --warmup=3

# Emitir JSON no stdout sem salvar
php spark benchmark --json --no-save
```

Os cenarios `http.request_html` e `http.request_json` usam uma fixture file-based real
do SparkPHP para medir request pipeline completo, em vez de apenas microbenchmarks
isolados.

### Deploy em producao

```bash
# Otimizar tudo (cache de rotas + views)
php spark optimize

# Executar migrations pendentes
php spark migrate

# Iniciar worker de fila (se usar QUEUE=file)
php spark queue:work &
```

### Operando filas no dia a dia

```bash
# Ver a saude das filas
php spark queue:list

# Consumir so a fila de emails
php spark queue:work --queue=emails --sleep=1

# Processar N jobs e sair (bom para supervisores/smoke test)
php spark queue:work --queue=default --max-jobs=10

# Inspecionar o payload de um job com falha
php spark queue:inspect job_67f2a... --queue=failed

# Recolocar um job com falha na fila original
php spark queue:retry job_67f2a...

# Limpar apenas uma classe especifica da fila default
php spark queue:clear default --job=SendWelcomeEmail
```

O worker respeita os metadados persistidos do job (`tries`, `backoff`, `timeout`
e `fail_on_timeout`), resolvidos a partir de:

- defaults internos do framework
- `app/jobs/_queue.php`
- propriedades / atributos da classe do job
- overrides inline no despacho

### Diagnosticos

```bash
# Ver informacoes do ambiente
php spark about

# Ver apenas a versao atual
php spark version
# ou
php spark --version
# ou
php spark -V

# Saida:
#   SparkPHP v0.9.0 (0.9.x)
#   SparkPHP environment report  v0.9.0
#   PHP 8.3.0
#   Environment: production
#   Database: mysql (sparkphp@localhost)
#   ...
```

### Upgrade assistido

```bash
# Auditar sem alterar arquivos
php spark upgrade

# Formato JSON para automacao/CI local
php spark upgrade --json

# Aplicar sincronizacao segura
php spark upgrade --sync
```

O `upgrade` checa:

- diretorios esperados do scaffold
- arquivos essenciais do produto
- estado do `.env`
- drift de chaves entre `.env.example` e `.env`

No modo `--sync`, o CLI aplica apenas mudancas seguras:

- cria `.env` se faltar
- gera `APP_KEY` se estiver ausente ou placeholder
- recria diretorios e `DatabaseSeeder` esperados
- adiciona no `.env` chaves que existem no `.env.example` mas ainda nao foram copiadas

### AI status e smoke test

```bash
# Diagnostico do runtime de AI
php spark ai:status

# Formato JSON para automacao
php spark ai:status --json

# Validar texto, embeddings, imagem, audio, agente e retrieval
php spark ai:smoke-test

# Rodar apenas uma capacidade
php spark ai:smoke-test --capability=agent
php spark ai:smoke-test --capability=retrieval --json
```

O `ai:status` mostra:

- driver atual
- provider resolvido
- modelos por capacidade
- defaults de imagem/audio
- configuracao de trace do Inspector

Por default, o `ai:smoke-test` cobre `text`, `embeddings`, `image`, `audio` e `agent`.
Use `--capability=retrieval` quando quiser validar tambem a camada de dados.

O `ai:smoke-test` retorna por capacidade:

- status
- latencia
- tokens
- custo
- resumo curto do resultado

## Spark Inspector

O SparkPHP inclui um inspector embutido para debug em desenvolvimento. Configure no `.env`:

```env
SPARK_INSPECTOR=auto         # auto | on | off (auto = ativo em dev)
SPARK_INSPECTOR_PREFIX=/_spark
SPARK_INSPECTOR_HISTORY=150  # requests no historico
SPARK_INSPECTOR_MASK=false   # mascara dados sensiveis
SPARK_INSPECTOR_SLOW_MS=250  # threshold para marcar request como lenta
SPARK_AI_MASK=true           # mascara prompts e respostas de AI
SPARK_AI_TRACE_PREVIEW=240   # limite do preview no trace de AI
```

Acesse `http://localhost:8000/_spark` para ver o painel do Inspector com:

- Historico de requests
- pipelines completos de request, cache e queue
- pipeline e aba dedicados de AI, com provider, model, tokens, custo e tool calls
- queries executadas e tempo
- gargalos como slow query, slow view e cache hot keys
- jobs, falhas, releases e retries quando a fila passa pelo runtime instrumentado
- uso de memoria, logs e rotas resolvidas

## Proximo passo

→ Voltar para o [Indice](README.md)
