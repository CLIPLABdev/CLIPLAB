# Fase 5 — MediaPipe e Smart Reframe

## Status

Design aprovado em 2026-09-04. Esta é a Fase 5 da execução técnica do repositório e corresponde à Fase 6 do escopo original do produto.

## Objetivo

Permitir que o usuário escolha a proporção visual de um corte antes da renderização e obtenha um MP4 final reenquadrado com FFmpeg. O produto deve oferecer enquadramento central, foco manual e acompanhamento automático/dinâmico de rosto com MediaPipe no navegador, mantendo um caminho totalmente funcional quando JavaScript, WebAssembly, modelo ou codec não estiverem disponíveis.

O resultado precisa continuar compatível com PHP 8.0+, MySQL 8.0.16+ ou MariaDB 10.4+ com CHECK habilitado, cron finito, hospedagem compartilhada Hostinger e um futuro worker VPS. Node.js permanece ferramenta exclusiva de desenvolvimento e testes.

## Estado de partida

A Fase 4 já entrega:

- sugestões de cortes persistidas por análise;
- ajuste de início e fim;
- solicitação transacional e idempotente de renderização;
- fila SQL suportada com lease, retry, reclaim e fencing;
- renderer FFmpeg com argv fixo e shell desabilitado;
- MP4 e thumbnail privados;
- status assíncrono, download autorizado e cleanup durável;
- testes reais com MySQL/MariaDB suportado, FFmpeg e navegador.

O renderer atual preserva a proporção original. Não existe rota privada com byte ranges para reproduzir a origem, perfil de render, keyframe de enquadramento, MediaPipe ou editor.

## Decisão arquitetural

Será usada uma arquitetura client-assisted:

1. O servidor entrega a origem do corte por uma rota autenticada com suporte a um único byte range.
2. O formulário server-rendered oferece proporção e foco manual mesmo sem JavaScript.
3. Após ação e consentimento explícitos, MediaPipe é carregado a partir de assets versionados do próprio domínio.
4. Um Web Worker recebe frames reduzidos, detecta rostos, cria tracks efêmeros e devolve uma trajetória simplificada.
5. O navegador envia somente um plano de enquadramento normalizado e limitado.
6. O servidor valida e persiste o perfil e os keyframes na mesma transação que incrementa render_revision e despacha render_clip.
7. O worker existente carrega o snapshot da revisão e o FFmpeg aplica crop, scale e setsar.
8. A publicação, o status, o download, a perda de lease e o cleanup continuam usando o lifecycle já validado.

Detecções brutas, imagens de rosto, embeddings, nomes ou identidades nunca serão persistidos.

## Alternativas consideradas

### Perfil apenas central/manual

É a alternativa mais rápida e oferece crop funcional, mas não atende à detecção facial e ao modo dinâmico exigidos. O caminho manual será mantido como fallback, não como entrega final da fase.

### Proxy 360p antes da detecção

Um job adicional poderia gerar uma origem H.264 pequena e previsível para o navegador. Isso melhora compatibilidade com MOV, WebM e arquivos grandes, porém cria nova espera, storage, cleanup e estados antes de o usuário configurar o corte. Fica reservado como fallback futuro se a telemetria de codecs reais mostrar necessidade.

### Detecção em VPS

Produziria resultados uniformes, mas introduziria Python/C++ ou outro runtime, infraestrutura externa e transferência de vídeo. Não é compatível com a primeira implantação Hostinger e não será implementado nesta fase. Os contratos de perfil e keyframes permitem substituir o provedor no futuro.

## Escopo funcional

### Proporções

O usuário poderá escolher:

| Perfil | Saída |
|---|---:|
| Original | sem crop/scale adicional |
| 9:16 | 720 × 1280 |
| 1:1 | 720 × 720 |
| 16:9 | 1280 × 720 |
| 4:5 | 720 × 900 |

Todas as dimensões são pares e representam o output inicial. A configuração não aceitará largura, altura ou filtergraph enviados pelo cliente.

