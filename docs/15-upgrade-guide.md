# Upgrade Guide

Este guia concentra o processo oficial de upgrade do SparkPHP.

## Regra geral

Antes de atualizar o framework:

1. leia `docs/14-releases.md`
2. revise a baseline atual de PHP e banco
3. rode sua suite de testes
4. valide comandos criticos como `php spark about`, `php spark routes:list` e `php spark migrate:status`

## Checklist de upgrade

### 1. Atualize a baseline do ambiente

Confirme primeiro:

- PHP 8.3+
- SQLite 3.35+, MySQL 8.0+ ou PostgreSQL 13+

Se o projeto ainda estiver abaixo disso, ajuste o ambiente antes de prosseguir.

### 2. Atualize dependencias e arquivos do projeto

```bash
composer install
composer dump-autoload
```

Se o seu projeto usa o Spark sem Composer para runtime, ainda assim mantenha as dependencias de desenvolvimento atualizadas para preservar a suite e a CLI.

### 3. Revise convencoes do framework

Cheque especialmente:

- `app/routes/_middleware.php`
- `_middleware.php` aninhados dentro de `app/routes/`
- middlewares de pasta como `[auth]`
- aliases nomeados de rota e `route()`

Se um arquivo `_middleware.php` existir no projeto, ele agora e executado como middleware herdado e nao como rota.

### 4. Limpe caches e recompile artefatos

```bash
php spark cache:clear
php spark routes:clear
php spark views:clear
php spark optimize
```

### 5. Verifique banco e migrations

```bash
php spark about
php spark migrate:status
```

Em seguida, rode migrations pendentes se houver:

```bash
php spark migrate
```

### 6. Rode a suite

```bash
vendor/bin/phpunit --display-skipped
```

Os `skipped` aceitaveis hoje sao apenas integrações externas opcionais de MySQL e PostgreSQL sem ambiente configurado.

## Mudancas importantes desta linha

### SDK de AI unificado

O Spark agora inclui uma entrada unica `ai()` para texto, embeddings, imagem, audio
e agentes, com driver fake por default em desenvolvimento.

Impacto:

- projetos que tinham wrappers proprios de AI podem migrar gradualmente para `ai()`
- novas configuracoes opcionais apareceram no `.env`, como `AI_DRIVER`,
  `AI_TEXT_MODEL`, `AI_EMBEDDING_MODEL`, `AI_IMAGE_MODEL`, `AI_AUDIO_MODEL`
  e `AI_AGENT_MODEL`
- o driver `fake` vira a base oficial para testes e smoke tests locais

### Convencoes file-based de AI e structured output

A linha atual tambem oficializa a camada `app/ai/*`:

- `app/ai/prompts`
- `app/ai/tools`
- `app/ai/agents`

Impacto:

- `php spark init` agora cria esses diretorios no bootstrap do projeto
- prompts nomeados podem migrar para `ai_prompt()` ou `->usingPrompt(...)`
- agentes podem centralizar defaults, tools e schema em arquivos de `app/ai/agents`
- responses estruturadas agora podem ser pedidas via `schema([...])` em `text()` e `agent()`

Se o projeto ja tinha convencoes proprias para AI, a migracao recomendada e mover
primeiro prompts e tools reutilizaveis para `app/ai/*`, depois consolidar os agentes.

### Semantic search e retrieval first-party

O Spark agora conecta embeddings, banco e AI flow sem pacote adicional:

- `db(...)->whereVectorSimilarTo(...)`
- `db(...)->selectVectorSimilarity(...)`
- `Model::semanticSearch(...)`
- `ai()->retrieve(...)->from(...)->get()`

Impacto:

- colunas de embedding podem continuar como JSON/texto em dev, com ranking em memoria
- em PostgreSQL, a intencao e mapear a consulta para `pgvector`
- fluxos de RAG simples agora podem nascer so com `QueryBuilder` + `ai()`

Se o projeto ja tinha retrieval artesanal, a migracao recomendada e mover primeiro a
busca para `QueryBuilder`, depois substituir o glue code por `ai()->retrieve(...)`.

### AI observavel por default

O Spark agora instrumenta chamadas de AI automaticamente no Inspector:

- `text()`
- `embeddings()`
- `image()`
- `audio()`
- `agent()`
- `retrieve()`

Impacto:

- novas configuracoes opcionais apareceram no `.env`: `SPARK_AI_MASK` e
  `SPARK_AI_TRACE_PREVIEW`
- o CLI ganhou `php spark ai:status` e `php spark ai:smoke-test`
- o driver `fake` passa a expor `usage` e `cost_usd`, o que pode afetar suites que
  validavam `meta` de forma muito estrita

Migracao recomendada:

1. habilite o Inspector no ambiente de desenvolvimento
2. confirme `SPARK_AI_MASK=true`
3. rode `php spark ai:status`
4. rode `php spark ai:smoke-test`
5. revise `/_spark` para validar previews, tokens e custo

### Baseline moderna do framework

A baseline oficial do Spark passou a ser:

- PHP 8.3+
- SQLite 3.35+
- MySQL 8.0+
- PostgreSQL 13+

Impacto:

- projetos em PHP 8.1/8.2 precisam subir a runtime antes de atualizar
- SQLite antigo pode falhar em operacoes de schema como `DROP COLUMN`

### `_middleware.php` agora faz parte do runtime

Arquivos abaixo agora sao executados como middleware herdado:

- `app/routes/_middleware.php`
- `app/routes/**/_middleware.php`

Ordem de execucao:

1. `_middleware.php` global
2. `_middleware.php` das pastas percorridas
3. pastas com colchetes como `[auth]`
4. `guard()` inline
5. handler

Impacto:

- se o projeto ja tinha `_middleware.php` guardado como arquivo auxiliar, ele precisa ser removido ou renomeado
- rotas passaram a herdar middleware de forma mais consistente ao longo da arvore

## Quando tratar como breaking change

No estado atual `0.x`, trate upgrades de minor como potencialmente breaking se envolverem:

- Router e convencoes de descoberta
- Middleware e seguranca
- baseline de PHP ou banco
- schema builder e migrations

## Politica para times

Se o SparkPHP estiver sendo adotado por um time:

- pinne a minor explicitamente
- atualize em janela controlada
- preserve um smoke test de rotas, auth, migrations e cache

## Resumo rapido

```bash
php -v
php spark about
php spark routes:list
php spark migrate:status
vendor/bin/phpunit --display-skipped
```
