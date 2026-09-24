# Conclusão do ClipLab — escopo restante aprovado

Data: 2026-09-06. Base: c7c2c4f. Fonte: especificação completa fornecida pelo usuário em 2026-09-03 e ordem explícita de concluir todas as tarefas em 2026-09-06.

## Objetivo e processo
Entregar todos os recursos ainda faltantes, integrados à aplicação existente, sem encerrar a execução após cada módulo. Os módulos já verificados (auth, projetos, ingestão, fila, Gemini/análise, cortes, download, MediaPipe, Smart Reframe e biblioteca) são preservados. A autorização global anterior dispensa novas aprovações de desenho dentro deste escopo; credenciais faltantes, publicação externa e operações destrutivas continuam exigindo contexto e autoridade concretos.

Frentes independentes: administração; planos/créditos/limites; preparação de publicação; legendas/editor. A integração e homologação encerram o conjunto. Cada frente possui paths exclusivos; root integra routes/web.php, layout/app.php, bootstrap/worker, config compartilhada e documentação geral. Não tocar no checkout antigo Desktop/video.

## Restrições globais
- PHP 8.0+, PDO/MySQL 8.0.16+ ou MariaDB 10.4+, JavaScript puro; Node apenas em ferramentas de desenvolvimento.
- Hospedagem web compartilhada, cron e workers finitos. FFmpeg/FFprobe/proc_open no host de processamento; não presumir capacidade na Hostinger sem verificar.
- Prepared statements, escape HTML, CSRF em toda mutação e autorização server-side a cada requisição. Nenhuma confiança em role/user_id enviado pelo cliente.
- Mídia privada fora de public. Nenhuma chave/timestamp de sessão/path privado/stack trace em HTML, JSON público ou logs.
- Testes com rede fake e somente banco cliplab_phase5_test; DB tests coordenados pelo root, nunca simultâneos a outro gate destrutivo. Preserve dados de demonstração 8093.
- Staging e commits exatos coordenados pelo root. Nenhum push, merge ou deploy automático durante implementação.

## Administração
Rotas /admin protegidas por sessão ativa e role=admin consultada no banco, independentemente da navegação. Área dark separada, responsiva, com dashboard real e tabelas paginadas de usuários, projetos/vídeos, jobs e erros. Filtros allowlist e 25 itens/página, sem carregar payloads/mídias privadas. Usuários comuns recebem 403; guests são redirecionados.

Operações: suspender/reativar usuários, atribuir plano ativo e ajustar créditos com motivo obrigatório; não oferecer exclusão em massa nem promoção de role pelo formulário. Ajustes são transacionais, bloqueiam usuário e escrevem ledger, nunca permitem saldo negativo ou overflow. Não permitir autossuspensão nem remover o último admin ativo. CLI cria/promove explicitamente admin com senha segura sem password em argv, sem credencial padrão.

Planos são editáveis por admin: nome/preço/minutos/créditos/ativo/features validados. Sem checkout ou cobrança real (explicitamente futura no escopo original). Não desativar o plano Free usado por cadastro sem substituto compatível.

system_logs armazena nível/evento/mensagem pública/contexto allowlist e data. Logger técnico atual continua funcionando, com persistência DB best-effort para não derrubar requests por falha de logging. Listagem mostra somente dados sanitizados. Auditoria das mutações registra ator/alvo/operação, sem segredo.

Configuração Gemini: modelo e chave configuráveis por admin; a chave nunca é lida de volta no formulário. Override de chave é criptografado autenticadamente com chave mestra APP_ENCRYPTION_KEY fora do banco. Sem mestra válida, bloquear gravação do segredo com mensagem clara; configuração por ambiente continua suportada. Uma factory fornece a configuração efetiva ao worker e ao teste de conexão. Teste de conexão somente por POST+CSRF e rate limit, com prompt sintético mínimo, sem vídeo do usuário e sem exibir resposta técnica/segredo.

## Planos, créditos e limites
Preservar seeds comerciais atuais. Schema de features: {exports_hd:bool,priority_processing:bool,team_access:bool,limits:{max_upload_bytes:int,storage_bytes:int}}. Sem chaves extras; bytes positivos. Defaults: Free 100 MiB/upload e 1 GiB/storage; Pro 500 MiB/20 GiB; Business 500 MiB/100 GiB. Defaults são configuração inicial ajustável pelo admin, não cobrança externa.

Página autenticada /conta/plano mostra plano real, preço, recursos, créditos, consumo mensal e espaço; /conta/creditos mostra ledger paginado. Usuário não pode se conceder créditos nem ativar plano pago sozinho. A seleção pode registrar solicitação ao administrador sem afirmar pagamento realizado.

Limite efetivo de upload é min(plano, limite global, PHP). Verificar upload/URL e consumo de minutos sob transação/lock para concorrência; apenas impedir novo consumo, nunca apagar arquivos de conta acima da quota. Reservas/créditos existentes mantêm idempotência, refund e fencing. Contabilizar bytes realmente persistidos; root aplica verificação de espaço também ao publicar renders.

