# Roadmap Archive

Este arquivo preserva o histórico de execução do roadmap interno do SparkPHP — as macrofases de planejamento que foram concluídas durante o desenvolvimento até a versão `0.10.0`.

Este não é o CHANGELOG. O CHANGELOG registra releases públicas e mudanças de contrato. Este arquivo registra a evolução das macrofases de planejamento.

---

## Fase 1 — Documentação Fundacional (concluída em 2026-03-27)

Estabeleceu os quatro documentos de referência interna do produto:

- `docs/architecture/01-spark-template.md` — especificação da template engine com diretivas, pipes e eliminação de verbosidades do Blade
- `docs/architecture/02-estrutura-framework.md` — árvore completa de diretórios, convenções de nomeação e mapa camada → convenção → exemplo
- `docs/architecture/03-core-engine.md` — componentes internos, fluxo de request boot→route→middleware→response e estratégia de cache
- `docs/architecture/04-identidade-filosofia.md` — manifesto, princípios de design e convenções gerais do SparkPHP

---

## Fase 2 — Implementação do Core (concluída em 2026-03-27)

Entregou o runtime base do framework:

- Bootstrap + Autoloader
- Router (file-based)
- Request / Response
- Spark Template Engine (compilador)
- Database / Model
- Middleware Engine
- Container (DI)
- Validator
- Event Emitter
- Session / Cache
- CLI (`spark`)
- Helpers globais

Correspondência no CHANGELOG: `[0.1.0]`

---

## Fase 3 — Ferramentas e DX (concluída em 2026-03-29)

- `composer create-project` skeleton
- Extensão VS Code para `.spark` com grammar TextMate, snippets e autocomplete
- Documentação pública em `docs/` com 13 guias e exemplos práticos
- Suite PHPUnit com cobertura de Router, Request/Response, Container, Middleware, View, Validator, EventEmitter, Cache e Helpers
- Benchmarks comparativos via `php spark benchmark`
- SparkInspector com toolbar HTML, painel em `/_spark`, headers `X-Spark-*`, coletores de request/view/query/cache/log/event/mail/queue/dump e helpers `inspect()` / `measure()`

Correspondência no CHANGELOG: parte de `[0.2.0]`

---

## Fase 4 — Estabilização e Coerência do Produto (concluída em 2026-03-29)

- Decisão oficial sobre papel de `config()` vs `.env` — docs e runtime alinhados
- Suite 100% verde com baseline de qualidade definida
- `_middleware.php` global e por diretório implementado conforme documentação
- Baseline elevada para PHP 8.3+, SQLite 3.35+, MySQL 8.0+, PostgreSQL 13+
- Política pública de releases e compatibilidade (`docs/14-releases.md`)
- `VERSION` como fonte única da versão publicada

Correspondência no CHANGELOG: `[0.2.0]`

---

## Fase 5 — Segurança, HTTP e APIs (concluída em 2026-03-29)

- `PreventRequestForgery` com validação por token + Origin/Referer
- Request/Response v2: content negotiation, envelopes de erro, streaming, downloads
- Serialização de API por convenção no Model (sparse fields, JSON:API opcional, paginação)
- Route model binding implícito
- Geração de spec OpenAPI via `php spark api:spec`

Correspondência no CHANGELOG: `[0.2.0]`

---

## Fase 6 — Runtime, Dados e Filas (concluída em 2026-03-29)

- Queue v2: roteamento por job, tries, backoff, timeout, failOnTimeout
- Cache v2: touch(), flexible (stale-while-revalidate), tags
- Query Builder / ORM v2: filtros, eager loading, withCount()
- Observabilidade profunda no Inspector: pipelines de request, queue e cache

Correspondência no CHANGELOG: `[0.2.0]`

---

## Fase 7 — AI-Native SparkPHP (concluída em 2026-03-29)

- SDK de AI unificado: `ai()` para texto, embeddings, imagem, áudio e agentes
- Convenções file-based para AI: `app/ai/agents`, `app/ai/tools`, `app/ai/prompts`
- Vector search first-party via `nearestTo()`, retrieval e RAG
- AI observável por default no Inspector com masking de dados sensíveis

Correspondência no CHANGELOG: `[0.3.0]` a `[0.6.0]`

---

## Fase 8 — Ecossistema e Diferenciais Competitivos (concluída em 2026-03-29)

- CLI de produto: `spark doctor`, `spark new`, `spark upgrade`, geração de spec
- Starter kits first-party: `api`, `saas`, `admin`, `docs`
- Documentação pública de nível produto: comparativos, benchmarks, guia de migração
- Checklist de review "mais curto, mais claro ou mais observável" como gate de contribuição

Correspondência no CHANGELOG: `[0.7.0]` a `[0.10.0]`

---

## Referência cruzada

- [CHANGELOG.md](../CHANGELOG.md) — histórico de releases públicas
- [TASKS.md](../TASKS.md) — roadmap ativo (Now/Next/Later)
- [docs/14-releases.md](14-releases.md) — política de versionamento e maturidade
