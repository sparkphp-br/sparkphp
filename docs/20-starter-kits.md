# Starter Kits

Os starter kits first-party do SparkPHP existem para resolver um problema simples:
comecar um projeto novo sem abrir mao da filosofia do framework.

Em vez de vender "feature parity" com mais pacotes, o Spark publica presets pequenos,
versionados junto com o proprio runtime e aplicados pelo CLI.

## Principios

- nenhum starter adiciona dependencia externa
- nenhum starter muda a forma de pensar o framework
- todo starter continua file-based, zero-config e observavel
- o catalogo acompanha a versao do runtime instalada no projeto

## Catalogo atual

| Starter | Entrypoint | Quando usar |
|---------|------------|-------------|
| `api`   | `/api`     | APIs JSON-first, contratos HTTP e OpenAPI |
| `saas`  | `/`        | Produto B2B com landing, pricing e dashboard inicial |
| `admin` | `/admin`   | Backoffice, operacao interna e ferramentas de equipe |
| `docs`  | `/documents` | Portal de documentacao publica com Markdown |

## Listando o catalogo

```bash
php spark starter:list
php spark starter:list --json
```

O modo texto e bom para navegacao rapida. O modo JSON e util para tooling,
automacao interna e inspeccao do catalogo publicado naquela versao do framework.

## Criando um projeto novo com starter

```bash
php spark new ../minha-api --starter=api
php spark new ../meu-saas --starter=saas
php spark new ../meu-admin --starter=admin
php spark new ../meus-docs --starter=docs
```

Fluxo do `spark new --starter=...`:

1. copia o scaffold base do runtime atual
2. aplica o overlay do starter escolhido
3. gera `.env` e `APP_KEY`
4. prepara diretorios de runtime
5. grava `.spark-starter` com o preset aplicado

Esse arquivo `.spark-starter` fica na raiz e registra:

- `key` do starter
- `spark_version` em que ele foi aplicado
- `applied_at` em formato ISO-8601

## Aplicando em um projeto existente

```bash
php spark init --starter=docs --force
```

Esse fluxo existe para projetos ainda muito iniciais. Como o starter pode sobrescrever
arquivos base como `app/routes/index.php`, o caminho recomendado para projeto maduro
continua sendo criar um app novo com `spark new --starter=...` e migrar com calma.

## O que cada starter entrega

### `api`

- rota raiz redirecionando para `/api`
- endpoint de entrada da API com metadados da release
- exemplos REST em `app/routes/api/*.php`
- fluxo bom para `php spark api:spec`

### `saas`

- landing inicial com views Spark
- rota de pricing
- dashboard placeholder em `/app`
- boa base para conectar auth, billing e filas

### `admin`

- painel inicial em `/admin`
- listagem de usuarios e trilha operacional
- shape ideal para adicionar `_middleware.php` herdado e `guard('auth')`

### `docs`

- raiz redirecionando para `/documents`
- aproveita o portal Markdown nativo do Spark
- inclui um guia editorial extra em `docs/`

## Como os starters sao implementados

Os presets vivem no proprio runtime, dentro de:

```text
core/stubs/starters/
```

Cada starter tem:

- `manifest.php` com metadados publicos
- `files/` com o overlay aplicado ao projeto

Isso traz duas vantagens:

1. o starter e versionado junto com o framework
2. qualquer projeto gerado continua sabendo listar e aplicar o catalogo local

## Filosofia zero-config preservada

Os starter kits do Spark nao devem:

- exigir pacote extra para "ligar"
- esconder comportamento em geradores opacos
- empurrar o usuario para uma arquitetura paralela

Eles servem para encurtar o primeiro commit. Depois disso, o projeto continua sendo um
SparkPHP normal: rotas em arquivo, views `.spark`, CLI versionada, docs em Markdown e
observabilidade nativa.
