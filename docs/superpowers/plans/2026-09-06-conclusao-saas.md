# Conclusão integral do SaaS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Concluir administração, planos/limites, legendas/editor e preparação/homologação da plataforma completa.
**Architecture:** Quatro frentes com arquivos exclusivos, integradas por root aos pontos compartilhados existentes. Persistência no MySQL e processamento em jobs finitos.
**Tech Stack:** PHP 8.0+, PDO/MySQL/MariaDB, JS puro, FFmpeg, Gemini, PHPUnit/Playwright.
**Spec:** docs/superpowers/specs/2026-09-06-conclusao-saas-design.md

## Global Constraints
- PHP 8.0+, sem Node em produção, sem processo permanente/Redis/Docker obrigatório.
- Testes apenas em cliplab_phase5_test; nenhuma leitura/exposição de .env ou segredo, nem provider real durante tests.
- Root integra routes/web.php, app/Views/layouts/app.php, bootstrap/worker e configurações comuns. Cada implementador altera só seus paths. Nenhum staging concorrente.
- CSRF, autorização por sessão e role consultada no banco; HTML escapado e projeção allowlist. Nenhuma exclusão de dados para resolver quota.
- Continuar até todas as tasks; não encerrar após módulo individual. Publicação/accessos externos são gate separado.

### Task 1: Administração funcional e configuração segura
**Files:** criar routes/admin.php; app/Middleware/AdminMiddleware.php; app/Controllers/AdminController.php; app/Repositories/AdminRepository.php, SystemLogRepository.php; app/Services/AdminService.php, AdminGeminiSettingsService.php; app/Security/SecretCipher.php; app/Views/admin/{dashboard,users,projects,jobs,logs,plans,gemini}.php e layouts/admin.php; public/assets/css/admin.css; bin/create-admin.php; migrations 202609060020+; tests/{Unit,Feature,Integration}/Admin*Test.php e SecretCipherTest.php. Logger DB integration patch deve ser comunicado ao root antes de tocar app/Core/Logger.php.
**Interfaces:** routes/admin.php usa $router existente e cria seus factories lazy. AdminGeminiSettingsService::effective():array devolve mesmo shape de config/gemini.php; construtor PDO,array baseline,string encryptionKey. PlanLimits validator da Task2 é fonte única para features. Métodos administrativos operam com actorId validado e transação.
- [x] Test-first: guest302, user403, admin200, CSRF419; SQL injection filtro cai no default; fixture foreign segredos não aparecem. Exemplo `self::assertSame(403, $router->dispatch(Request::fake('GET','/admin'))->status());` com sessão user real.
- [x] Criar consultas reais paginadas e operações de estado/plano/créditos com locks/ledger/motivo. Testar duas requisições concorrentes, saldo negativo, autossuspensão, último admin e inválidos.
- [x] Criptografar override da chave com OpenSSL AES-256-GCM ou sodium autenticado, nonce novo e contexto fixo; alteração do ciphertext deve falhar. `self::assertSame($plain,$cipher->decrypt($cipher->encrypt($plain)));` e assert tamper rejeitado. Não incluir plaintext em exceção.
- [x] Implementar UI/máscara/config efetiva/teste de conexão sintético por POST rate-limited. CLI sem senha em argv ou default. Não chamar Gemini real nos tests.
- [x] Testar banco/migrations (twice + malformed partial), gates e relatório task-1-report.md; entregar paths/assinaturas de integração. Commit exato ao sinal root.

