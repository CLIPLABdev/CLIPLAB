# Implantação na Hostinger

Este guia instala o ClipForge sem servidor Node.js, Docker, Redis, WebSocket ou processo permanente da aplicação. A importação do YouTube requer yt-dlp e um runtime JavaScript local compatível (por exemplo Node.js), configurado em YTDLP_JS_RUNTIME. Para o sistema completo na Hostinger, a opção operacional é um VPS com PHP/MySQL e FFmpeg; hospedagem Web/Cloud isolada não executa a renderização. Uma web separada exige worker e armazenamento privado realmente compartilhado. Consulte a seção 15 antes de publicar.

Precisão de duração sem nova coluna de banco: novos pedidos de exportação, inclusive no editor, executam um preflight FFprobe no POST web autenticado antes de abrir a transação. Portanto, **o host web também precisa de proc_open, FFprobe e acesso aos mesmos arquivos privados**; apenas mover o worker para VPS não basta para esses envios. A construção do serviço/GET não executa o probe. Sem medição precisa o envio falha com orientação, sem fallback para a duração inteira usada por créditos/quotas. Um fim além do EOF é recusado com limite em milissegundos, sem cortar o SRT silenciosamente. Configure FFPROBE_BINARY, PROCESS_TIMEOUT_SECONDS e PROCESS_OUTPUT_LIMIT_BYTES no web e no worker; não exponha mídia ou crie rota pública de probe.

## 1. Conferir o plano

