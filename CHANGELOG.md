# Changelog

Todas as mudancas publicas relevantes do SparkPHP passam a ser registradas aqui.

## [0.9.0] - 2026-03-29

### Added

- `README.md` raiz com posicionamento publico do produto, quick start e links de decisao.
- comparativo honesto com Laravel em `docs/21-spark-vs-laravel.md`.
- guias novos de adocao, benchmark e migracao em `docs/22-adoption-guide.md`, `docs/23-benchmarking.md` e `docs/24-migrating-from-laravel.md`.

### Changed

- `docs/README.md` e a landing agora tratam explicitamente o Spark como framework mais simples, mais previsivel e mais observavel.
- o portal de docs passou a renderizar o resumo editorial do `docs/README.md` na index.
- versao publicada do framework avancou para `0.9.0`.

## [0.8.0] - 2026-03-29

### Added

- starter kits first-party versionados no runtime com presets `api`, `saas`, `admin` e `docs`.
- comando `php spark starter:list` para listar o catalogo local de starters em modo humano ou JSON.
- suporte a `php spark new --starter=...` para scaffoldar projetos novos ja com o preset aplicado.
- suporte a `php spark init --starter=...` para aplicar um starter ao projeto atual quando desejado.
- marcador `.spark-starter` para registrar o preset aplicado no projeto.
- nova documentacao dedicada para starter kits em `docs/20-starter-kits.md`.

### Changed

- `ProjectScaffolder` agora descobre e aplica overlays versionados em `core/stubs/starters`.
- `php spark about` e `php spark upgrade` passaram a exibir o starter atual do projeto.
- landing, docs e fluxo de instalacao agora tratam starter kits como parte oficial do produto.
- versao publicada do framework avancou para `0.8.0`.

## [0.7.0] - 2026-03-29

### Added

- `php spark new` para scaffold de projetos novos a partir do runtime atual do SparkPHP.
- `php spark upgrade` com modo auditoria (`check`) e sincronizacao segura (`--sync`) do scaffold local.
- auditoria de upgrade para diretorios faltantes, arquivos essenciais e drift de chaves entre `.env.example` e `.env`.
- sincronizacao nao destrutiva de chaves ausentes do `.env` a partir do `.env.example`.

### Changed

- `ProjectScaffolder` passou a concentrar create-project, audit e sync do scaffold de produto.
- help do CLI e documentacao oficial agora tratam o `spark` como interface principal de setup, diagnostico e evolucao.
- versao publicada do framework avancou para `0.7.0`.

## [0.6.0] - 2026-03-29

### Added

- observabilidade nativa de AI no `SparkInspector`, com coletor dedicado para `text`, `embeddings`, `image`, `audio`, `agent` e `retrieval`.
- metricas first-party de AI: latencia, tokens, custo estimado, provider, model e tool calls.
- pipeline de AI e gargalos como `slowest_ai_call` e `most_expensive_ai_call` no Inspector.
- comandos `php spark ai:status` e `php spark ai:smoke-test` para diagnostico e validacao rapida da integracao.
- nova documentacao dedicada para observabilidade de AI em `docs/19-ai-observability.md`.

### Changed

- `AiFakeProvider` agora emite `usage` e `cost_usd` consistentes para todos os builders, melhorando suite, smoke test e DX local.
- o boot minimo do framework passa a registrar `AiManager` com base path correto tambem no contexto de CLI.
- docs de AI (`docs/16-ai.md`, `docs/17-ai-conventions.md`, `docs/18-search.md`) foram expandidas com exemplos mais detalhados e fluxos operacionais.
- versao publicada do framework avancou para `0.6.0`.

## [0.5.0] - 2026-03-29

### Added