## Legendas e editor
Editor dedicado por clipe, reaproveitando prévia privada e reenquadramento atual. Permitir intervalo, proporção, foco/Smart Reframe, estilo e posição da legenda, título e marca textual. Estilos: none, minimal, viral, podcast, highlight, karaoke, custom. Campos custom allowlist (cor #RRGGBB, tamanho limitado, posição top/middle/bottom); nenhuma string de filtro ou comando aceita do cliente.

Transcrição usa áudio apenas do intervalo escolhido, extraído privadamente pelo FFmpeg para WAV mono 16 kHz PCM16 até 180 s e 6 MiB. Gemini recebe áudio inline pelo endpoint generateContent existente (sem criar Files API adicionais). O adapter usa transport/config compartilhados e limita tempo/bytes/resposta, sem mudar a integração de análise atual.

Contrato de transcrição: idioma e lista de cues com start_ms/end_ms/text/words; tempos relativos ao corte, limites 0..duration_ms, ordem e não sobreposição, máximo 500 cues/6000 palavras, texto UTF-8 limitado. Words são opcionais: highlight/karaoke usam tempos reais quando disponíveis; na ausência, apresentar segmento inteiro sem inventar timestamps por palavra. Usuário pode revisar/editar SRT antes de exportar; falha de IA nunca vira texto fictício.

Geração ocorre em job generate_subtitles idempotente/fenced; persiste cues antes de despachar render_clip na mesma transação. Cada render usa snapshot do estilo/texto/transcrição. Nenhuma dependência de aba aberta para transcrição ou render. Prévia visual pode ocorrer no navegador. Não cobrar uma segunda reserva de análise por render; consumo da IA de legendas é incluído no recurso do plano e protegido pelos limites de uso.

ASS é gerado internamente para legendas, título e marca, com escaping de texto e nomes/caminhos de fontes controlados. FFmpeg recebe argv, filtro construído apenas por código, temporários privados e cleanup garantido. Reenquadramento é aplicado antes do ASS, respeitando dimensões do vídeo final.

Edição de um clipe concluído deve preservar o arquivo anterior: gerar uma nova versão/novo clipe vinculado ao original, com chave de idempotência, em vez de sobrescrever o MP4. Editar tempos invalida transcrição incompatível. Ownership/análise atual são rechecados nos jobs. Download SRT privado disponível quando há transcrição pronta.

## Publicação e qualidade final
Pacote reproduzível com allowlist: app/bootstrap/config/routes/public/database migrations+seeds/bin/vendor, arquivos de entrada/Apache, composer.lock e licenças. Excluir .env real, storage, testes, node_modules, caches, logs, fixtures, Git e .superpowers. Não seguir symlinks para fora da origem. Manifest SHA-256 por arquivo e teste de ausência de canários secretos.

Modo de readiness explícito all-in-one versus web+worker. Checker normal de desenvolvimento mantém compatibilidade; gate de produção não pode afirmar plataforma operacional sem IA e capacidade de processamento verificadas. Smoke automatizado sem mutações para HTTPS/headers/rotas privadas; fluxo com usuário/mídia de teste exige contexto explícito e limpeza somente dos próprios IDs.

Interface mantém identidade atual, estados reais, 320/768/1440, navegação por teclado, no-JS nos formulários e mensagens associadas a campos. Recursos de landing devem corresponder ao implementado. Documentar privacidade operacional do envio ao Gemini, sem alegar aprovação jurídica; identificação do operador/domínio depende dos dados da Hostinger.

## Aceite final
Todos os módulos do escopo com backend/banco/validação/erro/segurança persistentes; unit/feature/integration + browser/FFmpeg reais e revisão de código. Localhost demonstra fluxo completo. Artefato limpo produzido. Teste Gemini real e homologação Hostinger registrados separadamente: se faltar credencial/acesso/capacidade, explicar exatamente o bloqueio, sem marcar o projeto inteiro concluído.

## Fontes técnicas consultadas
- https://ai.google.dev/api/generate-content — generateContent, inlineData e responseFormat.
- https://ai.google.dev/gemini-api/docs/audio — transcrição e referências temporais; não garante alinhamento perfeito por palavra.
# Complemento: entrega automática dos vídeos

A especificação original encerra com “vai me entregar o vídeo automático”. Para cumprir esse fluxo sem transformar cada sugestão em uma aprovação manual obrigatória, novos projetos oferecem opção explícita, inicialmente selecionada, de renderizar automaticamente até três melhores sugestões após a análise. Projetos existentes mantêm opção desativada na migração. O processamento usa os mesmos jobs, snapshots, limites e armazenamento privado; falha de render não apaga a análise nem o original. O usuário ainda pode gerar versões adicionais no editor. Cobrança continua vinculada à análise, sem segundo débito por render.