### Task 2: Conta, planos e limites concorrentes
**Files:** criar routes/account.php; app/Plans/PlanLimits.php; app/Controllers/AccountController.php; app/Services/PlanQuotaService.php; app/Repositories/AccountRepository.php; app/Views/account/{plan,credits}.php; public/assets/css/account.css; migrations202609060010+; tests/{Unit,Feature,Integration}/{PlanLimits,PlanQuota,Account}*Test.php. Alterar seeds/plans.sql, ProjectIntakeService/UploadLimits/AiPipelineStarter/CreditReservationService somente quando necessário e relatado; não tocar ClipRenderHandler/novo editor (root).
**Interfaces:** PlanLimits::fromFeatures(array $features):self e ::toArray():array aceitam exatamente schema da spec. PlanQuotaService(PDO)::snapshotForUser(int $userId):array e ::assertAdditionalStorageAvailable(int $userId,int $bytes):void; segundo roda sob transação/lock do usuário que protege o commit da persistência. Exceção pública específica, não PDO para usuário. Root usa no render publish.
- [x] RED para parser exato/features inválidas/limites; `PlanLimits::fromFeatures(['limits'=>['max_upload_bytes'=>-1]])` rejeita, defaults preservam plano legado sem apagar features suportadas.
- [x] Implementar snapshot real de créditos/minutos do mês/storage; impedir self-upgrade pago e self-credit. Mostrar planos reais e ledger25/página com filtros allowlist/no-store.
- [x] Aplicar menor limite upload global/PHP/plano e quota/minutos com locks para upload e URL; não permitir race de duas admissões no último saldo/minuto/espaço. Test DB real com dois owners e two connections; rollback mantém saldo e reserva intactos.
- [x] Preservar retry/refund/fencing; validar análise sem saldo permanece bloqueada. Ajustar seeds em modo não destrutivo para deployments novos, migração/normalização para planos legados explícita.
- [x] Rodar gates focados e report task-2-report.md com contratos de integração e quota no publish. Commit exato ao sinal root.

### Task 3: Pacote, readiness e acabamento de acesso
**Files:** criar bin/build-release.php, bin/check-production.php, bin/smoke-http.php, app/Deployment/{ReleaseBuilder,ProductionReadiness,HttpSmoke}.php; tests/{Unit,Feature}/Deployment*Test.php; modificar docs/HOSTINGER.md apenas seção própria ao final coordenado; telas auth e layouts/marketing.php para acessibilidade/assets. Não alterar routes/web.php, app layout, worker/configs compartilhadas. Arquivos legais/privacidade informativa separados se necessários, com integração root.
**Interfaces:** CLI build-release recebe --output com arquivo novo fora de public/storage, usa allowlist spec e manifest hashes. CLI readiness recebe --role=all-in-one|web|worker, exit0 pronto/1bloqueado/2uso. Web não afirma que worker externo está homologado. smoke-http --base-url HTTPS somente GET allowlist sem credenciais/produção mutação.
- [x] RED pacote fixture com .env canário, logs, mídia e symlink externo; `self::assertFalse($zip->locateName('.env'));` e assert vendor/dotfiles/licenças/manifest presentes e hashes corretos; path traversal/reuso de output rejeitados.
- [x] Implementar pacote sem seguir links e sem writes na origem; sem erro expor segredo. Testar duas builds mesmas entradas com manifesto equivalente. Não gerar pacote real até root concluir módulos.
- [x] Implementar readiness explícito que falha sem configurações/capacidade exigidas por papel; preservar checkerdev atual. Testes com ambiente controlado sem .env. Script smoke verifica headers, inacessibilidade de .env/storage/app e auth endpoints sem cadastrar/enviar nada.
- [x] Associar erros de auth a inputs e foco, preservar validações/actions existentes; self-host Bootstrap/licença se ainda CDN. Verificar UI320/768/1440 sem alterar branding existente. Landing somente revisada após editor para evitar promessas falsas.
- [x] Relatório task-3-report.md com blockers reais de deploy e gates; commit ao sinal root.

