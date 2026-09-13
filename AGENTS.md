# Pasta principal do projeto

Por solicitação explícita do usuário, o projeto deve ser desenvolvido e salvo em **C:\Users\Acer\Desktop\video**.

- Trabalhe diretamente nesta pasta. Não use worktrees externos ou pastas de visualização como fonte principal.
- Subagentes devem usar a mesma pasta principal e coordenar a propriedade dos arquivos.
- Salve novos códigos, testes, documentação e artefatos dentro desta pasta; entregas ficam em dist/.
- Preserve .env, mídia privada, banco local e alterações existentes. Nunca publique segredos.
- Backups locais e registros de consolidação ficam em .local-history/, ignorada pelo Git.
- Inicie o localhost a partir de tools/start-local.ps1 nesta pasta, porta 8093. Nunca mantenha dois workers concorrentes durante troca de runtime.
- O banco MariaDB instalado no XAMPP é um serviço externo ao código; não mova seu diretório de dados nem execute reparos/DDL destrutivos para organizar arquivos.
- A cópia histórica no worktree anterior é somente recuperação; não volte a editar nela sem pedido explícito.