- vector search first-party no `QueryBuilder` com `nearestTo()`, `whereVectorSimilarTo()`, `selectVectorSimilarity()` e `orderByVectorSimilarity()`.
- conveniencia em `Model` via `nearestTo()` e `semanticSearch()`.
- retrieval nativo no fluxo de AI via `ai()->retrieve(...)->from(...)->get()`.
- `AiRetrievalResult` com `toPromptContext()` para encurtar fluxos de RAG.
- documentacao dedicada de semantic search e retrieval em `docs/18-search.md`.

### Changed

- consultas vetoriais agora usam expressao `pgvector` em PostgreSQL e fallback em memoria no SQLite/MySQL para DX local e suite.
- `docs/05-database.md`, `docs/16-ai.md` e `docs/17-ai-conventions.md` passaram a cobrir vector search e retrieval.
- versao publicada do framework avancou para `0.5.0`.

## [0.4.0] - 2026-03-29

### Added

- convencoes file-based de AI com `app/ai/agents`, `app/ai/prompts` e `app/ai/tools`.
- descoberta automatica de prompts, tools e agentes via `ai()->discoverPrompts()`, `discoverTools()` e `discoverAgents()`.
- helpers `ai_prompt()`, `ai_tool()` e `ai_tools()` para consumir a camada de convencoes sem boilerplate.
- structured output first-party para `ai()->text()` e `ai()->agent()` via `schema([...])`.
- nova documentacao dedicada para a camada de convencoes em `docs/17-ai-conventions.md`.

### Changed

- `php spark init` agora prepara a estrutura `app/ai/*` desde o bootstrap do projeto.
- `AiFakeProvider` passou a simular tool-calling e payloads estruturados para testes e smoke tests.
- versao publicada do framework avancou para `0.4.0`.

## [0.3.0] - 2026-03-29

### Added

- SDK de AI unificada com `ai()` para texto, embeddings, imagem, audio e agentes.
- `AiManager`, `AiClient`, `AiProvider`, requests/responses tipados e `AiTool`.
- `AiFakeProvider` como driver default para desenvolvimento e testes.
- documentacao first-party da SDK em `docs/16-ai.md`.

### Changed

- `.env.example` e docs de instalacao agora publicam a configuracao de AI.
- helper reference e landing passam a expor a API unificada de AI.
- versao publicada do framework avancou para `0.3.0`.

## [0.2.0] - 2026-03-29

### Added

- `PreventRequestForgery` nativo com validacao de token, `Origin`/`Referer` e defaults mais seguros de session/cookie.
- `Request / Response v2` com content negotiation mais forte, envelopes padronizados de erro, streaming, downloads e respostas vazias consistentes.
- serializacao de API por convencao no `Model`, sparse fields, JSON:API opcional e paginação com `links` / `meta`.
- route model binding implicito, helpers `policy()`, `can()` e `authorize()`.
- geracao de spec OpenAPI via `php spark api:spec`.
- `Queue v2` com rotas por job, `tries`, `backoff`, `timeout`, `failOnTimeout`, inspect/retry/clear seletivo.
- `Cache v2` com `touch()`, `flexible()` (stale-while-revalidate), tags e telemetria integrada.
- `Query Builder / ORM v2` com filtros mais expressivos, eager loading aninhado e `withCount()`.
- observabilidade profunda no Spark Inspector com pipelines de request, cache e queue, alem de gargalos consolidados.
- suite de benchmark com cenarios de request HTML/JSON e metadados versionados no relatorio.
- comando `php spark version` e banner do `serve` versionados a partir do arquivo `VERSION`.

### Changed

- baseline oficial do framework elevada para PHP 8.3+, SQLite 3.35+, MySQL 8.0+ e PostgreSQL 13+.
- documentacao, policy de releases e upgrade guide alinhados ao runtime real do framework.
- middleware global e por diretorio implementado de forma consistente com a documentacao.

## [0.1.0] - 2026-03-27

### Added

- primeira linha publica do core do SparkPHP com Router file-based, View engine `.spark`, Request/Response, Database/Model, Middleware, Validator, Cache, Session, Mail, Queue, CLI e Spark Inspector inicial.
