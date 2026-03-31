# SparkPHP — Roadmap

## Importante

Antes de abrir qualquer tarefa, leia os documentos fundacionais:

- `docs/architecture/01-spark-template.md`
- `docs/architecture/02-estrutura-framework.md`
- `docs/architecture/03-core-engine.md`
- `docs/architecture/04-identidade-filosofia.md`

Filtro obrigatório para toda nova feature ou mudança:

1. Fica mais curto?
2. Fica mais claro?
3. Fica mais observável?
4. Reduz boilerplate real?

Se a resposta for não para todos os quatro, a mudança não pertence ao core.

---

## Now — em execução

- [ ] Coerência entre docs e runtime (`docs/contract-matrix.md`)
  - Auditar os 9 comportamentos implícitos principais (view espelho, JSON/HTML, null→404, POST→201, route model binding, fillable, relações, middleware, events)
  - Para cada divergência: preferir reduzir promessa antes de expandir runtime
  - Criar testes de contrato para os 5 comportamentos mais críticos

- [ ] Segurança do Inspector (`docs/inspector-security.md`)
  - Masking de headers sensíveis: `Authorization`, `Cookie`, `X-API-Key`
  - Masking de inputs: `password`, `password_confirmation`, `token`, `secret`
  - Masking de AI prompts em staging/production
  - Garantir que `APP_ENV=production` → Inspector inacessível por padrão

---

## Next — próximo ciclo

- [ ] Template DSL: core vs avançado (`docs/template-core-vs-advanced.md`)
  - Classificar todas as diretivas em core essencial, avançado e candidato à revisão
  - Reorganizar `docs/04-views.md` com seção "Uso básico" de 1 tela
  - Não remover diretivas agora — apenas classificar e marcar candidatos futuros

- [ ] Contratos de inferência formalizados (`docs/inference-rules.md`)
  - Documentar o que o Spark infere (com precedência explícita)
  - Documentar o que o Spark nunca infere (garantias negativas)
  - Adicionar opt-out para route model binding

- [ ] Maturidade por subsistema visível no README
  - Adicionar tabela de maturidade no `README.md` (Estável / Beta / Experimental)
  - Atualizar `docs/14-releases.md` com política por subsistema

---

## Later — planejado, não imediato

- [ ] Modularização do AI SDK
  - Verificar se `core/Ai.php` pode ser carregado sob demanda no boot
  - Proposta formal de separação como `sparkphp/ai` (pacote Composer independente)
  - Pré-requisito: core estável, docs coerentes, maturidade publicada

- [ ] Testes de contrato público expandidos
  - Cobrir todos os comportamentos documentados em `docs/contract-matrix.md`
  - Estabelecer meta mínima de cobertura para subsistemas estáveis

- [ ] Opt-out explícito para convenções sensíveis
  - Route model binding desativável por rota
  - Inferência de view desativável por handler
  - Serialização automática desativável por response

---

## Icebox — adiado indefinidamente

- Comparativos de feature com Laravel como direção de produto
  (comparativos são material de adoção, não bússola de roadmap)

- Expansão horizontal antes de estabilizar coerência e fronteira do core

---

## Decisões em aberto

| Decisão | Status | Impacto |
|---|---|---|
| `sparkphp/ai` como pacote separado | Em avaliação | Core menor, DX mantida |
| Opt-out de inferência automática por convenção | Em avaliação | Previsibilidade, debugging |
| Política de masking de dados no Inspector (staging) | Pendente | Segurança operacional |
| Quais pipes de template pertencem ao core | Pendente | Superfície da DSL |
| Separação de `Queue` e `Mailer` como opcionais | Em avaliação | Core mais enxuto |

---

## Histórico

**Releases públicas:** [`CHANGELOG.md`](CHANGELOG.md) — mudanças de contrato, breaking changes e novas features por versão.

**Macrofases concluídas:** [`docs/roadmap-archive.md`](docs/roadmap-archive.md) — execução das 8 fases fundacionais até `0.10.0` (documentação, core, DX, estabilização, segurança, runtime, AI e ecossistema).
