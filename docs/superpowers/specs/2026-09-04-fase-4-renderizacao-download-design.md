# Fase 4 — Renderização de clipes, thumbnails e download privado

## Objetivo

Transformar uma sugestão validada da Fase 3 em um arquivo MP4 real, reproduzível e privado. O usuário escolhe um corte, pode ajustar início e fim antes da primeira renderização, envia a solicitação sem manter a requisição web aberta, acompanha o estado real do job e baixa somente arquivos pertencentes à própria conta.

A Hostinger continua sendo o plano de controle em PHP/MySQL. Quando `proc_open` e FFmpeg estiverem disponíveis, o mesmo artefato executa a renderização localmente pelo cron. Quando não estiverem, o job permanece retomável para um worker VPS que use o mesmo banco e o mesmo armazenamento privado.

## Decisão e alternativas consideradas

### Escolhida: aprovação individual e renderização do corte original

Cada card oferece início/fim editáveis e a ação “Aprovar e renderizar”. Um job `render_clip` gera um MP4 H.264/AAC mantendo a proporção original e uma thumbnail JPEG. Esse corte passa a ter download privado, enquanto as demais sugestões continuam disponíveis.

É a menor entrega que produz um vídeo final real, mantém controle de custo e armazenamento, permite retry isolado e prepara contratos reutilizáveis para Smart Reframe e legendas.

### Não escolhida agora: renderizar todas as sugestões automaticamente

Reduz cliques, mas usa CPU, disco e tempo de cron mesmo para cortes que o usuário não deseja. Também torna falhas e reprocessamentos mais caros.

### Não escolhida agora: renderização, reenquadramento e legendas em uma única fase

Entrega mais recursos de uma vez, porém mistura FFmpeg, detecção facial, editor e transcrição em um único risco operacional. Reenquadramento, MediaPipe e legendas serão fases posteriores sobre o renderer validado aqui.

## Escopo

Incluído:

- aprovação individual de uma sugestão;
- ajuste opcional de início e fim antes da primeira renderização;
- persistência transacional da solicitação;
- job MySQL idempotente `render_clip`;
- corte com precisão de frame por FFmpeg;
- transcodificação para MP4 H.264/AAC compatível com navegador;
- thumbnail JPEG;
- estados reais por corte;
- polling autenticado;
- download e thumbnail protegidos por propriedade;
- execução local ou por worker VPS com o mesmo contrato;
- diagnóstico de requisitos para FFmpeg, timeout, lease e diretórios privados.

Fora desta fase:

- renderização automática de todos os cortes;
- segunda versão ou reedição de um clipe já concluído;
- Smart Reframe, MediaPipe, rastreamento facial ou mudança de proporção;
- legendas, título sobreposto, áudio adicional ou marca;
- timeline visual avançada;
- streaming com seek/range;
- cobrança adicional pela renderização;
- armazenamento S3 concreto;
- painel administrativo.

## Arquitetura

### Contratos

Criar `App\Contracts\ClipRenderer`:

    render(RenderClipRequest $request): RenderedClipArtifacts

`RenderClipRequest` contém apenas valores já validados: descritor privado da origem (`storage_disk` e `object_key`), início, duração e identificador técnico do job. `RenderedClipArtifacts` contém os dois arquivos temporários, tamanhos e tipos detectados. Nenhum caminho é recebido do navegador; somente o adaptador local, com o storage injetado, resolve a chave para um caminho absoluto e escolhe o diretório temporário privado.

`LocalFfmpegClipRenderer` implementa o contrato com `ProcessRunner`. Um futuro `RemoteClipRenderer` poderá produzir os mesmos artefatos sem alterar controller, repositório ou handler.

Criar `ClipRenderRequestService` para autorização, validação, transação e despacho. Criar `RenderClipHandler` para composição do worker. O controller permanece fino e nunca executa FFmpeg.

### Componentes

