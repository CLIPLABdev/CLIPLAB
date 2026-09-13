# Inteligência com Gemini e sugestões de clipes — Design da Fase 3

## Objetivo

Estender o fluxo de ingestão e inspeção técnica da Fase 2 para analisar automaticamente o vídeo com Gemini, validar uma resposta estruturada, persistir sugestões reais de clipes e cobrar créditos de forma transacional. O usuário acompanha etapas reais e consulta resumo, score, gancho e motivo de cada sugestão; esta fase não corta, reenquadra, legenda, renderiza nem entrega um arquivo de vídeo final.

## Pré-requisito e escopo

A execução desta fase começa sobre a Fase 2 integralmente revisada, incluindo composição do worker, rotas de projetos e endpoint autenticado de status. O commit `2c335c3` já fornece armazenamento privado, FFprobe, fila com lease e os handlers de ingestão; os contratos finais da Fase 2 devem ser preservados durante a integração.

Esta fase inclui exatamente:

- cliente REST server-side para Gemini GenerateContent e Files API, sem SDK ou JavaScript de IA;
- upload resumable iniciado pelo servidor e transferência do arquivo privado em streaming, sem carregar o vídeo inteiro na memória;
- consulta do estado remoto `PROCESSING`, `ACTIVE` ou `FAILED`;
- prompt versionado para seleção de momentos e resposta em JSON estruturado;
- validação local independente do JSON, campos, limites, timestamps, duração, score e strings;
- uma nova tentativa de geração quando a primeira resposta é estruturalmente inválida;
- tabelas `ai_analyses`, `clips` e `credit_reservations`;
- reserva, consumo e reembolso idempotentes de créditos com bloqueio transacional;
- jobs `analyze_video` e `generate_clips` na fila MySQL existente;
- adiamento de job sem consumir tentativa enquanto o arquivo Gemini permanece `PROCESSING`;
- disparo automático da análise após o FFprobe concluir;
- estados públicos de análise, resumo e sugestões em uma tela mínima, acessível e responsiva.

Não fazem parte desta fase: FFmpeg para corte/renderização, thumbnails, downloads, MediaPipe, face tracking, Smart Reframe, legendas, editor, aprovação de corte, cobrança real, painel administrativo ou exposição/configuração da chave no navegador.

## Restrições globais

- Produção continua compatível com PHP 8.0+, MySQL 8, cron curto e hospedagem compartilhada Hostinger, sem Node.js, Docker, Redis, WebSocket ou daemon.
- A chave existe somente em `GEMINI_API_KEY` no ambiente do servidor. Não entra em query string, banco, payload de job, HTML, JavaScript, resposta JSON, exceção pública ou log.
- O modelo é configuração explícita em `GEMINI_MODEL`; não fica acoplado a um nome que possa ser descontinuado.
- Nenhuma chamada externa acontece durante uma requisição web. Upload, consulta de estado e inferência ocorrem apenas no worker CLI.
- Vídeo privado é lido somente por `PrivateStorage`; caminho absoluto, URI do provedor, resource name, prompt integral e resposta bruta não são campos públicos.
- cURL transmite arquivo por stream, impõe timeout, limites de resposta e TLS. URLs de sessão de upload devem ser HTTPS e pertencer a host Google permitido.
- A aplicação não considera JSON sintaticamente válido como confiável: valida novamente todos os valores e limites antes de persistir clipes.
- Toda consulta web continua limitada por `project_id + user_id`, com 404 indistinguível para recurso alheio e inexistente.
- Progresso representa etapas persistidas; a interface não simula percentuais.

## Arquitetura

O plano de controle permanece PHP/MySQL. Quando `ProbeSourceHandler` confirma os metadados, um `AiPipelineStarter` calcula o custo em créditos, reserva o valor na mesma transação e despacha `analyze_video` com uma chave idempotente. O handler de análise envia a mídia local para a Files API, persiste somente a referência remota necessária e consulta seu estado. Se o estado for `PROCESSING`, devolve um resultado `deferred`; a fila agenda nova consulta e restaura o contador de tentativas, distinguindo espera normal de falha.

