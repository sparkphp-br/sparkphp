# SparkPHP — Roadmap de Tasks

## Importante

Sempre analise os seguintes documentos (vão criar uma orientação melhor do projeto):
01-spark-template.md
02-estrutura-framework.md
03-core-engine.md
04-identidade-filosofia.md

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
- [ ] Task 3.3 — Documentação pública
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
