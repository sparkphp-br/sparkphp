# SparkPHP — Roadmap de Tasks

## Importante

Sempre analise os seguintes documentos (vão criar uma orientação melhor do projeto):
01-spark-template.md
02-estrutura-framework.md
03-core-engine.md
04-identidade-filosofia.md

## Direção do Roadmap (pós-core)

- Primeiro corrigir incoerências entre filosofia, documentação e runtime
- Depois fortalecer segurança, estabilidade, APIs e observabilidade
- Só então expandir forte em AI, search e features que disputam diretamente com o Laravel
- Toda feature nova relevante deve nascer com docs, testes e integração no Inspector/CLI quando fizer sentido

## Fase 1 — Documentação Fundacional

- [x] Task 1.1 — Spark Template (template engine)
  - Documento completo com diretivas, pipes, comparativo com Blade
  - Extensão `.spark` definida
  - Seção de eliminação de verbosidades

- [x] Task 1.2 — Estrutura do Framework
  - Árvore completa de diretórios e arquivos
  - Convenções de nomeação e resolução automática
  - Mapa: camada → convenção → exemplo

- [x] Task 1.3 — Core Engine (Arquitetura)
  - Componentes internos: Router, Autoloader, Bootstrap, Container, etc.
  - Fluxo de request completo (boot → route → middleware → response)
  - Estratégia de cache e compilação

- [x] Task 1.4 — Identidade e Filosofia
  - Manifesto do SparkPHP
  - Princípios de design
  - Convenções gerais unificadas

## Fase 2 — Implementação do Core ✅ Concluída em 2026-03-27

- [x] Task 2.1 — Bootstrap + Autoloader
- [x] Task 2.2 — Router (file-based)
- [x] Task 2.3 — Request / Response
- [x] Task 2.4 — Spark Template Engine (compilador)
- [x] Task 2.5 — Database / Model
- [x] Task 2.6 — Middleware Engine
- [x] Task 2.7 — Container (DI)
- [x] Task 2.8 — Validator
- [x] Task 2.9 — Event Emitter
- [x] Task 2.10 — Session / Cache
- [x] Task 2.11 — CLI (`spark`)
- [x] Task 2.12 — Helpers globais

## Fase 3 — Ferramentas e DX (futuro)

- [x] Task 3.1 — `composer create-project` skeleton
- [x] Task 3.2 — Extensão VS Code para `.spark`
  - [x] Grammar TextMate para diretivas/echos Spark
  - [x] `language-configuration.json` com folding e auto-close
  - [x] Snippets iniciais para blocos principais
  - [x] Pacote `.vsix` gerado e instalado localmente no VS Code
  - [x] Runtime ativo com autocomplete e hover para `.spark`
- [x] Task 3.3 — Documentação pública
  - [x] 13 guias em `docs/` cobrindo todas as funcionalidades
  - [x] Exemplos práticos e executáveis em cada seção
  - [x] Referência completa de helpers, CLI, diretivas e regras de validação
  - [x] README.md com índice, quick start e princípios do framework
- [x] Task 3.4 — Testes do core
  - [x] Suite inicial PHPUnit configurada
  - [x] Testes de Router (params dinâmicos, 405, rota raiz)
  - [x] Cobertura de Request/Response/Container/Middleware
  - [x] Cobertura inicial de View (`@cache`)
  - [x] Cobertura de Validator (required, email, min/max, between, regex, etc.)
  - [x] Cobertura de EventEmitter (listeners, cancelamento, off, file-based)
  - [x] Cobertura de Cache (set/get, forget, increment, remember, flush)
  - [x] Cobertura de Helpers (encrypt/decrypt, hash, env, url, asset, now)
  - [x] Expandir cobertura para integração HTTP end-to-end
- [x] Task 3.5 — Benchmarks comparativos
  - [x] Comando CLI `php spark benchmark`
  - [x] Relatório JSON salvo em `storage/benchmarks/latest.json`
  - [x] Comparativos cold/warm para autoloader e views
  - [x] Comparativos de roteamento estático/dinâmico e container
- [x] Task 3.6 — Spark Inspector nativo
  - [x] Toolbar HTML + painel interno em `/_spark`
  - [x] Histórico persistido em `storage/inspector`
  - [x] Headers `X-Spark-*` e `Server-Timing`
  - [x] Coletores de request/response, rota, timeline, views, queries, cache, logs, events, mail, queue, dumps e exceptions
  - [x] Helpers `inspect()` e `measure()`
  - [x] Comandos CLI `php spark inspector:status` e `php spark inspector:clear`

## Fase 4 — Estabilização e Coerência do Produto (curto prazo)

- [x] Task 4.1 — Alinhar filosofia, docs e runtime
  - Decidir oficialmente o papel de `config()` vs `.env`
  - Remover promessas que não existem no core ou implementar o que já foi documentado
  - Revisar README, docs e exemplos para refletirem o comportamento real do framework

- [x] Task 4.2 — Suite 100% verde e baseline de qualidade
  - Corrigir a falha atual do `SparkInspector` na suite end-to-end
  - Fechar lacunas de regressão em Router, Middleware, Helpers e Inspector
  - Definir meta mínima de cobertura para componentes críticos

- [x] Task 4.3 — Middleware global e por diretório de verdade
  - Implementar `_middleware.php` global e por pasta conforme a documentação
  - Garantir ordem previsível: global → diretório → guard inline → handler
  - Cobrir com testes de precedência, bloqueio e composição