Quando o arquivo fica `ACTIVE`, `GeminiService` chama `generateContent` com prompt e schema versionados. `AnalysisResponseValidator` transforma o texto JSON em `AiAnalysisResult`; uma primeira resposta inválida permite exatamente uma nova geração. O resultado aprovado é persistido em `ai_analyses` e dispara `generate_clips`. Esse segundo handler cria as linhas de `clips` por índice estável, consome a reserva e marca o projeto como `suggestions_ready`, tudo na mesma transação. Falha terminal antes do consumo reembolsa a reserva uma única vez.

O acesso externo fica atrás de interfaces e transporte injetável. Testes usam respostas falsas; a suíte e o checker nunca exigem chave nem realizam chamada à internet. No futuro, `GeminiService` pode rodar em um worker VPS usando o mesmo MySQL/storage assinado sem alterar controllers, formatos públicos ou regras de crédito.

## Componentes e contratos

### Configuração

`config/gemini.php` lê:

- `GEMINI_API_KEY`, vazio por padrão e obrigatório somente para executar IA; é tratado como segredo opaco imprimível, sem validar prefixo ou formato comercial;
- `GEMINI_MODEL`, vazio por padrão e definido pelo operador; para o rollout atual, o valor estável recomendado e explícito é `gemini-3.7-flash`, sem aliases `*-latest`;
- base fixa `https://generativelanguage.googleapis.com`;
- `GEMINI_HTTP_TIMEOUT_SECONDS`, padrão 180;
- `GEMINI_RESPONSE_LIMIT_BYTES`, padrão 1.048.576;
- `GEMINI_FILE_POLL_SECONDS`, padrão 15, limitado entre 5 e 300;
- `GEMINI_VALIDATION_ATTEMPTS`, fixado em 2 nesta fase;
- `GEMINI_CREDITS_PER_MINUTE`, padrão 1.

Configuração ausente produz `ai_unconfigured`, mensagem pública neutra e reembolso; o site e os fluxos anteriores continuam disponíveis.

### Cliente Gemini

```php
interface VideoAnalysisProvider
{
    public function upload(ProjectSource $source): GeminiFile;
    public function getFile(string $resourceName): GeminiFile;
    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string;
    public function deleteFile(string $resourceName): void;
}
```

`GeminiFile` contém apenas `name`, `uri`, `mimeType` e `state`. O construtor aceita estados `PROCESSING`, `ACTIVE` e `FAILED`, resource name canônico `files/{id}` com ID de 1–40 caracteres minúsculos alfanuméricos/hífens sem hífen nas bordas, URI HTTPS do provedor na porta padrão e MIME de vídeo já validado. Ausência de estado ou `STATE_UNSPECIFIED` em uma resposta válida é normalizada pelo adaptador para `PROCESSING`; o objeto de domínio permanece estrito.

`GeminiService` usa a API REST v1beta por cURL. O upload resumable faz `POST /upload/v1beta/files` com `{"file":{"displayName":"..."}}`, captura `X-Goog-Upload-URL` sem diferenciar caixa e faz outro `POST` para essa sessão, transmitindo o arquivo privado como stream com offset 0 e comando `upload, finalize`; nunca carrega o vídeo inteiro em memória. A URL opaca de sessão aceita somente HTTPS, host exato `generativelanguage.googleapis.com`, porta ausente ou 443, sem userinfo, fragmento, CR/LF, redirecionamento ou query registrada. Início e finalização compartilham um único prazo monotônico, portanto a segunda etapa não reinicia o timeout integral.

