# Projetos e processamento inicial — Design da Fase 2

## Objetivo

Transformar a fundação autenticada da Fase 1 em um fluxo funcional de ingestão: o usuário cria um projeto enviando um arquivo MP4, MOV ou WEBM, ou informando uma URL HTTPS direta; o sistema armazena a origem fora da área pública, cria um job idempotente no MySQL, executa FFprobe por cron com lease e retry, e apresenta biblioteca e progresso em tempo quase real. Esta fase entrega o plano de controle e a sondagem técnica da mídia; seleção com Gemini e renderização dos clipes continuam em fases posteriores.

## Escopo

Esta fase inclui exatamente:

- upload de arquivos MP4, MOV e WEBM;
- importação somente de URL HTTPS direta para um arquivo de vídeo;
- validação por extensão, MIME detectado no servidor, assinatura básica do contêiner e limites configuráveis de tamanho;
- armazenamento privado local e metadados em `project_sources`;
- fila persistente em `processing_jobs`, com idempotência, lease, retry, backoff e recuperação de jobs abandonados;
- runner CLI compatível com cron da Hostinger;
- contrato seguro de execução de processos e integração inicial com FFprobe;
- biblioteca, formulário de novo projeto, CTAs ativos no dashboard e polling de status;
- endpoint autenticado `GET /api/projects/{id}/status`, sempre limitado ao proprietário;
- contratos que permitam trocar disco local por S3 compatível e processamento local por VPS sem mudar controllers ou modelo de domínio.

Não fazem parte desta fase: Gemini, escolha automática de cortes, FFmpeg de renderização, MediaPipe, legendas, editor, cobrança, dedução de créditos e administração. O status `ready` significa “origem validada e metadados extraídos”, não “clipes gerados”.

## Premissas e restrições globais

- Produção deve funcionar em PHP 8.0+ e MySQL 8, sem Node.js, Docker, Redis, WebSocket ou daemon permanente.
- O cron pode iniciar múltiplas execuções sobrepostas e pode ser interrompido sem aviso.
- `upload_max_filesize`, `post_max_size`, `max_execution_time`, espaço em disco, funções `proc_*` e presença de FFprobe variam por plano Hostinger.
- Arquivos de mídia nunca ficam sob `public/`; nomes fornecidos pelo usuário nunca viram caminhos físicos.
- Toda consulta a projeto, origem ou status recebe o `user_id` autenticado e falha de forma indistinguível entre recurso inexistente e recurso de outro usuário.
- Toda mutação web usa CSRF. Endpoints JSON somente de leitura continuam exigindo sessão autenticada e retornam `Cache-Control: no-store`.
- URLs, caminhos, cabeçalhos remotos, saída de processo e mensagens de exceção são dados não confiáveis e não podem vazar credenciais em logs ou HTML.
- A interface preserva o design system escuro e responsivo da Fase 1.

## Arquitetura

O PHP/MySQL existente permanece como plano de controle. Controllers recebem formulários e consultam status; serviços validam e registram a origem; repositórios persistem projetos, origens e jobs; um comando CLI de curta duração reivindica uma quantidade limitada de jobs e termina. A execução pesada é acessada por contratos: nesta fase há armazenamento local privado e processador local com FFprobe, mas os consumidores dependem de interfaces compatíveis com S3 e VPS.

O request web não executa FFprobe nem baixa toda a URL remota. Uploads já recebidos pelo PHP são movidos para o armazenamento privado durante a criação. Uma URL direta é validada contra SSRF e registrada; sua transferência limitada acontece dentro do job `fetch_and_probe`, evitando consumir o tempo de resposta web. Após a ingestão, o job `probe_source` extrai duração, dimensões, codecs e presença de áudio.

## Componentes e contratos

### Configuração de mídia

`config/media.php` centraliza:

