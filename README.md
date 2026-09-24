# ClipLab

Plataforma SaaS em PHP 8/MySQL para ingestão e processamento assíncrono de vídeos, sem Node.js, Docker, Redis ou WebSocket em produção. O site pode usar hospedagem PHP; o processamento precisa de FFmpeg/FFprobe e jobs CLI finitos. Na Hostinger, FFmpeg exige VPS: um plano Web/Cloud sozinho não executa a geração completa dos vídeos.

## Funcionalidades

A aplicação recebe MP4, MOV e WEBM por upload ou URL HTTPS direta, mantém a origem fora de `public/`, usa FFprobe e Gemini para sugerir cortes e permite aprovar um intervalo para renderização assíncrona por FFmpeg. O Smart Reframe acrescenta proporções original, 9:16, 1:1, 16:9 e 4:5 nos modos original, centralizado, manual ou automático. O automático usa MediaPipe 1.0.1 self-hosted em Web Worker somente após consentimento; frames e métricas faciais ficam no processamento no dispositivo e não são enviados ao servidor.

O modo manual permanece disponível sem JavaScript, sem MediaPipe e quando o codec de prévia não é suportado. O reenquadramento aceita duração de 1..180 segundos, trajetória automática de no máximo 32 keyframes, prévia de 2..180 frames e maior lado de 64..320 px.

Status, thumbnail e download passam por rotas autenticadas com verificação de propriedade. O score continua sendo uma estimativa da análise de IA e não garante viralização.

A biblioteca em `/clips` reúne os cortes da análise atual de todos os projetos da conta, com filtros Recentes, Em processamento, Concluídos e Falhas e páginas de 24 itens. Um render concluído disponibiliza automaticamente seu MP4 e thumbnail nessa biblioteca, sem nova publicação, cópia de mídia ou consumo de créditos. Nos cards em processamento já carregados, o status e os links aparecem por atualização automática; use Atualizar para sincronizar a lista ou os filtros. A navegação e os downloads continuam disponíveis sem JavaScript.

Novos projetos podem solicitar exportação automática dos três melhores cortes, sem uma segunda cobrança de análise. Projetos anteriores mantêm sua escolha. O editor em `/clips/{id}/editar` cria uma versão independente: intervalo, proporção, enquadramento, título, marca e seis estilos de legendas. Há transcrição de áudio com Gemini ou revisão/importação manual de SRT. O MP4 original é preservado. A exportação e a transcrição continuam na fila quando a aba é fechada.

As páginas `/conta/plano` e `/conta/creditos` mostram limites reais e extrato paginado. Uploads, minutos mensais e armazenamento são conferidos no servidor sob transação. O painel `/admin` oferece gestão de usuários, planos, créditos, projetos, jobs, erros e configuração Gemini criptografada. Não há checkout nem autoatribuição de planos pagos; faturamento automático não faz parte desta versão.

As páginas públicas `/privacidade` e `/termos` explicam o funcionamento. Antes do lançamento, o operador deve completar sua identificação, contato e políticas aplicáveis. Consulte `docs/DELIVERY.md` para evidências e bloqueios reais de produção; testes locais não equivalem à homologação da Hostinger.

## Requisitos

- PHP 8.0+ CLI e web com `pdo`, `pdo_mysql`, `mbstring`, `fileinfo`, `curl` e `openssl`; `zip` na máquina que monta o pacote;
- MySQL 8.0.16+ ou MariaDB 10.4+ com CHECK ativo;
- Composer na preparação do artefato;
- FFprobe, FFmpeg com H.264/AAC/libass, fontes legíveis e `proc_open` no worker; banco e armazenamento privado precisam ser os mesmos usados pelo site;
- chave e modelo Gemini configurados no servidor para executar análises reais.

## Início local

1. Copie `.env.example` para `.env` e ajuste banco e diretório privado.
2. Instale dependências: `composer install`.
3. Instale FFprobe e FFmpeg e defina `FFPROBE_BINARY`/`FFMPEG_BINARY` como nomes no PATH ou caminhos absolutos controlados pelo operador.
4. Execute `php bin/check-requirements.php`.
5. Execute `php bin/migrate.php`.
6. Inicie o site: `php -S 127.0.0.1:8088 -t public public/index.php`.
7. Abra `http://127.0.0.1:8088/projetos/novo` após autenticar.