### Modos

- Original: conserva o comportamento da Fase 4.
- Centralizado: calcula o maior crop da proporção escolhida e usa o centro do frame.
- Manual: usa um foco normalizado estático escolhido pelo usuário.
- Automático: MediaPipe detecta e acompanha o rosto principal; a trajetória aprovada é persistida como keyframes e produz crop dinâmico.

O modo automático só pode ser enviado com uma trajetória válida. Em qualquer falha de compatibilidade, a interface retorna aos modos centralizado/manual sem perder o intervalo informado.

### Momento da configuração

Nesta fase, o Smart Reframe é configurado antes da primeira renderização de clips em suggested ou failed. Alterar um clip completed e manter múltiplas versões pertence à futura fase de editor.

Jobs antigos e clips sem perfil continuam significando render original. Essa compatibilidade é obrigatória.

## Modelo de dados

### clip_render_profiles

Nova tabela:

- id BIGINT UNSIGNED, chave primária;
- clip_id BIGINT UNSIGNED, FK com cascade;
- render_revision INT UNSIGNED;
- aspect_ratio ENUM original, 9:16, 1:1, 16:9, 4:5;
- reframe_mode ENUM original, center, manual, auto;
- output_width SMALLINT UNSIGNED, nullable apenas em original;
- output_height SMALLINT UNSIGNED, nullable apenas em original;
- detector_version VARCHAR(64), nullable e definido pelo servidor apenas em auto;
- created_at TIMESTAMP;
- unique clip_id + render_revision;
- índice por clip_id + created_at.

O banco deve rejeitar dimensões incoerentes, revisão zero e combinações inválidas entre original, modo e dimensões. O serviço repete as mesmas invariantes para mensagens amigáveis.

### clip_reframe_keyframes

Nova tabela:

- id BIGINT UNSIGNED, chave primária;
- render_profile_id BIGINT UNSIGNED, FK com cascade;
- sequence_index TINYINT UNSIGNED;
- at_ms INT UNSIGNED, relativo ao início final do corte;
- center_x DECIMAL(7,6) UNSIGNED;
- center_y DECIMAL(7,6) UNSIGNED;
- source ENUM manual, detected;
- created_at TIMESTAMP;
- unique render_profile_id + sequence_index;
- unique render_profile_id + at_ms.

center_x e center_y permanecem entre 0 e 1. Manual possui exatamente um keyframe em zero. Auto possui de 2 a 32 keyframes, começa em zero e termina na duração final do corte em milissegundos. Tempos são estritamente crescentes.

### user_consents

Nova tabela reutilizável:

- id BIGINT UNSIGNED, chave primária;
- user_id BIGINT UNSIGNED, FK com cascade;
- purpose VARCHAR(64);
- policy_version VARCHAR(32);
- granted_at DATETIME;
- revoked_at DATETIME nullable;
- unique user_id + purpose + policy_version.

O purpose desta fase é mediapipe_metrics e a versão inicial é 2026-09-04. O SDK/modelo não será carregado antes do consentimento. O modo manual não exige consentimento.

As mutações usam rotas explícitas e protegidas por sessão, ownership e CSRF:

- POST /privacidade/consentimentos/mediapipe concede a versão vigente;
- POST /privacidade/consentimentos/mediapipe/revogar revoga o consentimento ativo.

Repetir a mesma concessão ou revogação é idempotente. A interface lê o estado pelo carregamento server-rendered da página; não haverá endpoint público separado para consentimento.

### Imutabilidade por revisão

O perfil é um snapshot da render_revision. A transação de ClipRenderRequestService deve:

1. bloquear o clip do proprietário;
2. validar status, intervalo e plano;
3. incrementar render_revision;
4. persistir perfil e keyframes;
5. atualizar o clip;
6. despachar exatamente um job com payload ainda igual a clip_id + render_revision;
7. sincronizar o projeto;
8. confirmar a transação.

