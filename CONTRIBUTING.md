# Contributing to SparkPHP

SparkPHP aceita contribuicoes, mas nao aceita complexidade gratuita.

O criterio central deste projeto nao e "ter mais features". E fazer o framework
ficar:

- mais curto
- mais claro
- mais observavel

Se uma mudanca nao melhora pelo menos um desses eixos no caso comum, ela ainda nao
esta pronta.

## Fluxo esperado

1. entenda a filosofia do projeto em `docs/architecture/04-identidade-filosofia.md`
2. confira a documentacao existente em `docs/`
3. implemente a mudanca com o menor numero de conceitos novos possivel
4. atualize docs e testes quando o comportamento publico mudar
5. abra o PR preenchendo o checklist "melhor que Laravel"

## Gate obrigatorio: melhor que Laravel

Toda feature nova ou mudanca relevante de DX precisa responder estas perguntas:

1. ela ficou **mais curta**?
2. ela ficou **mais clara**?
3. ela ficou **mais observavel**?

O PR deve mostrar, com texto curto e evidencia concreta:

- qual eixo melhorou
- qual era o atrito anterior
- o que mudou no caso comum
- qual foi o trade-off aceito

Se a mudanca nao melhora nenhum desses eixos, a revisao correta e **rejeitar ou pedir redesign**.

Guia detalhado:

- `docs/25-review-checklist.md`

## O que toda contribuicao deve trazer

- testes para regressao ou para o novo contrato publico
- documentacao quando a superficie publica mudar
- alinhamento com a filosofia file-based e zero-config
- impacto observavel em CLI, Inspector, headers ou docs, quando fizer sentido

## O que reviewers devem bloquear

- feature parity cega com Laravel
- novos registries, providers ou camadas de cola sem necessidade
- APIs mais longas do que o caso atual
- comportamento menos previsivel sem ganho claro
- mudanca publica sem docs ou sem teste

## Checklist minimo antes de abrir PR

- rode `composer lint`
- rode `composer analyse`
- rode `vendor/bin/phpunit --display-skipped`
- revise `docs/README.md` se a mudanca for publica
- atualize guias especificos em `docs/` quando necessario
- preencha `.github/PULL_REQUEST_TEMPLATE.md`

## Sobre versionamento

`VERSION` e `CHANGELOG.md` sao superfícies de release do produto. Nem todo PR precisa
alterar esses arquivos. Atualize-os quando a mudanca estiver sendo publicada como uma
release de produto ou quando isso for pedido explicitamente no trabalho em andamento.
