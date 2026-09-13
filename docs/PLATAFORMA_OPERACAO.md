# Operação da plataforma — 07/09/2026

Este guia descreve comandos CLI finitos. Ele não inicia cron, não envia e-mail, não cobra e não executa migração por conta própria. Execute tudo na raiz privada da aplicação, nunca por rota HTTP, com backup e operador autorizado.

## Configuração que deve permanecer privada

- Preserve o `.env` existente durante a publicação. Não copie o `.env` para o artefato, Git, diretório público ou chamado de suporte.
- Preserve a mesma `APP_ENCRYPTION_KEY` válida na web, workers e backup privado. Ela protege segredos cifrados persistidos; substituí-la sem recifragem torna a configuração administrativa anterior indisponível.
- Em ambiente público, `APP_URL` deve ser uma origem HTTPS real, sem caminho, usuário, consulta ou fragmento. Configure TLS/certificado antes de testar links de cancelamento, retorno de checkout ou webhooks.
- O SMTP é configurado pelo administrador na configuração de e-mail e o segredo é armazenado cifrado no banco. Não coloque senha SMTP em formulário público, log ou argumento de shell. Use credenciais de teste autorizadas antes de qualquer entrega real.

## Schema: instalação nova versus upgrade

Em uma **base nova**, após conferir `.env`, conexão e backup do ambiente, use o fluxo de instalação normal:

```sh
php bin/migrate.php
```

Esse comando aplica migrations do pacote e executa o seeder de planos. Portanto, **não o use como upgrade de uma base existente**: o seeder pode regravar o catálogo de planos.

Em uma **base existente**, use somente o atualizador aditivo:

```sh
# leitura: mostra pending e valida que não há estado parcial
php tools/activate-platform.php

# somente depois de revisar o preflight e ter backup privado
php tools/activate-platform.php --apply

# confirme que o preflight posterior retorna pending: []
php tools/activate-platform.php
```

O atualizador aceita apenas a sequência aditiva 010, 020, 021, 022, 023, 030 e 040, inclui a projeção de notificações de mídia da 023 e faz seu próprio snapshot privado antes do DDL. Não publique nem inclua esse snapshot no pacote. Se o preflight acusar migração parcial, tabela de origem ausente ou lock ocupado, pare e inspecione: não repita `--apply` às cegas.

No ambiente já ativado desta entrega, o preflight final registrou `pending: []`; a baseline da projeção registrou 105 entidades e `emitted: 0`. Isso é evidência operacional daquele ambiente, não autorização para pular preflight/backup em outro host.

## Projeção de notificações de mídia

Após aplicar a migração 023, inicialize os checkpoints **uma única vez**, antes de processar lotes:

```sh
php bin/project-media-notifications.php --initialize
```

Depois, execute lotes finitos conforme a capacidade do host; por exemplo:

```sh
php bin/project-media-notifications.php --source=all --limit=25 --batches=1
```

`--initialize` não deve ser usado para reinicializar uma base já projetada. O comando de lote aceita `--source=all|projects|clips|jobs`, `--limit=1..100` e `--batches=1..10`.

## E-mail, preferências e campanhas

O worker de e-mail é separado da fila de mídia e só processa uma quantidade limitada por invocação:

```sh
php bin/process-email.php --limit=1
```

O limite aceito é `1..10`. O comando exige transporte SMTP configurado; sem SMTP ele falha fechado e não usa fallback de log. Antes de criar cron, valide manualmente com credenciais de teste autorizadas e uma conta de QA. Aceite SMTP não comprova entrega na caixa de entrada.

**O comando não aceita filtro de destinatário:** ele consome a próxima mensagem elegível da fila global. `--limit=1` não significa “somente a conta QA”. Faça a homologação em base isolada com destinatários de teste, ou revise integralmente a fila e obtenha autorização para os destinatários presentes antes de executar. O botão administrativo de teste SMTP é uma ação manual separada e só aceita o e-mail do próprio administrador; não o confunda com o worker automático.

Campanhas não enviam SMTP diretamente. O comando abaixo apenas cria entradas elegíveis na outbox:

```sh
php bin/process-campaigns.php
```

O descadastro, preferências e validação de destinatário continuam sendo verificados pelo worker no momento de entrega. Não habilite cron de campanhas ou e-mail até concluir teste controlado de credenciais, consentimento e destinatários de QA.

Se um agendador for habilitado após homologação, cada comando deve ser uma invocação CLI finita e separada. Não há cron ativo por este guia e nenhum dos exemplos deve ser interpretado como autorização para envio real.

## Checkout e gateways

Configure gateways apenas pelo fluxo administrativo, com credenciais de sandbox/teste autorizadas. A página de retorno do navegador não ativa plano; confirmação remota/webhook validado é a fonte de verdade. Sandbox é auditoria de teste e não concede entitlement operacional. Para homologar Stripe ou Pagar.me, são necessárias credenciais de teste e origem HTTPS pública para os callbacks/webhooks; não simule pagamento nem use credenciais de produção como teste.

## Sequência segura de homologação

1. Publique código preservando `.env`, mídia e backups privados fora da área pública.
2. Confirme HTTPS e `APP_URL` antes de qualquer fluxo com link externo.
3. Escolha o comando de schema correto para base nova ou upgrade; registre apenas saída sanitizada.
4. Inicialize a projeção de mídia uma vez e valide um lote mínimo sem dados reais desnecessários.
5. Configure SMTP administrativo com credenciais de teste e execute `process-email.php --limit=1` para uma conta QA autorizada.
6. Só então avalie agendamentos finitos e separados para projeção, campanhas e e-mail.
7. Homologue gateways sandbox com webhook HTTPS antes de habilitar qualquer uso comercial.

Nada neste guia substitui backup/restauração testados, revisão de acesso administrativo, política de retenção ou homologação real do host.