- `ClipRepository`: projeções públicas, lock do corte, atualização de estado e publicação dos artefatos.
- `ProjectSourceRepository`: entrega somente a origem validada do projeto.
- `ClipRenderRequestService`: aprova, versiona e enfileira.
- `RenderClipHandler`: valida payload, controla estados, chama renderer e publica os arquivos.
- `LocalFfmpegClipRenderer`: constrói argv fixo e produz MP4/JPEG temporários.
- `PrivateStorage`: recebe streams e guarda chaves relativas fora de `public/`.
- `ClipController`: ações de renderização, status, thumbnail e download.
- `Response`: ganha emissão em chunks para arquivo grande sem carregar todo o MP4 na memória.

## Modelo de dados

Uma migration aditiva preservará os campos de sugestão originais e acrescentará a `clips`:

- `render_start_time DECIMAL(10,3) UNSIGNED NULL`;
- `render_end_time DECIMAL(10,3) UNSIGNED NULL`;
- `render_revision INT UNSIGNED NOT NULL DEFAULT 0`;
- `render_error_code VARCHAR(64) NULL`;
- `output_size_bytes BIGINT UNSIGNED NULL`;
- `thumbnail_size_bytes BIGINT UNSIGNED NULL`;
- `approved_at DATETIME NULL`;
- `render_requested_at DATETIME NULL`;
- `rendered_at DATETIME NULL`.

Os campos existentes `start_time` e `end_time` continuam representando a sugestão da IA. Os campos `render_*_time` registram a escolha do usuário. `output_file` e `thumbnail` guardam somente chaves relativas privadas, nunca caminhos absolutos ou URLs públicas.

O enum existente de `clips.status` já suporta `suggested`, `approved`, `queued`, `rendering`, `completed` e `failed`. O projeto usa `rendering` enquanto há um corte ativo e `completed` quando existe ao menos um corte final e nenhum ativo. As sugestões continuam acessíveis nos estados `suggestions_ready`, `rendering` e `completed`.

Não haverá tabela de versões nesta fase. Um clipe concluído é imutável até a futura fase de editor/re-renderização.

## Solicitação e idempotência

Rota:

    POST /clips/{id}/render

O formulário envia `_token`, `start_time` e `end_time`. O serviço:

1. inicia transação;
2. carrega com `FOR UPDATE` o clipe, o projeto, a origem e a análise mais recente;
3. confirma que o projeto pertence à sessão;
4. aceita apenas `suggested` ou `failed`;
5. valida números decimais finitos, `start >= 0`, `end > start`, duração entre 1 e 180 segundos e `end` dentro da duração da origem;
6. incrementa `render_revision`, salva os intervalos, limpa erro e marca aprovação/solicitação;
7. despacha `render_clip` com payload exato `{clip_id, render_revision}`;
8. usa a chave idempotente `clip-render:{clip_id}:v{render_revision}`;
9. marca o corte `queued` e o projeto `rendering`;
10. confirma a transação.

Envios concorrentes do mesmo formulário veem o lock. Se o corte já estiver `queued` ou `rendering`, retornam o estado existente sem criar outro job. Estados estrangeiros, IDs inválidos e clipes de análise antiga recebem a mesma resposta 404.

Falha no despacho desfaz toda a transação. Não existe estado “aprovado sem job”.

## Worker e estados

O worker registra o quinto handler:

    render_clip

O payload aceita exatamente dois inteiros positivos, `clip_id` e `render_revision`. Caminhos, timestamps, codecs e opções FFmpeg são sempre recarregados do banco e da configuração.

Fluxo:

1. validar payload e conferir que revisão, projeto e análise ainda correspondem;
2. adquirir a proteção de efeito pelo lease;
3. marcar o clipe `rendering`;
4. resolver a origem pelo storage privado;
5. renderizar MP4 e thumbnail em diretório temporário privado;
6. verificar existência, tamanho máximo e MIME esperado;
7. publicar ambos em chaves aleatórias sob `processed/{project_id}/` e `thumbnails/{project_id}/`;
8. em transação protegida pelo lease, registrar chaves/tamanhos, limpar erro e marcar `completed`;
9. recalcular o estado agregado do projeto;
10. remover temporários em `finally`.

