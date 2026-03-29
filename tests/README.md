# Baseline de Qualidade da Suite

Esta suite existe para proteger o core do SparkPHP, não apenas para validar casos felizes.

## Gate mínimo

- `vendor/bin/phpunit` precisa terminar sem falhas.
- `skipped` só é aceitável para integrações externas opcionais, como testes de MySQL e PostgreSQL que dependem de ambiente configurado.
- Toda correção de bug em componentes centrais deve nascer com um teste de regressão no mesmo PR.

## Baseline do ambiente de teste

- PHP 8.3+
- SQLite 3.35+ para a suite local padrão
- MySQL 8.0+ e PostgreSQL 13+ como integrações externas opcionais

A suite já assume essa baseline em `composer.json`, nos testes de CLI/database e na cobertura do schema builder.

## Componentes críticos

Os seguintes componentes são tratados como fronteira de estabilidade do framework:

- `Router`
- `Middleware`
- `Request` / `Response`
- `Cache`
- `Queue`
- `AiManager`
- `helpers.php`
- `SparkInspector`
- `BenchmarkRunner`

## Suites que nao podem regredir silenciosamente

Algumas frentes agora sao tratadas como contratos publicos do produto:

- benchmark versionado via `php spark benchmark`
- observabilidade profunda do `SparkInspector` (pipelines, gargalos, queue/cache/request)
- surfaces publicas de versao (`VERSION`, CLI e relatorios gerados)
- AI file-based (`app/ai/*`), prompts nomeados, structured output e helpers publicos
- AI observability por default (`ai()` -> Inspector, masking, tokens/custo e smoke tests de CLI)
- vector search, retrieval e contratos de ranking do `QueryBuilder` / `ai()`

## Meta mínima de cobertura

Enquanto a cobertura ainda não é bloqueante no CI, a referência oficial do projeto passa a ser:

- `>= 80%` de cobertura nos componentes críticos
- `>= 70%` de cobertura geral do core

Ao instrumentarmos cobertura automatizada, essa meta deve virar critério verificável e não apenas guideline.