- `MEDIA_DISK=local`;
- `MEDIA_PRIVATE_ROOT`, fora de `public/`;
- `MEDIA_MAX_UPLOAD_BYTES`, com padrão conservador de 524.288.000 bytes;
- `MEDIA_ALLOWED_EXTENSIONS=mp4,mov,webm`;
- `MEDIA_DOWNLOAD_TIMEOUT_SECONDS` e `MEDIA_MAX_REDIRECTS`;
- `FFPROBE_BINARY`, caminho configurado pelo operador;
- `PROCESS_TIMEOUT_SECONDS`, `PROCESS_OUTPUT_LIMIT_BYTES`;
- `QUEUE_LEASE_SECONDS`, `QUEUE_MAX_ATTEMPTS`, `QUEUE_BATCH_SIZE`.

O checker de requisitos informa limites efetivos do PHP, escrita no diretório privado e disponibilidade de `proc_open`/FFprobe. Ausência de FFprobe é uma capacidade indisponível, não uma falha de bootstrap do site.

### `PrivateStorage`

```php
interface PrivateStorage
{
    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject;
    public function putStream($stream, string $objectKey, int $maxBytes): StoredObject;
    public function absolutePath(string $objectKey): string;
    public function delete(string $objectKey): void;
}
```

`StoredObject` contém `objectKey`, `sizeBytes` e `sha256`. O adaptador local resolve `realpath`, exige que todo destino permaneça sob a raiz privada, cria nomes aleatórios e grava primeiro em arquivo `.part`, promovido atomicamente após tamanho e hash serem confirmados. Um futuro `S3PrivateStorage` preservará o mesmo contrato; para ele, o processamento remoto consumirá uma referência assinada de curta duração em vez de `absolutePath()`.

### `ProjectCreator`

```php
interface ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt;
    public function fromDirectUrl(int $userId, array $input): ProjectReceipt;
}
```

`ProjectReceipt` expõe apenas `projectId`, `status` e `created`. A chave de idempotência enviada pelo formulário é normalizada por hash junto do usuário; repetir a mesma submissão retorna o projeto já criado sem duplicar arquivo ou job.

### `JobDispatcher` e fila

```php
interface JobDispatcher
{
    public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int;
}
```

`processing_jobs` é a fonte de verdade. O worker usa transação curta e `SELECT ... FOR UPDATE` para escolher um job elegível, grava `status=running`, `leased_until`, `worker_id` e token aleatório, e confirma antes de processar. O lease permite que outro cron recupere trabalho abandonado. A conclusão e o retry somente atualizam a linha quando `id`, `worker_id` e hash do token ainda coincidem, impedindo que um worker atrasado sobrescreva uma execução recuperada.

Retries incrementam `attempts`, limpam o lease e definem `available_at` com backoff exponencial limitado. Erros permanentes de validação terminam em `failed`; indisponibilidade transitória de rede/processador pode retornar a `retry`. A restrição única de `queue_name + idempotency_key` evita jobs duplicados.

### `MediaProcessor` e execução local

```php
interface MediaProcessor
{
    public function inspect(ProjectSource $source): MediaMetadata;
}
```

`LocalFfprobeProcessor` usa `PrivateStorage` e `ProcessRunner`. `ProcessRunner::run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult` recebe argv separado, nunca uma linha de shell. O binário vem somente de configuração; caminhos vêm do armazenamento validado; argumentos são montados pelo serviço. A saída é limitada, o tempo é limitado, stderr é sanitizado e JSON inválido ou campos fora de faixa causam falha controlada.

Um futuro `RemoteMediaProcessor` envia apenas um identificador opaco, metadados esperados e uma URL assinada curta para um VPS. Ele devolve o mesmo `MediaMetadata`; controllers, fila e endpoint de status não mudam.

## Modelo de dados

### Alterações em `projects`

- `ingest_key CHAR(64) NULL`, único por usuário;
- `progress TINYINT UNSIGNED NOT NULL DEFAULT 0`;
- `error_code VARCHAR(64) NULL`;
- `error_message VARCHAR(255) NULL`, sempre mensagem pública sanitizada;
- estados desta fase: `receiving`, `queued`, `fetching`, `probing`, `ready`, `failed`.