Falha em qualquer etapa remove perfil, keyframes, mutação do clip e job. Retry a partir de failed cria uma nova revisão e um novo snapshot. Reclaim do mesmo job usa o snapshot existente.

## Objetos e contratos

### AspectRatio

Value object fechado que converte apenas os cinco valores aceitos em dimensões server-owned. Ele não aceita aliases, tamanhos arbitrários ou texto FFmpeg.

### ReframeKeyframe

Value object com atMs, centerX, centerY e source. Exige inteiros e floats finitos, precisão canônica e limites.

### ReframePlan

Snapshot imutável contendo aspect ratio, modo, dimensões, detector version server-owned e lista ordenada de keyframes.

### ReframePlanValidator

Recebe strings do Request, duração validada e limites de configuração. Rejeita:

- campos desconhecidos no JSON;
- JSON maior que 16 KiB;
- mais de 32 keyframes;
- número não finito, string numérica ou precisão acima de seis casas;
- tempo fora do corte, repetido ou fora de ordem;
- coordenada fora de 0..1;
- keyframes em original/center;
- quantidade/origem incompatível com manual/auto;
- detector version vinda do cliente;
- reframe não original acima de REFRAME_MAX_DURATION_SECONDS, padrão 90 e teto 180.

### ClipRenderProfileRepository

Persiste e carrega o perfil exato por clip_id + render_revision usando o mesmo PDO da transação e do guard de lease.

### FfmpegReframeFilterBuilder

Produz um filtergraph somente a partir de ReframePlan e metadados persistidos da origem.

O cliente nunca envia filtro, expressão, resolução, comando, codec ou caminho.

## Geometria

Para origem W × H e target Tw × Th:

- targetRatio = Tw / Th;
- se W / H for maior que targetRatio: cropHeight = H e cropWidth = maior inteiro par menor ou igual a H × targetRatio;
- caso contrário: cropWidth = W e cropHeight = maior inteiro par menor ou igual a W / targetRatio;
- x = clamp(centerX × W − cropWidth / 2, 0, W − cropWidth);
- y = clamp(centerY × H − cropHeight / 2, 0, H − cropHeight).

Centralizado usa centerX 0.5 e centerY 0.5. Manual usa um ponto constante. Automático interpola linearmente cada trecho usando t após setpts=PTS-STARTPTS. O builder limita x/y novamente dentro dos bounds.

O filtergraph de reenquadramento segue a ordem para qualquer proporção não original:

1. setpts=PTS-STARTPTS;
2. crop com dimensões fixas e x(t)/y(t) construídos no servidor;
3. scale para as dimensões fixas do perfil;
4. setsar=1.

São permitidos no máximo 32 keyframes para manter o argv abaixo dos limites de processo e o custo por frame previsível. Todos os números usam ponto decimal e representação canônica.

## Renderer e lifecycle

RenderClipRequest ganha ReframePlan, mantendo ClipRenderer::render(RenderClipRequest) estável.

RenderClipHandler:

- valida projeto, revisão atual, origem ready e análise atual como hoje;
- carrega somente o perfil da revisão reivindicada;
- usa original para jobs legados sem perfil;
- nunca substitui um perfil ausente por dados de outra revisão;
- mantém markRendering, guards, reserva write-ahead, publicação, completeRender e sync atômicos;
- mantém o ruling de lease perdido: cleanup, nenhuma mutação final e deferred 15;
- mantém mensagens públicas sanitizadas.

LocalFfmpegClipRenderer:

- mantém argv em array, bypass_shell e allowlist;
- adiciona -vf somente para plano não original;
- gera thumbnail a partir do MP4 final, preservando a proporção;
- mantém deadline compartilhado, limites de stdout/stderr, vídeo, thumbnail e cleanup;
- rejeita metadados de largura/altura ausentes ou incoerentes para reframe.

## Preview privado com Range

### Rota

GET /clips/{id}/source-preview

O lookup exige:

