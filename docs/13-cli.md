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
| `php spark queue:list`   | Lista jobs pendentes                     |
| `php spark queue:clear`  | Remove todos os jobs da fila             |

### Cache & Otimizacao

| Comando                     | Descricao                                 |
|-----------------------------|-------------------------------------------|
| `php spark cache:clear`     | Limpa todo o cache de aplicacao           |
| `php spark views:cache`     | Pre-compila todas as views `.spark`       |
| `php spark views:clear`     | Limpa cache de views compiladas           |
| `php spark routes:cache`    | Gera cache de rotas                       |
| `php spark routes:clear`    | Limpa cache de rotas                      |
| `php spark routes:list`     | Lista rotas com a ordem efetiva dos middlewares |
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

# Verificar o banco
php spark db:show
php spark db:table posts
```

### Deploy em producao

```bash
# Otimizar tudo (cache de rotas + views)
php spark optimize

# Executar migrations pendentes
php spark migrate

# Iniciar worker de fila (se usar QUEUE=file)
php spark queue:work &
```

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