No servidor de produção, use uma versão de PHP com suporte ativo e atualizações de segurança; a opção de referência em 07/09/2026 é PHP 8.4 na última versão corretiva. PHP 8.0.30 foi o runtime local destes testes e não deve ser usado como recomendação de produção: PHP 8.0 já está fora de suporte. Valide a aplicação na versão escolhida antes do lançamento; compatibilidade mínima no Composer não equivale a homologação desse host. [Ciclo oficial de suporte do PHP](https://www.php.net/supported-versions.php).

Habilite pdo, pdo_mysql, mbstring, fileinfo e curl. Escolha uma versão mantida de MySQL/MariaDB que respeite os mínimos técnicos MySQL 8.0.16+ ou MariaDB 10.4+, com CHECK ativo; esses mínimos não recomendam versões antigas sem suporte. No MariaDB, `@@check_constraint_checks` deve ser `1`. Crie usuário exclusivo com acesso somente à base da aplicação e mantenha as credenciais fora de Git, HTML e logs.

## 2. Preparar o artefato

Na máquina de entrega, gere o artefato rastreado com `vendor/` do Composer e `public/assets/vendor/mediapipe-tasks-vision-1.0.1/` completo, incluindo manifesto, modelo, WASM, bundle e licença. Os assets são pré-compilados: exclua `node_modules`, caches npm/pnpm e qualquer package-manager cache. Não execute builds npm/pnpm no servidor e não instale processo permanente, Redis, Docker ou WebSocket. O runtime JavaScript local do yt-dlp é uma dependência separada e necessária quando a importação do YouTube está habilitada.

Depois de enviar o código, execute por SSH a partir da raiz:

    composer install --no-dev --optimize-autoloader

Preserve o `.env` de produção, logs e mídia privada fora do pacote. O `node tools/vendor-mediapipe.mjs verify` é um gate offline da máquina de entrega; o código PHP não requer Node, mas o yt-dlp requer o runtime configurado para importar do YouTube. Execute o checker somente depois das migrations, conforme a ordem da seção 14.

## 3. Definir o document root

Prefira configurar o domínio/subdomínio para apontar diretamente para a pasta public. Assim, app, bootstrap, config, database, storage e .env ficam fora do acesso web.

Se o plano só permitir apontar para a raiz do projeto, envie o .htaccess raiz versionado com esta regra exata. Ela bloqueia caminhos privados antes de encaminhar rotas ao front controller; assets/ e outros arquivos que existam em public/ são servidos internamente de public/.

    Options -Indexes
    RewriteEngine On
    RewriteRule ^(?:app|bootstrap|config|database|storage|tests|vendor)(?:/|$) - [F,L,NC]
    RewriteRule ^(?:\.env(?:\..*)?|composer\.(?:json|lock)|phpunit\.xml(?:\.dist)?|\.git(?:/|$)) - [F,L,NC]
    RewriteRule ^public/ - [L,NC]
    RewriteRule ^assets/(.*)$ public/assets/$1 [L,NC]
    RewriteCond %{DOCUMENT_ROOT}/public/$1 -f [OR]
    RewriteCond %{DOCUMENT_ROOT}/public/$1 -d
    RewriteRule ^(.+)$ public/$1 [L]
    RewriteRule ^ public/index.php [QSA,L]

Verifique com o navegador que /.env, /app/, /storage/ e /database/ retornam acesso negado ou inexistente. Não use esse fallback quando puder definir public como document root.

Tanto o `.htaccess` raiz quanto `public/.htaccess` devem conter `AddType application/javascript .mjs`, `AddType application/wasm .wasm` e `AddType application/octet-stream .tflite`.

Preserve também a política de segurança da resposta de `reframe-worker.js` nos dois arquivos: `default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'`. A permissão de compilação WASM é restrita ao worker e foi necessária no teste com Chrome/MediaPipe; a política da página permanece separada. O módulo Apache `headers` deve estar habilitado. Após publicar, confira esse header no script do worker.

### Biblioteca de clipes

A rota autenticada `/clips` usa o mesmo banco e armazenamento privado, sem serviço, processo ou migration adicional. Teste os filtros `recent`, `processing`, `completed` e `failed`, a paginação e a entrada Clipes na navegação.

Após o cron concluir um `render_clip`, a biblioteca permite acessar thumbnail e MP4 imediatamente. Cards carregados em processamento se atualizam pelo polling existente; atualizar a página sincroniza novos itens e a composição dos filtros. Nenhum download é iniciado sem ação do usuário. Não exponha arquivos de mídia diretamente: as rotas `/clips/{id}/thumbnail` e `/clips/{id}/download` continuam verificando a conta e a análise atual. Confirme que uma segunda conta não visualiza nem baixa os clipes da primeira.

## 4. Criar a configuração

Crie .env na raiz a partir de .env.example e preencha valores reais somente no servidor:

Em produção, `APP_ENV_FILE` normalmente deve ficar ausente. Assim o bootstrap carrega somente o `.env` da raiz. Valor vazio desabilita arquivo de ambiente; path explícito não vazio carrega somente aquele arquivo, sem fallback adicional.

    APP_NAME=ClipForge
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://seu-dominio.example
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=nome_da_base
    DB_USERNAME=usuario_da_base
    DB_PASSWORD=defina_uma_senha_forte
    MAIL_TRANSPORT=smtp
    MAIL_FROM_ADDRESS=no-reply@seu-dominio.example
    MAIL_FROM_NAME=ClipForge
    MAIL_SMTP_HOST=smtp.hostinger.com
    MAIL_SMTP_PORT=587
    MAIL_SMTP_ENCRYPTION=tls
    MAIL_SMTP_USERNAME=no-reply@seu-dominio.example
    MAIL_SMTP_PASSWORD=senha-da-caixa-postal
    MAIL_SMTP_TIMEOUT=10
    GEMINI_API_KEY=chave-exclusiva-do-ambiente
    GEMINI_MODEL=modelo-habilitado-na-sua-conta
    GEMINI_HTTP_TIMEOUT_SECONDS=180
    GEMINI_RESPONSE_LIMIT_BYTES=1048576
    GEMINI_FILE_POLL_SECONDS=15
    GEMINI_CREDITS_PER_MINUTE=1
    YOUTUBE_IMPORT_ENABLED=true
    YTDLP_TIMEOUT_SECONDS=60
    MEDIA_DOWNLOAD_TIMEOUT_SECONDS=120
    FFPROBE_BINARY=/usr/bin/ffprobe
    FFMPEG_BINARY=/usr/bin/ffmpeg
    PROCESS_TIMEOUT_SECONDS=60
    PROCESS_OUTPUT_LIMIT_BYTES=1048576
    RENDER_TIMEOUT_SECONDS=240
    RENDER_MAX_OUTPUT_BYTES=524288000
    RENDER_MAX_DURATION_SECONDS=180
    RENDER_THUMBNAIL_MAX_BYTES=10485760
    REFRAME_MAX_DURATION_SECONDS=90
    REFRAME_MAX_KEYFRAMES=32
    REFRAME_PREVIEW_MAX_FRAMES=180
    REFRAME_PREVIEW_MAX_EDGE=320
    MEDIAPIPE_ASSET_VERSION=1.0.1
    QUEUE_LEASE_SECONDS=330

Esse exemplo habilita a importação do YouTube e usa o lease mínimo de 330 segundos calculado na seção 11. A habilitação pressupõe yt-dlp e seu runtime JavaScript configurados e homologados no worker; declarar as variáveis não instala nem verifica essas dependências. Recalcule o lease se alterar qualquer timeout.

Mantenha APP_DEBUG=false em produção. Com esse valor, falhas inesperadas mostram apenas uma página amigável e um código de referência; contexto técnico sanitizado é gravado em storage/logs. Crie uma chave Gemini exclusiva para produção, restrinja-a à API necessária quando o console permitir e revogue-a imediatamente se houver suspeita de exposição. Nunca coloque a chave em Git, JavaScript, HTML, URL, captura de tela ou log.

## 5. Aplicar schema com o fluxo correto

Para uma **base nova**, depois de confirmar backup e `.env`, execute via SSH ou terminal do hPanel:

    php bin/migrate.php

Esse comando também executa o seeder de planos. Portanto, não é o caminho de upgrade para uma base existente: reaplicar o seed pode alterar o catálogo operacional.

Para **upgrade de base existente**, consulte `docs/PLATAFORMA_OPERACAO.md` e use o atualizador aditivo, nunca uma rota HTTP:

    php tools/activate-platform.php
    php tools/activate-platform.php --apply
    php tools/activate-platform.php

Revise o preflight antes de `--apply` e confirme `pending: []` depois. O atualizador inclui a migração 023 de projeção de notificações e recusa estado parcial; ele preserva os planos existentes e cria backup privado antes do DDL. Não publique esse backup nem o inclua no artefato.

## 6. HTTPS, sessão e permissões

Ative o certificado SSL da Hostinger e force redirecionamento HTTPS no hPanel. Confirme que APP_URL usa https://. A conta do servidor deve conseguir criar e gravar em storage/logs e, quando usado, storage/cache; use permissões mínimas necessárias, normalmente diretórios 775 e arquivos 664 conforme o usuário/grupo do PHP. Verifique também que o diretório temporário/sessões configurado pelo PHP é gravável pela conta.

## 7. E-mail SMTP e worker de fila

Configure o SMTP autenticado pela configuração administrativa de e-mail; o segredo fica cifrado no banco com a `APP_ENCRYPTION_KEY` preservada no `.env` privado. Não exponha senha SMTP, tokens, chaves de API ou conteúdo de e-mail em logs, argumentos de shell ou formulários públicos.

Os fluxos automáticos de conta e campanhas enviam por fila. O teste SMTP manual do administrador é separado. Em base isolada de QA, com transporte SMTP configurado e credenciais de teste autorizadas, a execução manual finita é:

    php bin/process-email.php --limit=1

O limite aceito é 1..10. Sem SMTP válido, o comando falha fechado e não usa fallback silencioso. `bin/process-campaigns.php` somente enfileira campanhas; ele não entrega e-mail. Não ative cron para esses comandos até validar uma conta QA, opt-in e destinatário de teste. Quando houver homologação, use invocações CLI finitas e separadas; este guia não ativa cron nem autoriza envio real.

O worker consome a próxima mensagem da fila global, não filtra pela conta QA. Um limite de 1 não evita o envio a um destinatário real já enfileirado; use ambiente isolado ou revise a fila e sua autorização antes do teste.

Consulte `docs/PLATAFORMA_OPERACAO.md` para a sequência completa, incluindo HTTPS/`APP_URL`, projeção de mídia e sandbox de gateways.

## 8. Validação pós-publicação

1. Acesse o domínio em HTTPS e confirme que a home, login e cadastro carregam.
2. Envie um formulário com CSRF inválido e confirme a página de sessão expirada.
3. Acesse uma rota inexistente e confirme a página 404.
4. Execute php bin/check-requirements.php, resolva toda linha [FALHA] e revise os [WARN]. Ausência de chave/modelo Gemini é um aviso operacional, não quebra o site.
5. Confirme no painel que o banco recebeu a tabela migrations e as tabelas de aplicação.
6. Autentique-se, crie um projeto de teste, acompanhe `/projetos` e confirme que `/projetos/{id}` só abre para o proprietário.
7. Abra a prévia manual sem consentimento e confirme que somente a source privada é solicitada; valide também o modo manual sem JavaScript e o fallback quando o navegador não suporta o codec.
8. Leia o texto de consentimento, conceda de forma afirmativa e confirme que o MediaPipe só então roda no Web Worker. Faces, frames, detecções e métricas permanecem no processamento no dispositivo; o servidor recebe somente keyframes canônicos.

## 9. Rollback

Antes de cada publicação, faça backup da base MySQL e arquive o diretório da versão estável, incluindo vendor/ e .env em local seguro fora da área pública. Em caso de falha:

1. Pare o cron da Hostinger e qualquer worker VPS antes de reverter código.
2. Coloque a aplicação em manutenção pelo mecanismo do hPanel, se disponível.
3. Restaure o diretório da última versão estável e o .env correspondente.
4. Restaure o dump do banco somente se a mudança de schema/dados exigir.
5. Reexecute php bin/check-requirements.php, valide HTTPS e faça smoke test das rotas.
6. Reabra o acesso e só então reative o cron.

Migrations desta fase são somente de avanço; não há rollback automático de schema. Nunca apague `clip_render_profiles`, keyframes, mídia privada, reservas de limpeza ou o ledger de créditos para acomodar código antigo. Planeje a reversão do banco antes da migration e preserve snapshots compatíveis.

## 10. Mídia privada e quota

Sempre que o plano permitir, mantenha a raiz do projeto e `MEDIA_PRIVATE_ROOT` fora de `public_html`; somente `public/` deve ser o document root. A conta PHP/CLI precisa gravar na raiz privada com permissões mínimas (normalmente diretórios 775 e arquivos 664, conforme usuário/grupo do plano). Antes do deploy e periodicamente, confira a quota livre no hPanel: uploads, arquivos `.part` e mídia processada consomem disco e nunca devem ser movidos para uma URL pública.

Configure estas variáveis sem incluir chaves, credenciais ou URLs privadas no código:

    MEDIA_DISK=local
    MEDIA_PRIVATE_ROOT=/home/conta/clipforge-private/media
    MEDIA_MAX_UPLOAD_BYTES=524288000
    MEDIA_DOWNLOAD_TIMEOUT_SECONDS=120
    MEDIA_MAX_REDIRECTS=2
    YOUTUBE_IMPORT_ENABLED=true
    YTDLP_TIMEOUT_SECONDS=60
    GEMINI_HTTP_TIMEOUT_SECONDS=180
    FFPROBE_BINARY=/usr/bin/ffprobe
    FFMPEG_BINARY=/usr/bin/ffmpeg
    PROCESS_TIMEOUT_SECONDS=60
    PROCESS_OUTPUT_LIMIT_BYTES=1048576
    RENDER_TIMEOUT_SECONDS=240
    RENDER_MAX_OUTPUT_BYTES=524288000
    RENDER_MAX_DURATION_SECONDS=180
    RENDER_THUMBNAIL_MAX_BYTES=10485760
    QUEUE_LEASE_SECONDS=330
    QUEUE_MAX_ATTEMPTS=3
    QUEUE_BATCH_SIZE=1

Confirme os valores efetivos de `upload_max_filesize` e `post_max_size` no PHP usado pelo site **e pelo cron**. `post_max_size` precisa ser maior que `upload_max_filesize`, e ambos precisam comportar o limite da aplicação; limites menores do provedor prevalecem. Web e CLI podem carregar arquivos php.ini diferentes: alinhe os limites e `MEDIA_MAX_UPLOAD_BYTES` nos dois ambientes. Upload e importação por URL respeitam o menor limite global/PHP/plano. Planeje quota para a origem, temporários simultâneos, um MP4 de até `RENDER_MAX_OUTPUT_BYTES` e uma thumbnail de até `RENDER_THUMBNAIL_MAX_BYTES` por renderização em curso. Os limites são barreiras por artefato, não reserva automática de espaço.

## 11. Cron do processamento

Agende a cada minuto, substituindo o caminho pelo caminho absoluto real do projeto:

    php /absolute/project/bin/process-jobs.php --queue=media --limit=1 --time-budget=50

O mesmo comando atende `probe_source`, `fetch_and_probe`, `analyze_video`, `generate_clips`, `generate_subtitles` e `render_clip`; não crie uma rota HTTP ou um comando de shell separado para FFmpeg.

O comando é finito e seguro contra crons sobrepostos por lease no MySQL. A saída JSON informa quantos jobs foram reivindicados, concluídos, tentados novamente, adiados ou falharam nos campos `claimed`, `completed`, `retried`, `deferred` e `failed`; `operational_errors` sinaliza falhas ao persistir transições. Não exponha esse comando como rota web.

O diagnóstico `check-requirements.php` somente inspeciona `proc_open`, os executáveis configurados/PATH, storage, temporário e lease; ele não executa FFprobe nem FFmpeg e não imprime caminhos configurados. FFmpeg ausente gera `WARN`, pois um VPS capaz pode reivindicar o job; lease abaixo da margem exigida gera `FALHA` e deve ser corrigido antes de ativar qualquer worker. O gate `check-production.php` da seção 15 executa probes adicionais e tem um escopo diferente.

Checker e worker calculam o mínimo com `WorkerLeaseBudget::requiredSeconds`. Para o pipeline atual, com legendas, `QUEUE_LEASE_SECONDS` precisa ser pelo menos:

```text
max(
  RENDER_TIMEOUT_SECONDS,
  RESOLUCAO + MEDIA_DOWNLOAD_TIMEOUT_SECONDS + JUNCAO + PROCESS_TIMEOUT_SECONDS,
  PROCESS_TIMEOUT_SECONDS + GEMINI_HTTP_TIMEOUT_SECONDS
) + 30
```

Com `YOUTUBE_IMPORT_ENABLED=true`, `RESOLUCAO` é `YTDLP_TIMEOUT_SECONDS` e `JUNCAO` é `PROCESS_TIMEOUT_SECONDS`, orçamento da junção dos tracks adaptativos. Com YouTube desabilitado, ambos são zero. O último `PROCESS_TIMEOUT_SECONDS` da segunda linha cobre a inspeção da origem. Resolução, download, junção e inspeção são sequenciais; extração de áudio e transcrição também. A margem adicional é de 30 segundos.

Os exemplos deste guia usam YouTube habilitado, resolução de 60 s, download de 120 s, processo/junção/inspeção de 60 s, render de 240 s e Gemini de 180 s. Portanto, `max(240, 60 + 120 + 60 + 60, 60 + 180) + 30 = 330` segundos. Com os mesmos timeouts e YouTube desabilitado, o mínimo é 270 segundos. **330 não é um mínimo universal:** recalcule com os valores efetivos de cada host; margem insuficiente impede iniciar o worker.

O `--time-budget=50` limita o início de novas reivindicações, mas não interrompe um job já reivindicado nem limita seu consumo de CPU a 50 segundos. `--limit=1` vale por execução: crons sobrepostos podem processar jobs distintos simultaneamente. O lease protege a posse do job, não limita a concorrência ou a carga total. Dimensione o agendamento, a concorrência e CPU/memória do worker compatível antes de ativar o cron; o lease deve continuar cobrindo o orçamento completo. Esperas normais do arquivo remoto usam `deferred`, não consomem tentativa e são retomadas pelo cron seguinte.

Se PHP CLI não estiver disponível no plano, o cron não inicia: a aplicação web continua funcionando e o job permanece no estado anterior até ser reivindicado por um worker compatível. Quando PHP CLI funciona, mas `proc_open`, FFprobe ou FFmpeg estão indisponíveis, essa ausência é uma condição de capacidade, não uma falha do vídeo: o job é adiado sem consumir `attempts` e pode ser retomado por uma instância capaz. Não habilite execução por shell nem relaxe validações.

Um worker VPS não é somente uma cópia de `bin/process-jobs.php`: publique o mesmo artefato PHP completo (`app/`, `bootstrap/`, `config/`, `vendor/` e código versionado), forneça conexão TLS/restrita ao mesmo MySQL e acesso ao mesmo namespace de mídia privada. Web e todos os workers precisam resolver cada `object_key` para os mesmos bytes; um disco local independente por máquina não atende esse contrato. Se o disco da Hostinger não puder ser montado de forma segura, use storage privado compartilhado; nunca copie a mídia para uma URL pública. Mantenha o site PHP/MySQL como plano de controle e execute no VPS o mesmo comando finito de cron. Para storage de objetos, implemente `S3PrivateStorage` e forneça ao worker credenciais mínimas gerenciadas no servidor.

Se a hospedagem compartilhada não oferecer FFmpeg/proc_open, mantenha a web na Hostinger e mova somente o comando finito para um VPS futuro. O VPS deve usar o mesmo banco e storage privado compartilhado; nunca mantenha uma fila paralela ou cópia pública da mídia.

## 12. Rotas e artefatos privados

MP4 e thumbnail permanecem fora de `public_html`. O navegador acessa somente `GET /clips/{id}/thumbnail` e `GET /clips/{id}/download`; ambas as rotas exigem sessão e verificam `clip.id` junto ao proprietário do projeto. Não crie alias, symlink, regra do servidor web ou URL direta para `processed/` e `thumbnails/`. `GET /api/clips/{id}/status` publica apenas estado e URLs internas quando o corte está concluído.

Reservas pendentes em `render_artifact_cleanups` e `source_artifact_cleanups` protegem a limpeza após queda, perda de lease ou falha terminal. Monitore o crescimento das tabelas e a quota, mas não apague linhas/objetos manualmente: toda execução finita do worker, inclusive sem job elegível, drena somente reservas vencidas e evita remover artefatos já referenciados. A mesma manutenção remove apenas temporários internos reconhecidos; arquivos ativos e nomes não relacionados permanecem intactos. Fontes são reservadas antes da gravação e publicadas junto da liberação da reserva em uma transação; uma falha de exclusão conserva a obrigação para uma próxima execução.

## 13. Gemini, privacidade e créditos

O vídeo original é enviado ao provedor Gemini para análise. Informe isso claramente na política de privacidade e nos termos do produto. A Files API mantém arquivos temporariamente conforme a política vigente do provedor; o worker também tenta removê-los após obter um resultado ou encerrar a análise. Essa remoção é uma limpeza adicional e não substitui a política de retenção do provedor.

O Smart Reframe automático é separado: após consentimento ativo `mediapipe_metrics` versão `2026-09-04`, MediaPipe processa frames no dispositivo. Não envie imagem facial, detecção bruta, embedding ou identidade ao servidor. Revogação impede novas análises automáticas; centralizado e manual continuam disponíveis sem JavaScript, consentimento ou codec de prévia. Limites operacionais: duração 1..180 s, `REFRAME_MAX_KEYFRAMES` exatamente 32, prévia 2..180 frames e edge 64..320 px.

Cada análise reserva créditos de forma transacional antes do primeiro job. Sugestões válidas consomem a reserva uma única vez; falha terminal coberta pelo pipeline reembolsa a reserva antes de marcar o projeto como falho. Não altere manualmente `users.credits`, `credit_reservations` ou `credit_transactions`: o ledger é a trilha de auditoria e precisa ser preservado em deploy e rollback.

A ativação automática vale para novos projetos processados após esta versão. Projetos legados já parados em `ready` não são debitados retroativamente; uma futura ação explícita de reanálise deverá criar sua própria reserva idempotente.

Novos projetos oferecem a opção de exportar automaticamente até três melhores cortes após a análise. A opção fica registrada no projeto; projetos antigos não são alterados retroativamente. O usuário também pode exportar e editar versões manualmente. Não prometa conclusão antes do estado real `completed`.

## 14. Ordem de deploy da Fase 5

1. Pare o cron e faça backup do banco e da mídia privada.
2. Envie o código, `vendor/` e assets MediaPipe versionados, preservando `.env` e mídia fora de `public_html` e excluindo `node_modules`/caches npm/pnpm.
3. Execute `composer install --no-dev --optimize-autoloader`.
4. Execute `php bin/migrate.php` e confirme todas as migrations distribuídas: preserve as bases de reenquadramento/consentimento `202609040004`, `202609040005`, `202609040006` e aplique as novas migrations, incluindo `202609060051_create_publication_preparations.sql`, última desta revisão. Não pare em `202609060030`; confira o conjunto completo do pacote.
5. Execute `php bin/check-requirements.php`; resolva `FALHA`, confira chave/modelo sem imprimir seus valores e encaminhe os `WARN` de capacidade para um VPS quando necessário.
6. Reative o cron de um minuto.
7. Faça smoke test de login, source-preview privada, modos center/manual/auto com consentimento, status, thumbnail/download privados e uma execução manual do worker.

No rollback, pare o cron da Hostinger e todos os workers VPS antes de reverter código ou banco para impedir uso de schema incompatível. Preserve toda a mídia privada, `render_artifact_cleanups`, `credit_reservations`, `credit_transactions` e arquivos de backup; não apague reservas, objetos nem lançamentos apenas porque uma versão de código foi revertida. Se o rollback restaurar o banco, restaure também um snapshot compatível da mídia ou mantenha os objetos até concluir a reconciliação.

## 15. Release e verificação de produção — conclusão do SaaS

Este complemento descreve os comandos da versão de 2026-09-06 e prevalece sobre as verificações parciais das seções anteriores. `check-requirements.php` continua útil para diagnóstico, mas seus avisos não certificam uma implantação completa. Todos os comandos abaixo são CLI; não crie uma rota web para executá-los.

### Primeiro administrador e configuração criptografada

Após configurar o banco e aplicar as migrations, execute `php bin/create-admin.php --email=operador@dominio.example --name=Operador` com a identidade real do operador. O comando recebe a senha pela entrada padrão, nunca por argumento. Em terminal Unix interativo, a senha não tem eco; no Windows, use entrada padrão protegida conforme a mensagem do comando. Para promover deliberadamente uma conta existente, use `--email=conta@dominio.example --promote`. Nenhuma conta comum é promovida automaticamente e não existe senha administrativa padrão.

Configure `APP_ENCRYPTION_KEY` com 32 bytes aleatórios codificados em Base64, gerados por um gerenciador de segredos ou fonte criptográfica. Mantenha a mesma chave estável na web e no worker e no backup privado. Ela protege por AES-256-GCM a chave Gemini salva em `/admin/configuracoes/gemini`; não substitui `GEMINI_API_KEY`. Não é possível recuperar um override cifrado após perder essa chave. Troca de chave-mestra exige procedimento controlado de recifragem ou novo cadastro do segredo, não simples alteração do ambiente.

Sem override administrativo, a aplicação usa `GEMINI_API_KEY` e `GEMINI_MODEL` do ambiente. O formulário nunca devolve o segredo, e o teste de conexão é limitado por administrador/origem. Não inclua chaves reais em Git, ZIP, prints ou chamados de suporte. A conta comum pode consultar `/conta/plano` e `/conta/creditos`, mas não mudar saldo ou ativar plano pago.

### Artefato sem dados de produção

Em uma área de preparação limpa da máquina de entrega, instale as dependências de produção com `composer install --no-dev --optimize-autoloader` e verifique os assets locais. Em seguida, gere um ZIP novo, em um diretório já existente fora da origem, de `public`/`public_html` e de `storage`:

```sh
php bin/build-release.php --output=/caminho-privado-de-entrega/clipforge-20260906.zip
```

O gerador recusa sobrescrita e caminhos de saída com `.` ou `..`. Inclui código, dependências de execução, assets, migrations, seeds e licenças por allowlist; não inclui `.env`, armazenamento, logs, testes, fixtures, caches ou links simbólicos. Não use cópias de produção como área de preparação. A filtragem de nomes e extensões não substitui revisar dependências e conteúdo antes de distribuir: um segredo embutido em código PHP legítimo ainda precisa ser removido pelo responsável.

`release-manifest.json` contém SHA-256 dos bytes de cada arquivo incluído. A saída JSON informa `files` e `manifest_sha256`; esse último é o hash do manifesto, não do ZIP. Confira o manifesto e as entradas do arquivo antes de enviar. A ordem das entradas é estável, mas não se promete ZIP idêntico byte a byte entre ambientes. Preserve configuração e dados existentes ao implantar. O gerador não aplica migrations, não publica nem altera a base.

O PHP da máquina que gera o pacote precisa da extensão Zip. Node continua restrito à preparação e aos testes locais; o artefato não exige Node no servidor.

### Escolha o papel real de cada host

| Papel | Verificações exigidas | O que não comprova sozinho |
| --- | --- | --- |
| `web` | PHP CLI/extensões, conexão e versão do MySQL/MariaDB, migrations e colunas utilizadas, fila, leitura/escrita/rename em mídia privada, HTTPS/headers/rotas | Existência de worker externo capaz de processar os mesmos arquivos |
| `worker` | Base comum, `proc_open`, FFmpeg/FFprobe executados, H.264/AAC, legenda ASS visível, extração WAV, margem do lease e chamada Gemini | Disponibilidade do site e suas rotas públicas |
| `all-in-one` | União dos dois conjuntos no mesmo host | Agendamento real do cron, entrega de e-mail, recuperação de backup e fluxo completo de usuário |

Além dos gates acima, confirme no POST autenticado do host web o preflight FFprobe de precisão de EOF. O checker do papel web não certifica automaticamente esse novo fluxo. Web/Cloud sem a capacidade necessária não atende à criação de novas exportações nesta configuração; use VPS compatível também para o web, sem contornar restrições do plano.

O probe de mídia do checker cobre um exemplo sintético H.264/AAC, legenda ASS via libass, decodificação e extração WAV. Ele **não verifica automaticamente** yt-dlp, seu runtime JavaScript, a importação real do YouTube, `drawtext`, a disponibilidade/aparência de todas as fontes nem os templates e ajustes atuais do estúdio de capas. No host final, confirme `YTDLP_BINARY` e `YTDLP_JS_RUNTIME` e execute uma importação autorizada; renderize e confira capas com os templates/fontes utilizados, incluindo SPLIT, além do fluxo real de legendas e exportações. Um gate verde não substitui essas verificações, nem comprova acesso aos mesmos bytes quando web e worker estão separados.

Depois do backup, publicação do código e aplicação de **todas** as migrations entregues, rode os comandos adequados ao ambiente. Os domínios e caminhos dos exemplos são ilustrativos, não configuração pronta:

```sh
# No host web; verifica a origem HTTPS definida em APP_URL.
php bin/check-production.php --role=web --verify-http

# No host de processamento; chamada externa sintética expressamente habilitada.
php bin/check-production.php --role=worker --verify-gemini

# Somente se o mesmo host realmente executar web e processamento.
php bin/check-production.php --role=all-in-one --verify-http --verify-gemini

# Smoke HTTP independente, sem autenticar nem enviar mídia.
php bin/smoke-http.php --base-url=https://seu-dominio.example
```

`--verify-http` autoriza requisições GET limitadas à origem de `APP_URL`, sem cookies ou credenciais e sem seguir redirecionamentos. O teste exige HTTPS válido, páginas públicas acessíveis, áreas autenticadas redirecionadas para login e caminhos internos bloqueados. Verifica CSP, HSTS, `nosniff`, política de referenciador, proteção de frames e permissões de câmera/microfone/geolocalização. Configure os headers no host público; não desative verificação TLS nem reduza os critérios para obter um resultado verde.

`--verify-gemini` autoriza uma chamada real de texto sintético ao modelo efetivo (incluindo configuração administrativa válida), sem enviar vídeo do usuário. Ela pode consumir quota/custo do provedor e gerar registros do serviço externo. Use somente com autorização do operador e a chave do ambiente correto. O comando não imprime a chave ou o corpo da resposta. Não coloque segredo nos argumentos do shell. Esse teste não substitui a homologação do envio de vídeo, da transcrição ou de um modelo específico em uso real.

Sem essas flags, a verificação não faz os respectivos acessos externos e os requisitos `https`/`gemini` permanecem não verificados nos papéis que os exigem. Não interprete essa falha como indisponibilidade confirmada do provedor. Os probes locais ainda conectam ao banco em modo de leitura e criam temporários privados para testar gravação, renderização e extração de áudio; execute-os com usuário e permissões equivalentes aos do serviço. Não há aplicação de migration, concessão de créditos ou envio de mídia de conta pelos probes.

Saída `ready: true` significa somente **capacidades do host verificadas** (`scope: host_capabilities`). `end_to_end_verified` permanece `false`; os itens `unverified` precisam de homologação separada. `missing` enumera os requisitos não confirmados sem expor caminhos ou credenciais. Os três comandos retornam `0` em sucesso, `1` em falha operacional/gate e `2` para uso inválido. Guarde evidência sanitizada por host, data e versão; não publique os relatórios técnicos em diretórios acessíveis pela web.

### Limites concretos da Hostinger

Na documentação oficial consultada em 2026-09-06, SSH está disponível em Premium Web e superiores, mas não em Single Web. A disponibilidade deve ser confirmada no plano e no hPanel reais; ter acesso SSH não significa ter acesso root ou todos os binários necessários. [Conexão SSH na Hostinger](https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/).

A Hostinger informa suporte a FFmpeg em VPS, não em Web/Cloud. Portanto, uma instalação somente compartilhada não deve ser declarada capaz de renderizar: mantenha ali apenas o papel `web` e prepare um worker compatível, ou use um host único que passe o gate `all-in-one`. [Suporte oficial a mídia e FFmpeg](https://www.hostinger.com/support/which-media-compression-and-web-applications-are-supported-at-hostinger/).

O worker separado exige o mesmo banco e acesso privado aos **mesmos bytes** de cada origem e exportação. Dois diretórios locais independentes não atendem esse contrato. O adapter S3 citado anteriormente é uma alternativa de implementação futura, não uma capacidade já pronta deste pacote. Antes de anunciar processamento disponível, resolva o compartilhamento privado, as permissões, os limites de recurso e o cron finito. Não exponha mídia por URL pública, não contorne restrições do provedor e não instale um processo permanente na hospedagem compartilhada.

### Homologação que ainda é obrigatória

Após os gates por host, valide login e recuperação por e-mail real, criação de projeto com mídia autorizada, análise, legendas, exportação, preservação do original em nova versão e download privado. Teste outra conta contra os mesmos IDs e confirme o bloqueio. Verifique o cron real, a quota, falhas e recuperação; ensaie backup e restauração compatível antes de abrir o serviço ao público. A opção de exportação automática de novos projetos pode gerar até três cortes; projetos antigos mantêm a opção anterior e não devem receber consumo retroativo.

As páginas públicas `/privacidade` e `/termos` são explicações operacionais, não aprovação jurídica. **Antes do lançamento**, o operador precisa informar sua identidade, domínio, canal de atendimento, procedimentos de acesso/exclusão, política efetiva de retenção e backups e condições comerciais. Não há prazo automático de exclusão de projetos nesta versão. Revise a modalidade da conta Gemini e seu tratamento de dados; não presuma ausência de uso para melhoria de produtos. [Termos oficiais da API Gemini](https://ai.google.dev/gemini-api/terms).

Confirme que o aviso de envio de vídeo e áudio ao Gemini está visível antes do envio e que a autorização do MediaPipe continua separada, afirmativa e revogável. Não apresente planos administrados como checkout ou cobrança já implementada. Registre essas pendências de operação junto da homologação: um ZIP válido e um checker verde não resolvem identidade do operador, revisão jurídica ou acesso à Hostinger.
