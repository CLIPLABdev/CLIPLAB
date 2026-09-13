# Consolidar projeto na pasta video

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox tracking.

**Goal:** Tornar C:\Users\Acer\Desktop\video a fonte principal e o diretório usado pelo localhost.
**Architecture:** Cópia não destrutiva do worktree funcional, preservando .git e arquivos exclusivos do destino. Arquivos anteriores sobrepostos recebem backup; junctions de dependências são remapeadas somente para alvos internos confirmados.
**Tech Stack:** PowerShell, Git somente leitura, PHP e Chrome.
**Spec:** Pedido explícito do usuário para sempre salvar todo o projeto na pasta video.

## Global Constraints

- Não apagar a origem, não fazer reset/checkout, não mover .git nem dados do XAMPP.
- Não expor .env; corrigir apenas APP_URL e YTDLP_BINARY na cópia.
- Nenhum worker concorrente na troca; manter porta 8093.
- Preservar backups privados e as credenciais existentes.

## Tarefa única: consolidação verificável

- [x] Inventariar origem/destino, espaço e diferenças; inspeção independente do runtime.
- [ ] Fazer dry-run do copiador com caminhos fixos, validar reparse points e backup de sobreposições.
- [ ] Copiar todos os arquivos da origem exceto .git; recriar junctions de node_modules apontando para video.
- [ ] Confirmar fila ociosa; encerrar somente o web antigo e aguardar supervisor/worker; manter MariaDB.
- [ ] Sincronizar a cópia final, ajustar .env via apply_patch e registrar .local-history/dist no .gitignore.
- [ ] Iniciar tools/start-local.ps1 -ProjectRoot C:\Users\Acer\Desktop\video -Port 8093 -WithWorker em background oculto.
- [ ] Verificar hashes, autoload, testes focados puros/SQLite e acesso HTTP autenticado a projeto/editor/download.
- [ ] Documentar a pasta canônica e deixar a origem preservada para recuperação.