- [x] Task 4.4 — Baseline moderno do framework
  - Elevar requisito mínimo para PHP 8.3+
  - Revisar compatibilidade com SQLite, MySQL e PostgreSQL suportados
  - Atualizar documentação, `composer.json` e suite de testes para a nova baseline

- [x] Task 4.5 — Política pública de releases e compatibilidade
  - Definir versionamento semântico do SparkPHP
  - Criar política de suporte para PHP, banco e segurança
  - Publicar guia de upgrade e política de deprecações

## Fase 5 — Segurança, HTTP e APIs (médio prazo)

- [ ] Task 5.1 — `PreventRequestForgery` nativo
  - Evoluir o CSRF atual para validação por token + `Origin`/`Referer`
  - Padronizar comportamento para HTML, JSON, AJAX e proxies confiáveis
  - Adicionar opções seguras por default para cookie/session

- [ ] Task 5.2 — Request / Response v2
  - Melhorar content negotiation além de HTML/JSON básico
  - Padronizar envelopes de erro, redirects, downloads e respostas vazias
  - Preparar a base para responses mais avançadas, inclusive streaming

- [ ] Task 5.3 — Resources first-party e JSON:API opcional
  - Criar camada de serializers/resources para responses HTTP
  - Suportar paginação, `links`, `meta`, relacionamento e sparse fields
  - Oferecer compliance JSON:API sem obrigar o projeto inteiro a usar o padrão

- [ ] Task 5.4 — Route model binding implícito e autorização mais inteligente
  - Resolver modelos automaticamente a partir de parâmetros file-based
  - Reduzir boilerplate em handlers como `fn(User $user)`
  - Definir base para policies/authorize sem perder a simplicidade do Spark

- [ ] Task 5.5 — Geração de contratos de API
  - Extrair spec OpenAPI a partir de rotas, validação e resources
  - Expor comando CLI para gerar/atualizar a spec
  - Preparar terreno para SDKs e documentação automática

## Fase 6 — Runtime, Dados e Filas (médio prazo)

- [ ] Task 6.1 — Queue v2
  - Adicionar roteamento por classe/job
  - Suportar `tries`, `backoff`, `timeout`, `failOnTimeout` e filas de falha mais ricas
  - Melhorar comandos CLI para retry, inspect e limpeza seletiva

- [ ] Task 6.2 — Cache v2
  - Adicionar `touch()` como extensão de TTL sem regravar valor
  - Considerar `stale-while-revalidate`, tags e métricas de cache
  - Integrar melhor os eventos de cache ao Inspector

- [ ] Task 6.3 — Query Builder / ORM v2
  - Expandir operadores modernos de banco e ergonomia do Query Builder
  - Melhorar relacionamentos, eager loading e serialização
  - Preparar a base para features semânticas e vetoriais

- [ ] Task 6.4 — Semantic / Vector Search nativo
  - Criar suporte first-party para embeddings e consulta por similaridade
  - Integrar PostgreSQL + `pgvector` como alvo inicial
  - Expor APIs simples no Query Builder e no fluxo de AI

- [ ] Task 6.5 — Observabilidade profunda
  - Evoluir o Inspector para mostrar pipelines completos de request, queue e cache
  - Adicionar visibilidade melhor para jobs, falhas, retries e bottlenecks
  - Consolidar benchmarks comparativos do SparkPHP em cenários reais

## Fase 7 — AI-Native SparkPHP (longo prazo)

- [ ] Task 7.1 — SDK de AI unificado do SparkPHP
  - Criar API única para texto, embeddings, imagem, áudio e agentes
  - Evitar fragmentação de conceitos e manter uma DX coesa
  - Projetar adaptadores provider-agnostic desde o início

- [ ] Task 7.2 — Convenções file-based para AI
  - Estruturar `app/ai/agents`, `app/ai/tools` e `app/ai/prompts`
  - Descoberta automática de agentes e ferramentas por convenção
  - Integrar tool-calling e structured output com a mesma filosofia do framework

- [ ] Task 7.3 — AI + Search + Data
  - Conectar embeddings, vector search e retrieval ao core do framework
  - Facilitar RAG sem transformar o Spark em uma colagem de pacotes
  - Oferecer APIs curtas para casos comuns e extensibilidade para casos avançados

- [ ] Task 7.4 — AI observável por default
  - Mostrar latência, custo, tokens, provider e tool calls no Inspector
  - Adicionar tracing de prompts e respostas com mascaramento de dados sensíveis
  - Criar comandos CLI de diagnóstico e smoke-test para integrações de AI

## Fase 8 — Ecossistema e Diferenciais Competitivos (longo prazo)

- [ ] Task 8.1 — CLI de produto
  - Adicionar comandos como `spark doctor`, `spark new`, `spark upgrade` e geração de spec
  - Tornar o CLI a interface principal de setup, diagnóstico e evolução do projeto

- [ ] Task 8.2 — Starter kits first-party
  - Criar presets para API, SaaS, painel administrativo e documentação
  - Garantir que cada starter kit preserve a filosofia zero-config do SparkPHP

- [ ] Task 8.3 — Documentação pública de nível produto
  - Criar comparativos claros contra Laravel sem cair em feature parity cega
  - Publicar guias de adoção, benchmark e migração
  - Consolidar narrativa: mais simples, mais previsível, mais observável

- [ ] Task 8.4 — Critério permanente de “melhor que Laravel”
  - Toda nova feature deve ser mais curta, mais clara ou mais observável que a alternativa do Laravel
  - Rejeitar complexidade que exija providers, registries ou configuração desnecessária
  - Medir sucesso por redução de boilerplate, previsibilidade e velocidade de entrega