As chaves finais são novas por tentativa e recebem reservas write-ahead em `render_artifact_cleanups` antes da publicação. Se ocorrer erro normal após publicar somente um artefato, o handler tenta remover o objeto já publicado; falha de remoção preserva a reserva durável. Toda execução finita do worker, inclusive sem job elegível, drena reservas vencidas sem apagar objetos já referenciados por um clipe concluído.

`process_unavailable` é capacidade ausente: o job é adiado sem consumir tentativa e o corte volta a `queued`. Timeout ou falha transitória usa backoff até `max_attempts`. Quando acaba, o corte vira `failed` com código público sanitizado; sugestões e outros downloads permanecem disponíveis.

Se um corte falhar e não houver outro ativo, o projeto volta para `suggestions_ready`, exceto quando já houver algum clipe concluído, caso em que permanece `completed`.

## FFmpeg seguro

O comando usa array de argumentos com `bypass_shell`; não existe concatenação em shell. Binário, origem e diretório vêm da configuração/armazenamento. Início e duração são floats validados e formatados internamente com três casas.

Perfil MP4 inicial:

    ffmpeg -nostdin -hide_banner -loglevel error
      -ss START -i INPUT -t DURATION
      -map 0:v:0 -map 0:a?
      -c:v libx264 -preset veryfast -crf 23
      -pix_fmt yuv420p -movflags +faststart
      -c:a aac -b:a 128k OUTPUT

A interrogação em `-map 0:a?` permite vídeos sem áudio. A transcodificação, em vez de cópia de stream, mantém precisão do corte e compatibilidade web.

A thumbnail usa um segundo argv fixo, no ponto médio do arquivo renderizado, com um frame JPEG e escala máxima de 640 px preservando proporção. O renderer controla um prazo total compartilhado pelas duas execuções. O cron remove apenas temporários locais com nomes internos exatos e idade superior ao orçamento de renderização acrescido da margem de lease, recuperando falhas de `unlink` sem tocar arquivos ativos ou não relacionados.

No Windows, somente os basenames `ffmpeg`/`ffmpeg.exe` são aceitos; em produção Unix, o `ProcessRunner` continua resolvendo apenas o binário explicitamente permitido.

## Configuração e orçamento operacional

Adicionar:

    FFMPEG_BINARY=ffmpeg
    RENDER_TIMEOUT_SECONDS=240
    RENDER_MAX_OUTPUT_BYTES=524288000
    RENDER_MAX_DURATION_SECONDS=180
    RENDER_THUMBNAIL_MAX_BYTES=10485760

`QUEUE_LEASE_SECONDS` precisa cobrir o maior entre timeout Gemini e orçamento total de renderização, acrescido de 30 segundos. `bin/check-requirements.php` verifica isso sem executar FFmpeg, além de localizar o binário, conferir `proc_open` e testar leitura/escrita no storage privado.

O `--time-budget` do worker limita quando um novo job pode começar; não interrompe um job já reivindicado. Em hospedagem compartilhada com limite de processo menor que o orçamento do corte, a configuração recomendada é executar o mesmo worker em VPS.

## Entrega privada

Rotas autenticadas:

    GET /api/clips/{id}/status
    GET /clips/{id}/thumbnail
    GET /clips/{id}/download

Todas fazem lookup por `clip.id + projects.user_id`. ID inválido, item estrangeiro, estado incompleto ou objeto ausente retorna a mesma página/JSON 404, sem confirmar existência.

O endpoint de status expõe somente:

- `id`;
- `status`;
- `stage`;
- `message`;
- `render_start_time`;
- `render_end_time`;
- `thumbnail_url` quando concluído;
- `download_url` quando concluído;
- `updated_at`.

As URLs são construídas no servidor. Não são aceitos caminhos do banco como URL pública.