## Processamento

Uma execução finita do worker:

    php bin/process-jobs.php --queue=media --limit=1 --time-budget=50

Em desenvolvimento, repita o comando para consumir a fila. No servidor worker, agende-o uma vez por minuto. O mesmo worker executa `probe_source`, `fetch_and_probe`, `analyze_video`, `generate_clips`, `generate_subtitles` e `render_clip`; também recupera artefatos e temporários vencidos, mesmo sem job elegível. Hostinger e worker precisam enxergar o mesmo MySQL e o mesmo namespace de mídia privada; discos independentes não atendem esse contrato. Esperas normais usam `deferred`; falhas transitórias usam tentativas limitadas e backoff.

O lease mínimo é `max(GEMINI_HTTP_TIMEOUT_SECONDS + PROCESS_TIMEOUT_SECONDS, RENDER_TIMEOUT_SECONDS, MEDIA_DOWNLOAD_TIMEOUT_SECONDS + PROCESS_TIMEOUT_SECONDS) + 30`. O limite `--time-budget` impede iniciar novos jobs após o orçamento; uma operação já iniciada pode durar até seus timeouts. Configure o cron do host para permitir esse tempo.

As análises reservam créditos antes da primeira chamada, consomem uma vez quando as sugestões são persistidas e reembolsam falhas terminais previstas. Chave, URI remota, JSON validado e IDs internos nunca fazem parte da resposta pública.

Os artefatos concluídos são acessados apenas por `GET /clips/{id}/thumbnail` e `GET /clips/{id}/download`; nunca exponha `MEDIA_PRIVATE_ROOT` como diretório web. Dimensione disco e quota considerando origem, temporários, MP4 até `RENDER_MAX_OUTPUT_BYTES` e JPEG até `RENDER_THUMBNAIL_MAX_BYTES` por renderização concorrente.

## Verificação

    php vendor/bin/phpunit tests/Unit
    php vendor/bin/phpunit --testsuite Integration --process-isolation
    php vendor/bin/phpunit tests/Feature
    node tests/Browser/project-status-concurrency.test.js public/assets/js/project-status.js
    node tests/Browser/clip-status.test.js public/assets/js/clip-status.js
    node tools/vendor-mediapipe.mjs verify
    php bin/check-requirements.php

As integrações exigem banco isolado `cliplab_phase5_test` via `TEST_DB_DSN`, `TEST_DB_USERNAME` e `TEST_DB_PASSWORD`. Nunca use o banco do site nos testes: alguns testes de migração removem e recriam tabelas. O isolamento de processos libera conexões entre casos de teste. Para FFmpeg real, defina `TEST_FFMPEG_BIN` e `TEST_FFPROBE_BIN`.

O pacote reproduzível é criado por `php bin/build-release.php --output=/caminho/fora/do/projeto/cliplab.zip`. Ele inclui manifesto SHA-256, dependências, assets, configuração de exemplo e instruções; não inclui `.env`, mídias, logs ou testes. Verifique o host com `php bin/check-production.php --role=all-in-one --verify-http --verify-gemini`. Esse comando confirma capacidades reais, não a instalação do cron ou a entrega de e-mail, que exigem homologação separada.

A configuração detalhada de produção, cron, armazenamento privado, HTTPS, SMTP, deploy e rollback está em `docs/HOSTINGER.md`. O artefato inclui `vendor/` do Composer e os assets pré-compilados em `public/assets/vendor/mediapipe-tasks-vision-1.0.1/`; exclui `node_modules`, caches npm/pnpm e package-manager caches. Node/pnpm são apenas de desenvolvimento: produção não usa npm, processo permanente, Redis, Docker ou WebSocket.

Em produção, `APP_ENV_FILE` normalmente fica ausente e o bootstrap carrega `.env`; vazio desabilita arquivo e um path explícito seleciona somente aquele arquivo. Prefira `public/` como document root e use o `.htaccess` raiz apenas como fallback. Ambos declaram MIME para `.mjs`, `.wasm` e `.tflite`. Não versione `.env`, logs, caches nem mídia privada.