A resposta de upload é lida do envelope `{"file": {...}}`; `getFile()` consulta `/v1beta/{resourceName}` e lê o objeto direto; `deleteFile()` considera 404 uma limpeza idempotente. `generate()` chama `/v1beta/models/{model}:generateContent` com `fileData.mimeType`, `fileData.fileUri`, instrução e `generationConfig.responseFormat.text={mimeType:"application/json",schema:{...}}`. Somente um candidato final utilizável é aceito; partes de pensamento/assinatura e finalizações não utilizáveis são ignoradas ou rejeitadas de forma determinística. A chave é enviada somente no header `x-goog-api-key`, nunca em query, URL, exceção ou log. Como o contrato desta fase retorna somente o texto gerado, `provider_request_id` permanece nulo até existir um envelope de resposta próprio.

Os fatos do contrato REST e os estados de arquivo foram conferidos na documentação oficial da [Files API](https://ai.google.dev/api/files), [compreensão de vídeo](https://ai.google.dev/gemini-api/docs/video-understanding) e [saídas estruturadas](https://ai.google.dev/gemini-api/docs/generate-content/structured-output). A implementação mantém modelo configurável nos limites explícitos acima porque esses serviços evoluem; o endpoint permanece fixo para reduzir a superfície de SSRF.

### Prompt e validação

`ViralClipPrompt::VERSION` identifica o prompt persistido. Ele trata todo o conteúdo do vídeo como dados não confiáveis e manda ignorar quaisquer instruções encontradas nele. Pede em PT-BR resumo e até 10 sugestões independentes, priorizando gancho, surpresa, valor, história, emoção, pergunta, humor e mudança de assunto; proíbe timestamps inventados, duplicatas, mais de três casas decimais e texto fora do JSON. O schema usa somente o subconjunto suportado pelo provedor — `type`, `properties`, `required`, `additionalProperties`, `items`, `minItems`, `maxItems`, `minimum`, `maximum`, `enum`, `description` e `propertyOrdering` — e solicita:

```json
{
  "video_summary": "Resumo",
  "clips": [{
    "title": "Título",
    "start_time": 125.0,
    "end_time": 178.0,
    "duration": 53.0,
    "score": 92,
    "reason": "Motivo",
    "hook": "Gancho",
    "category": "educational"
  }]
}
```

O validador usa `json_decode(..., false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)` para manter objetos e listas distintos, limita a resposta bruta a 1 MiB e exige objeto superior com somente `video_summary` e `clips`; chaves são exatas e independentes de ordem. Exige resumo entre 1 e 2.000 caracteres; 1 a 10 clipes numa lista sequencial; campos exatos; números PHP inteiros/flutuantes finitos, sem strings ou booleanos; valores temporais representáveis em milissegundos; `0 <= start_time < end_time <= duração FFprobe`; duração positiva, no máximo 90 segundos e consistente com `end-start` por tolerância inclusiva de 250 ms; score matematicamente inteiro 0–100; strings não vazias sem controles proibidos e dentro das colunas; e categoria na allowlist `educational`, `story`, `emotional`, `humorous`, `controversial`, `insight`, `question`, `other`. Para fontes menores que 20 segundos, a única janela válida cobre o vídeo inteiro; nas demais, o mínimo aceito é 20 segundos. A ordem original vira `suggestion_index`, intervalos parcialmente sobrepostos são permitidos e intervalos exatamente duplicados são rejeitados.

`InvalidAnalysisResponse` expõe somente mensagem fixa e um código allowlisted: `response_too_large`, `invalid_json`, `invalid_shape`, `invalid_fields`, `invalid_clip_count`, `invalid_text`, `invalid_timestamp`, `invalid_duration`, `invalid_timeline`, `invalid_score`, `invalid_category`, `duplicate_clip` ou `invalid_domain`. Duração de origem fora de 1–86.400 segundos é erro de programação (`InvalidArgumentException`), não resposta inválida do provedor.

Falha de parse ou validação nunca cria linhas em `clips`. A primeira falha registra somente código, tentativa e identificadores internos, então repete a geração com o mesmo schema. A segunda falha termina em `ai_response_invalid`, marca análise/projeto como falhos e reembolsa. O conteúdo bruto e os valores rejeitados não vão para logs.

## Modelo de dados

### `ai_analyses`

- `id`, `project_id`, `prompt_version`, `model`;
- `status`: `queued`, `uploading`, `waiting_file`, `generating`, `validating`, `completed`, `failed`;
- `gemini_file_name`, `gemini_file_uri`, `gemini_file_mime`, `gemini_file_state`;
- `video_summary`, `validated_response_json`, `validation_attempts`;
- `provider_request_id`, `error_code`, `error_message`, `completed_at`, timestamps;
- unique `project_id + prompt_version` e índices por status/data.

`validated_response_json` contém somente o objeto aprovado. `gemini_file_*` e `provider_request_id` são internos. Não há chave, prompt integral ou resposta bruta.

### `clips`

- `id`, `project_id`, `ai_analysis_id`, `suggestion_index`;
- `title`, `start_time`, `end_time`, `duration_seconds`, `viral_score`, `hook`, `reason`, `category`;
- `status` inicialmente `suggested`; `output_file` e `thumbnail` permanecem nulos até fase de renderização;
- timestamps, unique `ai_analysis_id + suggestion_index`, índices por projeto/status/score.

### `credit_reservations`

- `id`, `user_id`, `project_id`, `operation=ai_analysis`, `units`;
- `status`: `reserved`, `consumed`, `refunded`;
- `idempotency_key`, `credit_transaction_id`, `refund_transaction_id`;
- `consumed_at`, `refunded_at`, timestamps;
- unique `user_id + idempotency_key` e foreign keys.

A última linha de `credit_transactions.balance_after` é a fonte contábil de verdade; `users.credits` é um espelho sincronizado para as telas e compatibilidade. Na ausência de histórico, o saldo inicial vem da linha de usuário já bloqueada. A reserva debita imediatamente, cria uma transação `debit` positiva referenciada à reserva e atualiza o espelho para o mesmo `balance_after`. `consume()` apenas muda `reserved` para `consumed`; não debita novamente. `refund()` muda somente `reserved` para `refunded`, cria uma transação `credit` positiva e devolve o valor. Cada transição é idempotente em repetição ou recuperação de lease.

A ordem global de locks é usuário, reserva e última linha do ledger. `reserve()` verifica que o projeto pertence ao usuário e, sob esses locks, reaproveita ou cria a reserva; `consume()` e `refund()` descobrem primeiro o usuário sem lock e então reabrem a reserva depois do lock de usuário. Unidades e custo são inteiros positivos limitados a 2.147.483.647. O motivo interno de reembolso aceita somente `analysis_failed`, `ai_unconfigured`, `ai_provider_rejected`, `ai_file_failed`, `ai_response_invalid`, `analysis_not_found`, `processing_persistence_failed`, `ai_timeout`, `ai_rate_limited` ou `ai_unavailable`. Se o chamador já abriu a transação, o serviço não faz `begin`, `commit` nem `rollback`; caso contrário, é proprietário de toda a transação.

O custo é `max(1, ceil(duration_seconds / 60) * credits_per_minute)`. Saldo insuficiente não cria job de IA, não debita e deixa o projeto em `awaiting_credits` com copy recuperável.

## Estados, jobs e idempotência

Novos estados de projeto: `ai_queued`, `uploading_ai`, `waiting_ai_file`, `analyzing`, `identifying_clips`, `suggestions_ready`, `awaiting_credits`. `failed` continua terminal. `project_sources.status=ready` continua significando que a mídia foi inspecionada. Os percentuais permanecem monotônicos depois de `probing/70`: fila `75`, upload `80`, espera remota `82`, análise `88`, identificação `95` e sugestões `100`.

Chaves idempotentes:

- análise: `ai:analyze:{projectId}:{promptVersion}`;
- clipes: `ai:clips:{analysisId}:{promptVersion}`;
- reserva: SHA-256 da mesma identidade lógica da análise;
- sugestões: unique `ai_analysis_id + suggestion_index`.

`analyze_video` recebe somente os IDs internos `analysis_id`, `source_id` e `reservation_id`; não recebe chave, caminho, URI ou prompt. `generate_clips` recebe somente `analysis_id` e `reservation_id`. Os handlers exigem exatamente essas chaves, inteiros positivos reais e vínculo do projeto com análise, origem e reserva antes de qualquer efeito. O vínculo da reserva inclui ID, usuário, projeto, operação, unidades e hash derivado de `userId:ai:analyze:{projectId}:{promptVersion}`; outra reserva do mesmo projeto não é intercambiável.

O baseline da Fase 2 já fornece `JobOutcome::deferred()` com atraso de 5–300 segundos, `JobRepository::defer()` protegido pelo lease e o contador separado no relatório. Ele agenda `status=retry`, limpa lease e erro e executa `attempts = GREATEST(0, attempts - 1)`, sendo usado para espera externa normal, checkpoints intermediários e fencing de persistência sem consumir tentativa. Cada claim executa no máximo uma operação lógica remota (`upload`, `getFile`, `generate` ou `deleteFile`), e a configuração exige `lease_seconds >= http_timeout_seconds + 30`. As chamadas de rede ficam fora de transações MySQL; um preflight fenceado ocorre antes e o checkpoint é novamente fenceado depois. Um fence perdido ou uma falha tipada de checkpoint retorna `deferred` mesmo na última tentativa, pois nenhum job pode terminalizar deixando crédito reservado sem reconciliação. Timeout, rate limit ou indisponibilidade real do provedor continuam consumindo tentativa e seguem o backoff normal.

## Fluxos

### Início automático

1. O FFprobe persiste metadados e origem `ready`.
2. `AiPipelineStarter`, dentro do efeito de conclusão protegido pelo lease e compartilhando o mesmo PDO, cria/recupera análise e reserva créditos.
3. Com saldo, despacha `analyze_video` e grava projeto `ai_queued`.
4. Sem saldo, grava `awaiting_credits`; ingestão permanece íntegra e nenhum job de IA nasce.

### `analyze_video`

1. Valida o payload exato e carrega análise, origem e reserva pelos IDs internos; resultado já validado segue para limpeza idempotente e estado concluído retorna sucesso.
2. Sem arquivo remoto, faz preflight fenceado, marca `uploading`, envia por streaming fora da transação, persiste o arquivo em novo checkpoint fenceado e retorna `deferred`.
3. Com arquivo `PROCESSING`, faz uma consulta remota, persiste o novo estado sob fence e retorna `deferred`.
4. Com arquivo `FAILED`, reembolsa primeiro e falha análise/projeto dentro do mesmo efeito fenceado.
5. Com arquivo `ACTIVE`, faz uma única geração na claim. Depois da resposta, incrementa `validation_attempts` duravelmente e valida localmente dentro do checkpoint.
6. A primeira resposta inválida persiste a tentativa e retorna `deferred`; a segunda falha e reembolsa. Exceções do provedor não incrementam esse contador.
7. Se válido, persiste resumo/JSON aprovado, despacha `generate_clips` e marca `identifying_clips` atomicamente.
8. Uma claim posterior executa somente `deleteFile`; falha dessa limpeza é ignorada de forma sanitizada e não altera o resultado do negócio.

### `generate_clips`

1. Valida os vínculos internos sem lock e entra no efeito protegido pelo lease.
2. Pré-valida o JSON sem efeitos; dentro do fence consome a reserva primeiro, exige estado `consumed`, bloqueia/recarrega a análise e valida novamente. Qualquer divergência lança e reverte integralmente o consumo antes de um fluxo separado de reembolso/falha.
3. Cria/recupera sugestões por índice e verifica conflito de conteúdo em replays.
4. Marca análise `completed` e projeto `suggestions_ready/100` no mesmo efeito; o worker conclui o job logo depois por CAS de lease.
5. Repetição após interrupção não duplica clipe nem crédito.

## Interface e autorização

- A biblioteca mostra etapas `IA na fila`, `Enviando para análise`, `Preparando IA`, `Analisando conteúdo`, `Identificando melhores momentos`, `Sugestões prontas`, `Créditos insuficientes` e `Falha`.
- `GET /projetos/{id}` exige proprietário e mostra resumo e cards de sugestão.
- Cada card exibe título, timestamps, duração, score 0–100, gancho, motivo e categoria; informa que o score é estimativa de IA, não garantia de viralização.
- O status JSON adiciona apenas `analysis_status`, `suggestions_count` e `suggestions_url`; nunca inclui referência Gemini, resposta validada integral, reserva ou saldo interno.
- Antes de `suggestions_ready`, a página mostra o estágio persistido e permite atualização/polling; sem JavaScript, refresh normal continua útil.
- A UI não oferece download, player de clipe ou botão “renderizado”, porque nenhum arquivo final existe nesta fase.

## Falhas, privacidade e observabilidade

Erros transitórios: `ai_rate_limited`, `ai_timeout`, `ai_unavailable`. Erros permanentes: `ai_unconfigured`, `ai_provider_rejected`, `ai_file_failed`, `ai_response_invalid`, `analysis_not_found`, `insufficient_credits`. No transporte, 408 vira `ai_timeout`, 429 vira `ai_rate_limited`, todo outro 4xx vira erro permanente, todo 5xx/rede vira `ai_unavailable`, e DELETE 404 é apenas limpeza idempotente. Todos usam mensagens públicas allowlisted. Logs têm `project_id`, `analysis_id`, `job_id`, estágio, tentativa, HTTP status class e request ID sanitizado; nunca chave, Authorization, URL com query, upload URL, URI, caminho, prompt, vídeo ou resposta bruta.

O checker informa `WARN` para chave/modelo ausentes e recomenda worker VPS quando cURL, tempo máximo ou largura de banda do plano não suportarem o upload. A aplicação web não cai. O runbook explica revogação de chave, cron, custo, privacidade do envio ao provedor, retenção temporária do Files API e migração do worker para VPS.

## Critérios de aceite

- Nenhuma rota ou asset contém `GEMINI_API_KEY`; testes e checker não fazem chamadas reais.
- Upload Gemini transmite o arquivo privado sem carregar todo conteúdo em memória e rejeita upload URL fora de HTTPS/hosts permitidos.
- Estado `PROCESSING` adia o job e mantém `attempts` inalterado após a transição; erros reais continuam consumindo tentativas.
- Somente resposta aprovada pelo schema e pelo validador local cria sugestões.
- Timestamps obedecem à duração FFprobe, score fica em 0–100 e strings respeitam limites.
- Repetir análise, geração de clipes, consumo ou reembolso não duplica linhas nem altera saldo duas vezes.
- Dois starters concorrentes não reservam mais créditos que o saldo disponível.
- Falha terminal antes do consumo devolve os créditos uma única vez; sucesso consome exatamente a reserva.
- Projeto alheio ou inexistente retorna o mesmo 404 e nenhuma resposta pública revela campos Gemini ou financeiros internos.
- A biblioteca e a tela de sugestões exibem estados reais em desktop/mobile e funcionam sem JavaScript.
- Worker Hostinger continua finito; o mesmo pipeline pode ser executado em VPS sem alteração de controller ou schema público.
- Nenhum arquivo de vídeo final é alegado ou disponibilizado nesta fase.

## Sequência de entrega

A Tarefa 1 estabiliza schema, configurações e contratos. Depois de aprovada, Tarefas 2, 3 e 4 são independentes e podem executar em paralelo. A Tarefa 5 integra os três resultados à fila e à conclusão do FFprobe. A Tarefa 6 compõe rotas, projeção pública, interface e operação.

Sequência: `Task 1` → paralelo `Tasks 2 + 3 + 4` → `Task 5` → `Task 6`.