O download abre o caminho resolvido pelo `PrivateStorage` e envia blocos limitados, com `Content-Type: video/mp4`, tamanho conhecido, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store` e nome seguro baseado no ID do clipe. A thumbnail usa `image/jpeg` e também passa pela autorização. Nenhum arquivo fica sob `public/`.

## Interface

A página `/projetos/{id}` mantém os cards existentes:

- `suggested` ou `failed`: campos numéricos de início/fim e botão “Aprovar e renderizar”;
- `queued`: “Na fila para renderização” e atualização manual;
- `rendering`: “Renderizando vídeo” sem porcentagem inventada;
- `completed`: thumbnail, duração final e botão “Baixar MP4”;
- falha terminal: mensagem amigável e ação de tentar novamente.

O JavaScript `clip-status.js` consulta somente cards ativos, impede requisições concorrentes, usa backoff 3/5/8/15 segundos e para em estado terminal. Só aplica `thumbnail_url` e `download_url` que correspondam aos padrões internos exatos. Após cinco falhas, exibe feedback e mantém “Atualizar página”.

Sem JavaScript, POST/redirect/GET e atualização manual continuam funcionais. Em 320 px, campos e botões ocupam uma coluna. Foco visível, labels explícitos, `aria-live` e preferência de movimento reduzido são preservados.

A biblioteca e o dashboard levam para a página de sugestões quando o projeto estiver em `suggestions_ready`, `rendering` ou `completed`.

## Segurança

- autenticação em todas as rotas;
- CSRF obrigatório no POST;
- ownership verificado no serviço e novamente na leitura;
- prepared statements e locks transacionais;
- análise mais recente obrigatória;
- números finitos e limites derivados da mídia validada;
- nenhum argv vindo diretamente do request;
- binários em allowlist;
- arquivos privados e chaves aleatórias;
- projeções públicas sem `output_file`, `thumbnail`, caminho da origem, payload do job ou erro interno;
- mensagens do catálogo de erros;
- logs sem URI assinada, caminhos completos, cookies, tokens ou chave Gemini;
- cabeçalhos de download contra sniffing e cache compartilhado.

## Testes

### Unidade

- validação de intervalos e bordas da duração;
- geração segura e determinística de argv;
- vídeo com e sem áudio;
- mapeamento de estados e erros públicos;
- emissão em chunks sem carregar arquivo inteiro;
- rejeição de payload com campos extras.

### Integração MySQL

- aprovação, lock, despacho e rollback;
- idempotência sob duas solicitações;
- somente análise mais recente;
- transições `queued → rendering → completed`;
- retry/defer/falha terminal;
- publicação e limpeza de artefatos;
- recálculo do status do projeto;
- preservação do ledger de créditos.

### Feature e autorização

- login e CSRF;
- 404 indistinguível para IDs inválidos, inexistentes e estrangeiros;
- owner pode solicitar, consultar, ver thumbnail e baixar;
- não owner nunca obtém metadados nem bytes;
- resposta pública nunca contém chaves ou caminhos.

### Navegador

- polling sem concorrência;
- links internos seguros;
- feedback após falhas;
- formulário e estados responsivos;
- fluxo sem JavaScript.

### FFmpeg real

Um teste de integração opcional gera uma origem curta sintética, renderiza um intervalo, confirma MP4/JPEG não vazios e inspeciona duração/codecs. Ele só é ignorado quando FFmpeg não está instalado; antes de declarar a fase pronta no ambiente local, o binário será configurado e esse teste deverá passar.

## Critérios de aceite

1. Um usuário autenticado abre uma sugestão e solicita um intervalo válido.
2. A requisição web retorna rapidamente e cria exatamente um job.
3. O worker produz MP4 e thumbnail reais.
4. O card muda por estados reais, sem progresso fictício.
5. O proprietário baixa um MP4 não vazio; outro usuário recebe 404.
6. Reenvio ou retry não duplica job nem consome créditos novamente.
7. Falha de FFmpeg não apaga sugestões nem downloads já concluídos.
8. Nenhum artefato, caminho privado ou segredo aparece no Git ou em resposta pública.
9. Unit, Integration, Feature, navegador e integração FFmpeg passam.
10. O verificador operacional informa claramente quando a Hostinger precisa de worker VPS.