### `project_sources`

- `id`, `project_id` único, `source_type` (`upload` ou `direct_url`);
- `storage_disk`, `object_key`, `original_name`, `extension`, `mime_type`;
- `size_bytes`, `sha256`, `source_url`, `source_host`;
- `width`, `height`, `duration_seconds`, `video_codec`, `audio_codec`, `has_audio`;
- `status` (`pending`, `stored`, `ready`, `failed`), `fetched_at`, timestamps;
- índice por `status/created_at` e foreign key com cascade para projeto.

`source_url` é tratada como dado sensível: não aparece no status JSON, views ou logs. O valor existe apenas até a coleta ser concluída e pode ser limpo em política posterior de retenção.

### `processing_jobs`

- `id`, `queue_name`, `type`, `project_id`, `payload_json`;
- `idempotency_key`, único dentro da fila;
- `status` (`queued`, `running`, `retry`, `completed`, `failed`);
- `progress`, `attempts`, `max_attempts`, `available_at`;
- `worker_id`, `lease_token_hash`, `leased_until`, `started_at`, `finished_at`;
- `last_error_code`, `last_error_message` sanitizada, timestamps;
- índices para reivindicação (`queue_name`, `status`, `available_at`, `leased_until`, `id`) e consulta por projeto.

## Validação e segurança da entrada

### Upload

O controller rejeita erro de transporte do PHP e ausência de arquivo. O serviço aplica limite com o menor valor entre configuração e limites efetivos do PHP, aceita somente `.mp4`, `.mov` e `.webm`, detecta MIME com `finfo`, compara combinações permitidas e verifica assinatura básica antes de promover o arquivo. `video/mp4`, `video/quicktime` e `video/webm` são aceitos apenas com contêiner compatível; extensão e MIME isolados não bastam. O nome original é somente metadado escapado.

Falha após mover um upload remove o objeto parcial/final e reverte os registros ainda não confirmados. A transação de banco nunca permanece aberta durante cópia grande ou chamada de rede.

### URL direta

Aceita-se somente `https`, sem usuário/senha embutidos e com host DNS explícito. Antes de cada conexão e após cada redirecionamento, o resolvedor bloqueia loopback, link-local, redes privadas, endereços reservados e destinos sem IP público. O downloader restringe quantidade de redirecionamentos, tempo total, Content-Length, bytes realmente lidos e tipos MIME. A conexão usa validação TLS e nome do host. Resposta HTML, playlist, página de compartilhamento e URL de plataforma não são “URL direta” e são rejeitadas.

Para reduzir rebinding, o adaptador conecta somente ao conjunto de IPs públicos aprovados pelo validador e mantém o host original para SNI/Host. Se o ambiente Hostinger não permitir esse controle com segurança, a importação por URL é marcada como capacidade indisponível e o upload local continua funcional; não se relaxam as regras SSRF.

## Fluxos

### Criação por upload

1. Usuário autenticado abre `/projetos/novo`; o servidor gera CSRF e chave de idempotência.
2. O POST valida campos e arquivo, grava o objeto privado e calcula hash/tamanho.
3. Uma transação cria ou recupera projeto pelo `user_id + ingest_key`, cria `project_sources` e despacha `probe_source` idempotente.
4. A resposta redireciona para `/projetos`, que mostra `queued`.
5. O cron reivindica o job, executa FFprobe e grava metadados, `progress=100` e `status=ready`.

### Criação por URL

1. O POST valida sintaxe HTTPS e destino público sem baixar o arquivo.
2. A transação cria projeto/origem e job `fetch_and_probe`.
3. O worker baixa em streaming para `.part`, reforçando limite e destino a cada redirecionamento.
4. Após validar e promover o arquivo, o mesmo handler executa inspeção e finaliza o projeto.

### Status e polling

