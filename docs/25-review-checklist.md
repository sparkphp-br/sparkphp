# Review Checklist: Melhor que Laravel

Esse guia formaliza uma regra que ja existia na filosofia do SparkPHP:

> toda feature nova precisa ser mais curta, mais clara ou mais observavel do que a alternativa equivalente.

O objetivo nao e competir por quantidade de features. O objetivo e manter o Spark
coerente com sua proposta.

## Os tres eixos

### 1. Mais curta

A mudanca reduz o codigo necessario para o caso comum?

Sinais bons:

- menos arquivos tocados para usar a feature
- menos chamadas encadeadas
- menos wiring manual
- menos glue code entre partes do framework

### 2. Mais clara

A intencao fica obvia sem o dev precisar abrir documentacao toda hora?

Sinais bons:

- nomes e convencoes mais obvios
- menos "magia" escondida atras de API longa
- menos ambiguidade na leitura do codigo

### 3. Mais observavel

O efeito da feature fica visivel no produto?

Sinais bons:

- aparece no `spark`
- aparece no `SparkInspector`
- aparece em headers, benchmark ou docs publicas
- fica mais facil depurar ou medir

## Regra de merge

Uma mudanca relevante deve melhorar pelo menos um eixo **sem piorar gravemente os outros dois**.

Se ela:

- adiciona complexidade parecida com a do Laravel
- exige registro manual que a convencao poderia resolver
- fica mais longa no caso comum
- fica menos observavel

o reviewer deve pedir redesign ou rejeitar.

## O que todo PR deve responder

1. qual atrito real existia antes?
2. em qual eixo o Spark ficou melhor?
3. qual evidencia mostra isso?
4. qual trade-off foi aceito?

## Evidencia esperada

Review bom nao vive de adjetivo. Vive de evidência curta.

O autor deve trazer pelo menos um destes:

- before/after de codigo
- before/after de fluxo no CLI
- before/after no Inspector
- before/after de docs ou onboarding
- teste que proteja o contrato novo

## Exemplos de aprovacao

### Exemplo 1: aprovacao por ser mais curta

Situacao:

- antes, o caso comum exigia duas ou tres chamadas para fazer algo rotineiro
- depois, uma API direta resolveu o caso com menos boilerplate

Por que aprova:

- menos codigo
- mesma clareza
- contrato coberto por teste

### Exemplo 2: aprovacao por ser mais observavel

Situacao:

- a feature ja existia, mas nao aparecia no Inspector nem no CLI
- depois, o time consegue medir e depurar sem instalar nada extra

Por que aprova:

- reduziu custo operacional
- melhorou debug
- deixou o framework mais coerente com a proposta de produto

### Exemplo 3: aprovacao por ser mais clara

Situacao:

- havia duas convencoes competindo pela mesma coisa
- a mudanca consolidou uma convencao unica e mais obvia

Por que aprova:

- menos ambiguidade
- onboarding mais simples
- menor custo mental para lembrar o fluxo

## Exemplos de rejeicao

### Exemplo 1: feature parity cega

Situacao:

- "Laravel tem X, entao o Spark tambem precisa ter X"

Por que rejeita:

- nao prova ganho no caso comum
- tende a adicionar camada de cola
- move o Spark para complexidade sem identidade

### Exemplo 2: API mais longa

Situacao:

- a nova feature adiciona objeto, registry, config e bootstrap para resolver um caso simples

Por que rejeita:

- piora o eixo "mais curta"
- aumenta superficie mental
- deixa o Spark mais parecido com o problema que ele deveria evitar

### Exemplo 3: invisivel em runtime

Situacao:

- a mudanca afeta operacao, mas nao deixa nenhum rastro em CLI, Inspector, benchmark ou docs

Por que rejeita:

- piora debuggabilidade
- reduz previsibilidade
- contradiz o eixo "mais observavel"

## Onde esse checklist vive no processo

Esse gate agora aparece em tres lugares:

- `docs/architecture/04-identidade-filosofia.md`
- `CONTRIBUTING.md`
- `.github/PULL_REQUEST_TEMPLATE.md`

Ou seja: filosofia, contribuicao e abertura de PR passam a falar a mesma lingua.

## Resumo

A pergunta correta em review nao e:

- "funciona?"

Ela e:

- "funciona de um jeito que deixa o Spark mais curto, mais claro ou mais observavel?"
