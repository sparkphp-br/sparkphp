# Editorial Workflow

Esse starter transforma o projeto em um portal de documentação first-party sem exigir
CMS, pacote de docs ou build step adicional.

## O que já vem pronto

- rota raiz redirecionando para `/documents`
- renderer Markdown integrado ao Spark
- índice público de guias em `app/routes/docs/index.php`
- versionamento do produto via `VERSION` e `CHANGELOG.md`

## Fluxo recomendado

1. escreva cada guia como um arquivo `NN-topico.md` em `docs/`
2. mantenha títulos em `# H1` para o índice usar o nome humano
3. revise a home pública em `app/views/docs/*`
4. publique a release atualizando `VERSION` e `CHANGELOG.md`

## Zero-config de verdade

Você não precisa registrar páginas, gerar menus manualmente ou sincronizar uma base
de dados. O portal lê `docs/*.md`, ordena por nome e expõe tudo automaticamente.