`GET /api/projects/{id}/status` exige sessão ativa. O repositório usa `WHERE id = :id AND user_id = :user_id`; ausência retorna 404 genérico. A resposta contém somente `id`, `status`, `progress`, `stage`, `message`, metadados de mídia seguros e `updated_at`, com `Cache-Control: no-store`. A UI consulta apenas projetos não terminais, usa intervalo inicial de 3 segundos, backoff até 15 segundos, pausa com a página oculta e para em `ready`, `failed`, 401/404 ou após limite de erros.

## Interface

- O CTA “Criar novo projeto” do dashboard passa a apontar para `/projetos/novo`.
- `/projetos` lista somente projetos do usuário, com nome, origem, duração, tamanho, data, status e progresso reais.
- `/projetos/novo` oferece duas abas acessíveis: “Enviar arquivo” e “Importar URL”.
- O formulário informa formatos e limite efetivo antes do envio, preserva o nome do projeto em erros e nunca repopula URL rejeitada com credenciais.
- Cards em andamento expõem `data-project-status-url` para o polling; leitores de tela recebem atualizações por uma região `aria-live=polite` sem anunciar cada ponto percentual.
- Estados vazios, falha e capacidade FFprobe indisponível têm copy honesta e ação recuperável.

## Operação na Hostinger

O cron recomendado executa `php bin/process-jobs.php --limit=1` a cada minuto, com lock de execução local como redução de sobreposição, sem depender dele para consistência. A segurança real vem do lease no banco. Cada invocação limita quantidade de jobs e tempo total para respeitar limites do provedor.

O deploy documenta como verificar tamanho máximo de upload, quota de disco, PHP CLI, `proc_open` e FFprobe. Se `proc_open` ou FFprobe estiver indisponível, a aplicação web permanece saudável, aceita a origem conforme a política configurada e informa que o processamento aguarda um worker compatível. A troca para VPS usa `RemoteMediaProcessor`; a troca para S3 usa `S3PrivateStorage`. Jobs e estados permanecem no MySQL, permitindo migração incremental.

## Observabilidade e falhas

Cada execução gera `worker_id` e usa o identificador do job como correlação. Logs incluem IDs, estágio, duração e código de erro, mas removem URL, query string, caminho absoluto, saída integral de processo e tokens. A mensagem pública vem de catálogo por `error_code`; detalhes técnicos sanitizados ficam no log privado. Arquivos `.part` antigos e leases expirados podem ser identificados por comando de manutenção posterior sem serem servidos pela web.

## Critérios de aceite

- MP4, MOV e WEBM válidos são armazenados fora de `public/`; tipos divergentes, arquivos grandes e nomes maliciosos são rejeitados.
- Apenas URLs HTTPS diretas e destinos públicos passam; localhost, redes privadas, credenciais embutidas e redirecionamento inseguro falham antes da transferência.
- Repetir a mesma chave de idempotência não duplica projeto, origem ou job.
- Dois workers concorrentes não concluem o mesmo lease; job abandonado volta a ficar elegível e worker antigo não pode finalizar o novo lease.
- Retries respeitam limite e backoff; falha permanente termina com código público seguro.
- FFprobe recebe argv sem shell, caminho confinado ao storage, timeout e limite de saída.
- O endpoint de status nunca revela projeto alheio nem `source_url`, caminho físico, payload ou erro técnico.
- Biblioteca, formulário, dashboard e polling funcionam em desktop e mobile e permanecem utilizáveis sem JavaScript.
- `php bin/process-jobs.php --limit=1` é finito, observável e documentado para cron Hostinger.
- Os contratos permitem implementar VPS/S3 sem alterar controllers, views ou formato público do status.

## Sequência de entrega

A Tarefa 1 é bloqueadora. Após sua aprovação, Tarefas 2, 3 e 5 podem ser executadas em paralelo porque dependem apenas dos contratos estabilizados. A Tarefa 4 integra armazenamento, fila e FFprobe depois das Tarefas 2 e 3. A Tarefa 6 integra composição, rotas, endpoint, polling e dashboard depois das Tarefas 2, 3, 4 e 5.