### Task 4: Legendas, transcrição e editor de versões
**Files:** criar app/Media/Subtitles/{SubtitleCue,Transcript,TranscriptValidator,SrtCodec,AssDocumentBuilder}.php; app/Media/Editor/EditorOptions.php; app/Media/LocalFfmpegAudioExtractor.php; app/Contracts/TimedTranscriptionProvider.php; app/Services/GeminiTimedTranscriber.php,ClipEditService.php; app/Repositories/ClipEditorRepository.php; app/Queue/GenerateSubtitlesHandler.php; app/Controllers/ClipEditorController.php; routes/editor.php; app/Views/clips/editor.php; public/assets/{css/clip-editor.css,js/clip-editor.js}; migrations202609060001..0003; tests cobrindo esses componentes. Modificar RenderClipRequest/LocalFfmpegClipRenderer/RenderClipHandler e worker via root.
**Interfaces:** TimedTranscriptionProvider::transcribe(string $wavBytes,int $durationMs):Transcript. EditorOptions::fromArray(array):self normaliza allowlist da spec. RenderClipRequest adiciona ?EditorOptions e ?Transcript ao final, defaults preservam renders anteriores. TranscriptValidator::fromArray(array,int $durationMs):Transcript. SrtCodec::parse(string,int):Transcript e ::format(Transcript):string.
- [x] RED domain/SRT/ASS: bounds, UTF-8, XSS/ASS injection, timestamps invertidos/overlap/fora do corte, words inválidas e textos longos. Exemplo cue0..1000 com texto `{\\pos(1,1)}Olá` não injeta tag ASS executável; SRT roundtrip preserva texto e limites.
- [x] Implementar extração WAV limitada privada e transcriber com inlineData/mime audio/wav, schema JSON, statusHTTP mapeado/sem leaks. Testar request com fake transport e WAV sintético; sem chamar chave real.
- [x] Persistir editor snapshot, tracks e cues em migrations idempotentes; versão nova preserva original e tem request_key idempotente. Validar owner/current analysis/interval/profile e transação completa antes de job.
- [x] Implementar generate_subtitles com lease guard, retry limitado/terminal amigável, snapshot e dispatch render atômico; job obsoleto não altera clipe. Render aplica reframe antes ASS, cleanup e quota real antes publicar.
- [x] Implementar editor GET/POST autenticado/CSRF, fonte privada por gesto, timeline/intervalo/ratio/foco atuais, 6 estilos/posição/título/marca, revisão SRT, export SRT privado. Render completo funciona sem aba aberta; campos e links básicos semJS. Nova versão de completed não remove vídeo anterior.
- [x] Gates unit/DB/concurrency + FFmpeg real comparando vídeo sem e com legenda/título/marca, browser320/768/1440, foreign404, pipeline fakeIA->render->download. Relatório task-4-report.md e commit revisado.

### Task 5: Integração e homologação completa

- [x] Complemento do fluxo original: opção persistida de exportar até três melhores cortes automaticamente após análise; default apenas em novos formulários, legado desativado; scheduler idempotente e com jobs finitos, sem segundo débito de créditos. Root implementa após liberar paths de intake da Task2.

**Files:** routes/web.php, app/Views/layouts/app.php, bin/process-jobs.php, config/gemini.php e configurações necessárias, README.md, docs/HOSTINGER.md, testes browser/workflow finais, docs/DELIVERY.md.
**Interfaces:** módulos1..4 usam rotas existentes $router e assinaturas registradas nos reports; nenhum novo entrypoint público sem auth/CSRF.
- [x] Integrar factories e navegação: /admin, /conta/plano, /conta/creditos, /clips/{id}/editar; perfil admin não depende de input cliente. Workers usam configuração Gemini efetiva e registry generate_subtitles.
- [ ] Rodar migrations somente no DBtest primeiro; conjunto Unit/Feature/Integration e Chrome real sequencial. Corrigir falhas reais e revisar diffs sem reiniciar revisões amplas já concluídas. **Parcial:** Unit+Feature 1.259/5.811 com 1 skip e integração selecionada não destrutiva 153/1.486 ficaram verdes; seis schemas foram verificados em leitura. O gate DDL destrutivo não foi autorizado.
- [x] Validar localhost com conta de demonstração existente; novos dadosdemo apenas separados e explicitamente rotulados. Exibir novas rotas sem abrir abas duplicadas repetidamente.
- [x] Fazer teste IA real mínimo sem mídia pessoal somente com configuração autorizada e saída sanitizada; registrar resultado/erro real sem expor chave. O GET de modelos retornou HTTP 200; a geração terminou indisponível com HTTP 503 após dois timeouts.
- [x] Gerar pacote allowlisted e verificar conteúdo/hash; documentar cron/SMTP/HTTPS/private root/fonts/libass/backup/rollback e bootstrapadmin. ZIP final verificado fora do worktree: 302 arquivos, 7.653.431 bytes; manifesto e conteúdo conferidos, sem segredos, testes, dependências de desenvolvimento, `.env` ou backups.
- [ ] Homologar Hostinger se acesso/plano/domínio disponíveis; caso contrário concluir todo trabalho local possível e registrar exatamente credenciais/capacidade faltantes. Nunca afirmar SaaS inteiro publicado/testado apenas porque localhost funciona. **Pendente:** domínio, plano e acesso não fornecidos; nenhum deploy de produção foi realizado.
