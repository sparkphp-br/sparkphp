# SparkPHP Language for VS Code

Extensao de linguagem para arquivos `.spark` do SparkPHP.

## O que esta extensao entrega

- syntax highlighting para diretivas Spark
- destaque para `{{ }}` e `{!! !!}`
- suporte a PHP embutido
- highlight para argumentos nomeados e pipes
- snippets para os blocos mais usados
- autocomplete de diretivas Spark ao digitar `@`
- autocomplete de pipes ao digitar `|`
- hover com ajuda rapida em diretivas e pipes
- configuracao basica de brackets, auto-close e folding

## Diretivas cobertas no grammar inicial

- `@layout`, `@extends`, `@title`, `@bodyClass`, `@content`
- `@partial`, `@css`, `@js`, `@stack`, `@once`
- `@if`, `@elseif`, `@else`, `@endif`
- `@auth`, `@role`, `@can`, `@dev`, `@prod`
- `@foreach`, `@empty`, `@for`, `@while`, `@repeat`
- `@form`, `@input`, `@select`, `@checkbox`, `@radio`, `@file`, `@hidden`, `@group`, `@submit`
- `@component`, `@slot`, `@hasslot`
- `@active`, `@img`, `@icon`, `@json`, `@meta`
- `@cache`, `@lazy`, `@php`

## Estrutura

- `package.json`: manifesto da extensao
- `language-configuration.json`: pares, folding e comportamento do editor
- `syntaxes/spark.tmLanguage.json`: grammar TextMate
- `snippets/spark.code-snippets`: snippets de produtividade

## Como testar localmente

1. Abra esta pasta no VS Code.
2. Rode `Extensions: Install from VSIX...` se voce empacotar com `vsce`.
3. Ou abra o workspace da extensao e pressione `F5` para iniciar um host de desenvolvimento do VS Code.

## Dica de uso no projeto

Se o arquivo `.spark` abrir como `Plain Text`, use `Change Language Mode` e selecione `Spark`.
Neste repositiorio a associacao `*.spark -> spark` ja foi adicionada em `.vscode/settings.json`.

## Empacotar

```bash
npm install -g @vscode/vsce
cd tools/vscode/sparkphp-language
vsce package
```
