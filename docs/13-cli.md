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
| `php spark serve`    | Inicia o servidor de desenvolvimento                   |
| `php spark serve --port=3000` | Com porta customizada                         |
| `php spark init`     | Inicializa um novo projeto (copia .env, cria diretorios) |
| `php spark about`    | Exibe diagnosticos do ambiente, PHP, extensoes, banco  |
| `php spark benchmark`| Roda benchmark de performance do framework             |

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

# Gerar spec OpenAPI da API
php spark api:spec

# Verificar o banco
php spark db:show
php spark db:table posts
```

### OpenAPI por convencao

O comando `php spark api:spec` gera uma spec OpenAPI 3.1 em JSON, por padrao em:

```bash
storage/api/openapi.json
```

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

# Saida:
#   SparkPHP v1.0.0
#   PHP 8.3.0
#   Environment: production
#   Database: mysql (sparkphp@localhost)
#   Cache: file
#   Queue: file
#   Session: file
#   ...
```

## Spark Inspector

O SparkPHP inclui um inspector embutido para debug em desenvolvimento. Configure no `.env`:

```env
SPARK_INSPECTOR=auto         # auto | on | off (auto = ativo em dev)
SPARK_INSPECTOR_PREFIX=/_spark
SPARK_INSPECTOR_HISTORY=150  # requests no historico
SPARK_INSPECTOR_MASK=false   # mascara dados sensiveis
SPARK_INSPECTOR_SLOW_MS=250  # threshold para marcar request como lenta
```

Acesse `http://localhost:8000/_spark` para ver o painel do Inspector com:

- Historico de requests
- Queries executadas e tempo
- Uso de memoria
- Logs da requisicao
- Rotas resolvidas

## Proximo passo

→ Voltar para o [Indice](README.md)
