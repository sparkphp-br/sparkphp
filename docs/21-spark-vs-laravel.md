# SparkPHP vs Laravel

Esta pagina existe para responder uma pergunta recorrente sem cair em uma armadilha:
**SparkPHP nao precisa virar uma copia do Laravel para ser melhor em certos cenarios.**

O comparativo correto nao e “quem tem mais features?”. E:

- qual framework pede menos wiring para o caso comum?
- qual deixa o comportamento mais previsivel?
- qual expoe melhor o que esta acontecendo em runtime?

## Regra de leitura

Se voce quer:

- ecossistema gigantesco
- mercado enorme de devs
- bibliotecas first-party maduras para billing, filas, admin, deploy e observabilidade

o Laravel continua sendo uma aposta muito forte.

Se voce quer:

- menos arquivos de cola
- menos configuracao para o core funcionar
- menos superficie mental para manter
- mais visibilidade nativa do runtime

o SparkPHP foi desenhado exatamente para isso.

## O eixo correto de comparação

| Eixo | Laravel | SparkPHP |
|------|---------|----------|
| Wiring interno | Mais explicito | Mais implicito por convencao |
| Roteamento | Arquivo central / atributos / pacotes | File-based por default |
| Configuracao do core | Mais extensa | `.env` obrigatorio + defaults fortes |
| Observabilidade | Muito boa, mas frequentemente via pacotes/produtos | Nativa no core e no CLI |
| Curva para dominar o framework | Maior | Mais curta e mais flat |
| Ecossistema | Muito maior | Intencionalmente menor |
| Flexibilidade estrutural | Mais alta | Menor, em troca de previsibilidade |

## Onde o Spark quer ser melhor

### 1. Menos cola entre as partes

No Spark, o framework tenta descobrir sozinho:

- rotas
- middlewares
- eventos
- tools e agents de AI
- starter kits

Se voce precisa registrar manualmente algo que a convencao poderia resolver, isso
conta como cheiro de design.

### 2. Menos superficie mental

O Spark tenta manter poucas regras, aplicadas do mesmo jeito em varias areas:

- o arquivo diz o que algo e
- o local diz como aquilo se aplica
- o retorno define a resposta
- o type-hint define a resolucao

O objetivo e que o time precise consultar menos documentacao operacional no dia a dia.

### 3. Mais observabilidade first-party

O Spark considera observabilidade parte do produto, nao um acessorio.

Hoje isso aparece em:

- `php spark about`
- `php spark benchmark`
- `SparkInspector`
- traces nativos de cache, queue, request e AI
- benchmark versionado por release

### 4. Menos feature parity cega

O Spark nao deveria adicionar uma feature so porque o Laravel tem uma feature com
nome parecido.

A regra e outra:

1. a feature reduz codigo no caso comum?
2. a feature fica mais clara que a alternativa no Laravel?
3. a feature fica mais observavel?

Se a resposta for “nao”, a feature provavelmente ainda nao esta pronta.

## Onde o Laravel continua melhor

Em varios cenarios, de forma objetiva:

- times grandes que precisam contratar rapido
- produtos que dependem de um ecossistema vasto imediatamente
- casos em que Nova / Horizon / Forge / Vapor / Cashier resolvem uma parte relevante do problema
- migracoes onde o custo de trocar stack excede o ganho operacional

Isso nao enfraquece o Spark. So deixa a escolha honesta.

## Quando escolher SparkPHP

Escolha Spark quando voce quer:

- construir produto novo sem carregar um framework inteiro nas costas
- padronizar um time pequeno ou medio em torno de poucas convencoes
- reduzir o tempo gasto conectando partes do proprio framework
- manter a stack visivel por default
- tratar AI, benchmark e inspeccao como partes do produto desde o inicio

## Quando nao escolher SparkPHP

Evite Spark, pelo menos por enquanto, quando:

- o projeto depende fortemente de pacotes Laravel-first
- a organizacao precisa de um ecossistema pronto maior do que o ganho de simplicidade
- o time nao quer trocar flexibilidade estrutural por convencao forte
- a empresa precisa de uma escolha conservadora e amplamente padronizada no mercado hoje

## Resumo curto

Laravel ganha em ecossistema.

Spark quer ganhar em:

- simplicidade operacional
- previsibilidade estrutural
- observabilidade nativa

Essa e a comparacao certa.