- sessão autenticada;
- clip pertencente ao usuário;
- projeto pertencente ao usuário;
- clip vinculado à análise atual do projeto;
- project source com status ready;
- storage disk suportado e objeto existente.

Estrangeiro, stale e inexistente retornam o mesmo 404 sem indicar existência.

### HTTP Range

O endpoint:

- suporta somente um range bytes;
- aceita N-M, N- e -N;
- responde 200 para ausência de Range;
- responde 206 com Content-Range e Content-Length corretos;
- responde 416 com Content-Range bytes */total para range inválido ou múltiplo;
- envia Accept-Ranges: bytes;
- faz seek e streaming em chunks de no máximo 1 MiB;
- usa Cache-Control: private, no-store e X-Content-Type-Options: nosniff;
- usa Content-Disposition: inline com nome genérico;
- nunca expõe object_key, caminho absoluto ou erro interno;
- não carrega o arquivo inteiro em memória.

GET não exige CSRF. Toda mutação permanece no POST protegido.

## MediaPipe no navegador

### Dependência

Será fixado @mediapipe/tasks-vision 1.0.1 e o modelo BlazeFace short range float16/1. Nenhum asset usa latest. Bundle, WASM, loader, modelo, licença e manifesto de SHA-256 serão servidos por self em public/assets/vendor/mediapipe-tasks-vision-1.0.1.

A documentação oficial exige um modelo treinado e informa que detect/detectForVideo são síncronos e podem bloquear a interface. Por isso a inferência não pode rodar na main thread.

### Gate de empacotamento

Antes da integração:

- baixar o pacote e modelo de fontes oficiais;
- registrar origem, versão, licença, tamanho e SHA-256 num manifesto;
- verificar os hashes em teste;
- provar a inicialização em navegador real com assets same-origin;
- provar ausência de dependência de Node em runtime;
- validar MIME de .wasm e .tflite;
- observar toda requisição de rede durante init/detecção.

Se 1.0.1 não funcionar sob esses contratos, a fase não usará CDN nem fallback silencioso para latest. O modo manual continua funcional enquanto a incompatibilidade é corrigida.

### Privacidade e consentimento

A documentação do projeto MediaPipe informa que os frames são processados no dispositivo, mas que APIs de Tasks podem enviar métricas de desempenho/uso e atribui ao integrador a responsabilidade por consentimento informado.

Antes de ativar auto:

- explicar que a detecção ocorre no dispositivo;
- informar sobre métricas técnicas do SDK;
- exigir ação afirmativa;
- persistir consentimento versionado;
- oferecer revogação no mesmo fluxo;
- não inicializar SDK/modelo sem consentimento ativo.

Nenhum frame ou resultado bruto é enviado pelo backend para Google.

### Worker

public/assets/js/reframe-worker.js será um module worker same-origin. A main thread:

- abre a fonte privada somente quando o usuário inicia o preview;
- amostra no máximo 180 frames;
- respeita passo mínimo de 500 ms;
- reduz o maior lado a no máximo 320 px antes da inferência;
- transfere ImageBitmap/OffscreenCanvas quando suportado;
- mantém no máximo uma inferência em voo;
- cancela ao ocultar página, trocar clip ou sair;
- encerra worker e libera bitmaps/URLs.

O worker:

- inicializa FaceDetector em VIDEO;
- aceita timestamps monotônicos;
- normaliza bounding boxes pelo tamanho do frame;
- associa detecções por IoU e distância de centro;
- tolera no máximo duas amostras ausentes;
- escolhe o track com maior persistência, confiança média e área útil;
- suaviza centros com média exponencial alpha 0.35;
- preserva primeiro e último pontos;
- simplifica pontos com desvio máximo de 0.025;
- limita a trajetória final a 32 keyframes;
- nunca recebe credenciais, CSRF, object key ou paths.

Mensagem do worker é tratada como entrada não confiável e passa pelo mesmo validador/canonicalizador do formulário antes do POST.

### Compatibilidade

