# Matriz de Coerência: Docs vs Runtime

Este documento audita os comportamentos prometidos pela documentação e verifica se o runtime os implementa conforme descrito.

**Regra principal:** quando houver divergência, preferir reduzir a promessa antes de expandir o runtime.

**Exceção crítica:** quando a divergência tocar o núcleo da proposta pública do Spark — file-based routing, resposta inteligente por Accept, view espelho, convenção de middleware ou observabilidade básica — preferir corrigir o runtime antes de reduzir a promessa, salvo se o custo arquitetural for desproporcional. Esses comportamentos são o produto; encolhê-los enfraquece a tese do framework.

---

## Comportamentos de resposta implícita

| Comportamento | Prometido em | Implementado em | Status |
|---|---|---|---|
| Array + `Accept: text/html` → view espelho | `docs/architecture/03-core-engine.md`, `docs/04-views.md` | `Response.php` + `Router.php` | ✅ Coerente |
| Array + `Accept: application/json` → JSON 200 | `docs/architecture/03-core-engine.md` | `Response.php` | ✅ Coerente |
| `null` em GET → 404 automático | `docs/architecture/03-core-engine.md` | `Response.php` | ✅ Coerente |
| Objeto/array em POST → 201 automático | `docs/architecture/03-core-engine.md` | `Response.php` | ✅ Coerente |
| String → HTML 200 | `docs/architecture/03-core-engine.md` | `Response.php` | ✅ Coerente |

## Route model binding

| Comportamento | Prometido em | Implementado em | Status |
|---|---|---|---|
| Type-hint de Model + param URL → `findOrFail()` | `docs/architecture/03-core-engine.md`, `docs/02-routing.md` | `Container.php` + `Router.php` | ✅ Coerente |
| Falha de binding → 404 com mensagem clara | `docs/02-routing.md` | `Container.php` | ✅ Coerente |
| Separação clara de route binding vs DI de services | `docs/02-routing.md` | `Container.php` | ✅ Coerente |

## Model e inferência de schema

| Comportamento | Prometido em | Implementado em | Status |
|---|---|---|---|
| `fillable` inferido das colunas da tabela | `docs/architecture/03-core-engine.md` | `Model.php` + `Schema.php` | ✅ Coerente |
| Relações inferidas de FKs do schema | `docs/architecture/03-core-engine.md` | `Relation.php` | ⚠️ Parcial — apenas para FK explícitas com nomenclatura convencional |
| Timestamps automáticos (`created_at`, `updated_at`) | `docs/architecture/03-core-engine.md` | `Model.php` | ✅ Coerente |

## Middleware

| Comportamento | Prometido em | Implementado em | Status |
|---|---|---|---|
| `_middleware.php` global aplicado a toda a árvore | `docs/architecture/02-estrutura-framework.md` | `Middleware.php` + `Router.php` | ✅ Coerente |
| Middleware por diretório de rotas | `docs/architecture/02-estrutura-framework.md` | `Router.php` | ✅ Coerente |
| Ordem: global → diretório → guard inline → handler | `docs/architecture/02-estrutura-framework.md` | `Middleware.php` | ✅ Coerente |
| Middleware não silencia erros — falha com status claro | `docs/architecture/04-identidade-filosofia.md` | `Middleware.php` | ✅ Coerente |

## Events por filename

| Comportamento | Prometido em | Implementado em | Status |
|---|---|---|---|
| `users.created.php` dispara ao criar User | `docs/architecture/02-estrutura-framework.md` | `EventEmitter.php` | ✅ Coerente |
| Auto-discovery de eventos em `app/events/` | `docs/architecture/02-estrutura-framework.md` | `EventEmitter.php` | ✅ Coerente |

## Template engine

| Comportamento | Prometido em | Implementado em | Status |
|---|---|---|---|
| View espelho automática sem `@extends` | `docs/architecture/01-spark-template.md` | `View.php` | ✅ Coerente |
| Layout padrão `layouts/main.spark` | `docs/architecture/01-spark-template.md` | `View.php` | ✅ Coerente |
| Escape contextual automático em `{{ }}` | `docs/architecture/01-spark-template.md` | `View.php` | ✅ Coerente |
| `{!! !!}` sem escape (opt-in) | `docs/architecture/01-spark-template.md` | `View.php` | ✅ Coerente |
| Compilação cacheada em `storage/cache/views/` | `docs/architecture/01-spark-template.md` | `View.php` | ✅ Coerente |

---

## Comportamentos com ressalvas

### Relações inferidas de FKs (⚠️ Parcial)

A documentação (`docs/architecture/03-core-engine.md`) promete que relações são inferidas de FKs do schema. Na prática, a inferência funciona apenas para FKs que seguem a nomenclatura convencional (`{tabela}_id`). FKs com nomes não-convencionais precisam ser declaradas explicitamente.

**Ação recomendada:** Documentar esta limitação em `docs/05-database.md` antes de ampliar a cobertura do runtime.

---

## Comportamentos de contrato negativo

O Spark nunca deve fazer o seguinte — se fizer, é um bug:

| Garantia negativa | Verificado em |
|---|---|
| Nunca executa handler sem rota correspondente | `Router.php` |
| Nunca persiste dados sem `save()`/`create()` explícito | `Model.php` |
| Nunca escapa menos do que o necessário | `View.php` |
| Nunca silencia erro de middleware | `Middleware.php` |
| Nunca expõe Inspector em `APP_ENV=production` por padrão | `SparkInspector.php` |

---

## Procedimento de auditoria

Para auditar um comportamento, não basta verificar uma classe isolada. Para cada item da matriz, auditar:

- **Router.php** — resolução de rota e view espelho
- **Response.php** — inferência de status e formato por Accept
- **Container.php** — resolução de dependências e route model binding
- **Model.php + Schema.php** — fillable inferido e lifecycle events
- **Middleware.php** — ordem de execução e comportamento em falha
- **EventEmitter.php** — auto-discovery por filename
- **Testes existentes** — confirmar que o comportamento está coberto por teste de contrato

Uma auditoria incompleta que verifica apenas o arquivo principal pode perder o ponto real da implementação.

## Como usar este documento

1. Ao implementar um comportamento novo, adicionar uma linha aqui antes de mergear.
2. Ao encontrar uma divergência, abrir issue com referência a esta matriz.
3. Ao alterar comportamento existente, atualizar o status aqui junto com o código.

Status possíveis: ✅ Coerente · ⚠️ Parcial · ❌ Incoerente · 🔲 Não implementado
