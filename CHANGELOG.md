# Changelog

Todas as mudancas publicas relevantes do SparkPHP passam a ser registradas aqui.

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