Auto é progressive enhancement. Se Worker, WebAssembly, OffscreenCanvas, decoder, modelo, consentimento ou preview falharem:

- apresentar mensagem aria-live amigável;
- não enviar auto vazio;
- preservar start/end e proporção;
- manter centralizado/manual disponível.

MOV/WebM sem decoder do navegador usam o fallback manual. O proxy compatível fica fora desta fase.

## Interface

Cada card suggested/failed ganha:

- seleção de proporção;
- seleção centralizado/manual;
- botão Ativar enquadramento inteligente;
- disclosure/consentimento antes do SDK;
- preview de vídeo owner-only;
- overlay mostrando área final;
- sliders e interação por ponteiro/teclado para foco X/Y;
- status local carregando, analisando, pronto ou indisponível;
- resumo textual da saída;
- campos hidden canônicos enviados ao POST existente.

Sem JavaScript, o formulário continua aceitando original, centralizado ou manual com inputs numéricos válidos. Auto não aparece como valor enviável sem trajetória.

Em 320 px:

- editor em uma coluna;
- preview e canvas com max-width 100%;
- nenhum overflow horizontal;
- alvos de toque mínimos;
- botões com largura total;
- labels persistentes;
- foco visível;
- reduced-motion respeitado.

Cards queued/rendering/completed exibem apenas badge da proporção/modo. Status JSON pode expor output_aspect_ratio e reframe_mode; nunca expõe keyframes, detector version, dados faciais ou storage.

## Segurança

- Prepared statements e transação única para perfil + clip + job.
- CSRF no POST de render e no consentimento/revogação.
- Ownership em todo lookup.
- 404 indistinguível para recursos privados fora do owner.
- JSON estrito, limite de bytes e rejeição de chaves desconhecidas.
- Nenhum filtergraph ou argumento originado diretamente no cliente.
- CSP com worker-src 'self'.
- connect-src permanece restrito; qualquer necessidade externa observada no gate deve ser explicitamente revisada.
- wasm-unsafe-eval só pode ser adicionado se o navegador real provar necessidade; nunca usar unsafe-eval amplo.
- Assets com versionamento, licença e hash.
- Nenhuma telemetria, keyframe, path ou payload bruto em logs.

## Erros e estados

Erros de formulário voltam ao projeto com valores permitidos preservados. Erros de ownership permanecem 404 sem flash revelador.

Falhas do MediaPipe são locais e não alteram clip/projeto/job.

Falhas do render continuam usando:

- render_unavailable para binário indisponível;
- render_timeout para deadline;
- render_output_invalid para artefato/metadado inválido;
- render_failed para falha sanitizada.

Perfil inválido nunca chega ao job. Perfil persistido mas não correspondente à revisão faz o handler falhar de maneira sanitizada, sem usar configuração stale.

## Configuração e Hostinger

Novas configurações:

- REFRAME_MAX_DURATION_SECONDS, padrão 90, intervalo 1..180;
- REFRAME_MAX_KEYFRAMES, valor fixo suportado 32;
- REFRAME_PREVIEW_MAX_FRAMES, valor padrão 180;
- REFRAME_PREVIEW_MAX_EDGE, valor padrão 320;
- MEDIAPIPE_ASSET_VERSION, valor obrigatório 1.0.1.

O checker valida:

- assets esperados e hashes sem imprimir paths privados;
- MIME/configuração recomendada para WASM/modelo;
- worker-src da CSP;
- lease maior que o maior timeout de render;
- limites de upload e cron já existentes.

O runbook Hostinger documenta upload dos assets estáticos, tipos MIME, cron e fallback manual. Não haverá processo permanente, WebSocket, Redis, Docker ou Node em produção.

## Testes obrigatórios

### Unit

- enums/value objects e canonicalização;
- validação de combinações, JSON, quantidade, precisão, tempo e coordenadas;
- geometria para landscape, portrait e pontos nos limites;
- interpolação/clamp e filtergraph literal;
- parser de Range;
- tracker/smoothing/simplificação em harness JS;
- status público sem campos privados.

### Integration com banco SQL suportado

- migration reaplicável e recuperação de DDL parcial;
- checks, uniques, FKs e cascade;
- perfil + keyframes + clip + job atômicos;
- dispatch failure com rollback total;
- concorrência com duas conexões;
- retry cria nova revisão;
- reclaim usa o snapshot da mesma revisão;
- ledger de créditos invariável.

### Feature

- owner 200/206, foreign/stale 404 e guest redirect;
- ranges N-M, N-, -N, inválido e múltiplo;
- chunks e headers privados;
- CSRF e validação de formulário;
- consentimento/revogação owner-only;
- HTML funcional sem JS;
- CSP e assets self-hosted;
- status sem vazamento.

### Renderer/handler

- original sem -vf;
- cada proporção usa resolução correta;
- manual/auto usam somente filtro server-built;
- job legado sem perfil;
- revisão errada/stale;
- lease loss e reclaim;
- publicação, outbox e cleanup sem regressão.

### Navegador

- MediaPipe real inicializa em browser com assets locais;
- nenhuma dependência CDN;
- fluxo sem consentimento não carrega SDK;
- worker fake cobre sucesso, múltiplos rostos, nenhum rosto, timeout, mensagens inválidas, cancelamento e limite;
- fallback manual sem Worker/WASM/codec;
- single-flight e visibility;
- viewport 320, 768 e 1440;
- teclado, aria-live e reduced-motion.

### FFmpeg real

Gerar fonte H.264/AAC com regiões visuais determinísticas. Renderizar:

- original;
- 9:16 central;
- 1:1 manual;
- 4:5 automático com trajetória esquerda→direita;
- 16:9 a partir de fonte portrait.

FFprobe deve confirmar dimensões, H.264/AAC, duração e thumbnail na proporção final. Amostras inicial/final devem comprovar mudança do foco dinâmico.

### E2E local

Sem Gemini:

1. preparar origem privada real;
2. login único e CSRF;
3. consentir auto;
4. carregar preview Range;
5. persistir plano;
6. solicitar render;
7. worker finito;
8. status completed;
9. thumbnail/download válidos;
10. owner permitido e foreign/guest negados;
11. exatamente um job;
12. ledger invariável;
13. cleanup/outbox/temporários vazios;
14. localhost permanece aberto com um exemplo visual.

## Fora de escopo

- transcrição e legendas;
- editor completo, timeline e múltiplas versões de um clip completed;
- título/overlay, logo e marca;
- proxy 360p;
- detector server-side/VPS;
- storage compartilhado S3;
- cobrança de reframe;
- biblioteca global de clips;
- painel administrativo.

Esses itens mantêm sua ordem no roadmap e usarão o perfil versionado criado aqui.

## Critérios de aceite

A fase só termina quando:

- todos os formatos e os quatro comportamentos original/center/manual/auto funcionarem;
- auto usar MediaPipe real fora da main thread;
- manual funcionar sem JavaScript;
- preview privado suportar seek sem vazamento;
- perfil permanecer imutável por revisão e transacional com o job;
- FFmpeg gerar artefatos corretos com filtro exclusivamente server-built;
- nenhuma detecção facial bruta for persistida;
- consentimento anteceder o carregamento do SDK;
- testes Unit, Integration, Feature, Browser e FFmpeg real passarem sem skips de banco ou mídia;
- viewport 320 px e fallback forem verificados;
- checker, lint, diff, segurança e secret scans estiverem limpos;
- smoke autenticado deixar uma tela funcional no localhost.

## Referências

- Guia oficial Face Detector Web: https://developers.google.com/edge/mediapipe/solutions/vision/face_detector/web_js
- Pacote oficial: https://www.npmjs.com/package/@mediapipe/tasks-vision
- Repositório oficial e privacy notice: https://github.com/google-ai-edge/mediapipe
- Modelo oficial: https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite
