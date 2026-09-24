# Homologação com vídeo oficial — aceite final pendente

Fonte obrigatória: https://www.youtube.com/watch?v=7YC9tf-qmmw

O usuário confirmou autorização para processar o vídeo. Projeto 2011 criado pelo formulário real, com solicitação de até três exportações automáticas. Nenhum outro vídeo substitui essa homologação.

## Resumo atualizado em 07/09/2026

O fluxo local real importou o vídeo oficial, concluiu a análise Gemini, gerou cinco cortes originais, produziu versões legendadas nos três formatos Full HD, salvou templates/brand kit, gerou capas e exportou metadados para publicação. Downloads e arquivos foram verificados. A correção de duração no fim da fonte também foi validada pela interface e pelo MP4 final, sem alterar créditos ou apagar versões anteriores.

O aceite completo continua pendente: ainda falta auditoria ouvindo o áudio, revisão semântica de todos os cortes, avaliação do reenquadramento entre participantes e implantação/homologação na Hostinger. Não há publicação social direta, cobrança integrada ou alinhamento por palavra comprovados. Testes com fixtures são identificados separadamente e não substituem o vídeo oficial.

## Gerador e importação

- Metadados: “1 SOLTEIRO vs 20 CASADAS | ft. Zago”, público, não-live, duração aproximada de 34min33s.
- O formulário criou o projeto corretamente. O primeiro runner de teste esperava o detalhe, mas o redirecionamento correto é para a lista; não era falha de criação.
- Job 1564 esgotou três tentativas de metadados por timeout. Comparação isolada: caminho padrão excedeu 90s; IPv4 completou em aproximadamente 19–27s. Corrigido com opção configurável YTDLP_FORCE_IPV4, padrão true.
- Conta demo local 122 recebeu o plano Pro existente via formulário administrativo, sem cobrança, para comportar o arquivo acima de 100 MB e o vídeo acima de 30 minutos. Os limites globais dos planos não foram alterados.
- Busca administrativa retornava erro PDO HY093 por parâmetros nomeados repetidos com prepares nativos. Quatro consultas corrigidas; regressão MySQL isolada: 4 testes/12 assertions; busca real passou a HTTP 200.
- Job 1565 foi criado para a retomada, preservando o histórico terminal 1564. A primeira tentativa encontrou download lento: 11.993.008 bytes em 30s, aproximadamente 400 KB/s.
- A transferência YouTube passou a usar blocos sequenciais de até 10 MiB, com validação estrita de Content-Range, tamanho, total, pinning DNS, TLS, quotas e prazo agregado. Medições reais de dois blocos: 0,881s e 0,978s. Referência técnica: https://raw.githubusercontent.com/yt-dlp/yt-dlp/master/yt_dlp/downloader/http.py
- A retomada do worker retornou: claimed=1, completed=1, failed=0, operational_errors=0.
- Arquivo real: storage/media/imports/2011/20ae92aa08bd7f25425266c7ca0ed117.mp4.
- FFprobe independente confirmou 450.411.539 bytes, 2072,938231 segundos, vídeo H.264 1920×1080 e áudio AAC.
- A decodificação integral de vídeo e áudio com FFmpeg também terminou com código 0 (`FULL_SOURCE_DECODE_OK`). Isso verifica a integridade de leitura da fonte, não a qualidade de cortes ainda não gerados.
- Na retomada de 07/09, a análise Gemini 776 do projeto 2011 foi concluída com `gemini-3.1-flash-lite`, após duas respostas `ai_unavailable` e uma terceira tentativa válida. Job 1566: `completed`, três tentativas; job 1567 (`generate_clips`): `completed`.
- Sugestões 47–51 geradas; os três cortes selecionados para exportação automática, 47, 50 e 51, foram renderizados. O último job, 1570, terminou com worker `claimed=1, completed=1, failed=0, operational_errors=0`.
- Na consolidação posterior, os cinco cortes originais 47–51 estão `completed`. A tabela abaixo registra arquivos H.264 1920×1080/AAC; FFprobe e decode integral de cada corte terminaram com código 0. Evidências locais identificadas pelo prefixo `official-clip*`.

| Clipe | Tamanho (bytes) | Duração medida (s) | Intervalo esperado (s) |
| --- | ---: | ---: | ---: |
| 47 | 12.246.393 | 45,011634 | 45,010 |
| 48 | 9.210.136 | 41,4414 | 41,430 |
| 49 | 11.580.605 | 50,3503 | 50,320 |
| 50 | 11.670.270 | 49,482767 | 49,450 |
| 51 | 11.562.963 | 60,4604 | 60,430 |

- `silencedetect` com limiar −45 dB e duração mínima de 0,75 s não detectou trechos nos cinco arquivos. Essa métrica não comprova fala contínua, contexto ou qualidade semântica. A inspeção da folha de frames real do clipe 50 confirmou conteúdo de debate, sem validar semanticamente todas as falas.
- Verificados posteriormente os três formatos abaixo, **sem legendas**, todos com duração medida de 49,482767 s e decode concluído com código 0. Eles comprovam dimensões e leitura desses arquivos, não exportações legendadas.

| Versão | Formato | Dimensões | Tamanho (bytes) |
| --- | --- | --- | ---: |
| 54 | Vertical, sem legendas | 1080×1920 | 11.892.424 |
| 55 | Quadrado, sem legendas | 1080×1080 | 8.759.391 |
| 56 | Horizontal, sem legendas | 1920×1080 | 11.790.925 |

- As versões legendadas 57–59 foram concluídas posteriormente, conforme a seção abaixo. As correções de margens e sobreposição passaram em novas versões 63/64; a precisão de EOF passou no novo corte 65. Ainda pendentes: auditoria ouvindo o áudio, sincronização percebida e qualidade/contexto de todos os cortes. Jobs ou arquivos concluídos não equivalem a homologação concluída.

## Editor, legendas e identidade

- Implementados formatos novos Full HD, preservação de perfis antigos 720, campos tipados, estilos, CTA e logo privado.
- Edição da transcrição preserva tempos de palavras somente quando existe correspondência válida; não fabrica alinhamento.
- Editor visual com timeline de legendas, seek real, recorte explícito, canvas aproximado, formatos e biblioteca. Não é um editor multipista.
- Recursos condicionais fora do aceite desta rodada: remoção automática de silêncios/pausas, autozoom e alternância automática entre participantes não foram homologados. A medição silencedetect não remove conteúdo. O Smart Reframe existente e seus testes históricos não comprovam detecção/rastreamento facial no vídeo oficial; os recortes centrais observados ainda perdem participantes.
- Biblioteca e kit de marca possuem persistência por usuário e validação de PNG privado. Quotas incluem logos e novas thumbnails.
- Teste real no navegador separado: logo 1 salvo (656 bytes), template 1 “Homologação oficial — Viral” salvo e reaberto, kit persistido e template aplicado no editor. O clipe anterior 45 foi usado somente para verificar aplicação visual, sem exportação; isso não substitui o vídeo oficial.
- Rotas /templates, /marca, /api/editor-library, /clips/45/capas e /clips/45/publicacao responderam HTTP 200 após integração.
- Revisão independente identificou risco de memória com logos extremos. Corrigido por dimensão limitada nos dois eixos, no renderizador, thumbnail e prévia. Regressões de mídia: 26 testes/184 assertions; correção aprovada pelo revisor.
- Revisão identificou perda de rascunho em erros de formulários da biblioteca/publicação. Corrigido: rascunhos, thumbnail, ID de edição e versão enviada permanecem nos erros 422/429/409. Root repetiu 31 testes/194 assertions (SQLite/puro) e a revisão independente aprovou a correção; nenhum teste acessou o MariaDB.
- Layout real do editor do clipe 50, estúdio de capas e publicação verificado em 320/360/768/1440 px, sem overflow horizontal ou erros JavaScript observados. A prévia oficial informou 2072,938 s, 1920×1080, `readyState=4` e seek em 1372,56 s. Isso verifica carregamento e navegação da fonte, não legendas prontas.
- A versão 52 com legendas falhou localmente porque o transporte comparava o corpo enviado com o teto de resposta de 1 MiB: WAV de 1.582.478 bytes tornou-se base64 de 2.109.972 bytes e era recusado antes de inicializar cURL. Corrigido com teto de envio de 10 MiB independente do teto de resposta de 1 MiB, mantido nos callbacks. Root repetiu 98 testes/395 assertions focados; revisão independente aprovou o fix.
- A versão 53 falhou com HTTP 400 `INVALID_ARGUMENT` genérico. O diagnóstico autorizado levou à remoção somente de `cues.maxItems=500` e `words.maxItems=150` do schema enviado ao Gemini. A validação local original e os limites de resposta permanecem; não houve relaxamento da aceitação local de cues, palavras, tempos ou texto.
- O experimento com o payload ajustado retornou HTTP 200/STOP e uma transcrição `pt` com 26 cues e zero tempos por palavra, aceita pelo `TranscriptValidator` original. Essa evidência confirma o experimento de transcrição, não alinhamento por palavra nem um MP4 legendado.
- A versão automática 57 saiu da fila e está `completed`, assim como as versões 58 e 59. Os históricos de falha 52 e 53 permanecem. Jobs registrados nesta etapa: 1585 `completed`, duas tentativas; 1586 `completed`, uma tentativa.

| Versão concluída | Legendas | Dimensões | Tamanho (bytes) |
| --- | --- | --- | ---: |
| 57 | Automática, karaoke com fallback por frase | 1080×1920 | 13.273.843 |
| 58 | Manual, minimal | 1080×1080 | 10.093.804 |
| 59 | Manual, viral | 1920×1080 | 13.170.538 |

- Os três arquivos têm vídeo H.264 High, yuv420p, 29,97 fps, 1.483 frames e duração de 49,482767 s; áudio AAC LC estéreo, 44,1 kHz, 49,45 s. Decode integral retornou código 0, com logs vazios. Os três downloads reais retornaram HTTP 200, e tamanhos/hashes coincidiram com `official-clips-57-58-59-validation.json`. FFprobe e decode verificam características técnicas e leitura dos arquivos; não comprovam qualidade percebida do áudio nem sincronização audiovisual ou das legendas.
- Transcrição da versão 57: `pt`, 26 cues entre 0 e 48 s para intervalo original de 49,45 s, zero tempos por palavra. As cues são por frase e usam marcações grosseiras em segundos inteiros. O estilo karaoke opera com fallback por frase; não há prova de alinhamento por palavra.
- Revisão quantitativa em `official-caption-readability-review.json`: 26 cues, 1.048 caracteres; a primeira cue começa com o fragmento “é mais que o homem.”. Seis cues excedem 30 caracteres por segundo: 6=50; 9=33; 10=40,5; 15=31; 20=35; 26=35. Esse limiar é uma heurística de QA, não um padrão externo nem prova de erro de sincronização; sinaliza trechos densos para revisão humana. Não foi fabricado retiming por palavra.
- Teste real de cue no editor 57: seek absoluto em 1375,56 s correto; edição de pontuação alterou o SRT do rascunho, mantendo os timestamps e 26 cues, sem erros JavaScript. A pontuação modificada não foi exportada. A demonstração posterior de trim manual com início +3 s e SRT reajustado está registrada na versão 60 abaixo; não comprova seleção automática perfeita.
- Editor 57 com as 26 cues verificado em 320/360/768/1440 px, sem overflow horizontal e com zero erros de página. Evidência: `official-editor-captions-layouts.json`.
- Inspeção visual encontrou perdas de enquadramento: na versão 57 vertical, o recorte aos 15 s corta participantes da composição dividida; na 58 quadrada, corta a pessoa à direita aos 20 s; na 59 horizontal, ambas aparecem. Não foi feita auditoria ouvindo o áudio nem revisão semântica integral dos cinco cortes originais. A homologação permanece aberta.
- Versão 60 vertical: intervalo manual 1375,56–1422,01 s, 25 cues com timestamps reajustados em −3 s, 1080×1920, 12.786.153 bytes; duração de vídeo 46,479767 s e áudio 46,45 s; decode código 0. Demonstra trim e reajuste de SRT, não alinhamento por palavra ou seleção automática perfeita.
- Versão 61 em 4:5: intervalo original, 26 cues, 1080×1350, 11.194.252 bytes; duração de vídeo 49,482767 s e áudio 49,45 s; decode código 0.
- Os insets portrait de 10% no topo, 18% na base e 6% nas laterais afastaram as legendas das bordas. Root encontrou colisão do logo com o título em 60/61; a correção passou a reservar a largura máxima do logo mais um espaçamento na banda/canto correspondente, somente em retrato com logo. ASS e prévia usam área útil assimétrica; middle, CTA, sem logo e formatos não retrato foram preservados. Revisão independente: 87 testes/308 assertions e Node passando; implementador: 93 testes PHP/461 assertions, incluindo FFmpeg sintético. Root repetiu MediaHomologationTest 21/113 e o teste Node.
- Validação real posterior: versão 63 (derivada de 60) 1080×1920, 12.806.686 bytes, vídeo 46,479767 s/áudio 46,45 s; versão 64 (derivada de 61) 1080×1350, 11.179.253 bytes, vídeo 49,482767 s/áudio 49,45 s. Ambas passaram em FFprobe, decode integral e downloads HTTP 200. Root inspecionou 63 aos 5 s e 64 aos 15 s: título e logo separados, texto legível e legendas afastadas do rodapé. Isso não garante qualquer interface social nem resolve o reenquadramento de participantes ou colisões verticais arbitrárias.
- A versão 63 baixou SRT de 1.954 bytes/25 cues, começando pela pergunta em 0–2 s; a 64 baixou 2.009 bytes/26 cues. Evidências: official-verified-clips-63-64.json e official-final-layout-downloads.json. As versões 60/61 foram preservadas.

## Thumbnails e publicação

- Candidatos de frames reais por contraste/nitidez e distribuição temporal; não há alegação de análise facial ou emocional.
- Templates clean, bold e split; texto e logo; JPEG 1280×720. Os testes do módulo usam fontes sintéticas declaradas, não contam como homologação oficial.
- Preparação local de metadados, vínculo da thumbnail, versões e histórico. Nenhuma postagem direta em redes sociais foi realizada ou declarada.
- Root integrou os handlers, callbacks de propriedade e quota. Corrigido também o catálogo de erros e a identidade dos novos jobs na observabilidade: 10 testes/35 assertions.
- Evidência real do clipe 50: conjunto 1 gerou cinco candidatos, IDs 1–5, nos offsets 9,272; 18,544; 24,725; 33,997; 46,359 s.
- Designs reais: 6 Clean (102.331 bytes), 7 Bold (89.436 bytes) e 8 Split (81.186 bytes), todos JPEG 1280×720; downloads 6–8 responderam HTTP 200. O design 8 apresentou quebra ruim de palavra e permanece no histórico.
- Corrigido o ajuste do título: reduzir fonte antes de quebrar palavra, piso 32, reflow quando as quebras excedem a altura disponível e recusa antes do FFmpeg se ainda não couber. Root repetiu 16 testes/106 assertions e integração FFmpeg sintética isolada de 3 testes/28 assertions; revisão independente aprovou. O rerender real 9 Split tem 77.818 bytes, 1280×720; root inspecionou a palavra “Fidelidade” íntegra. A variante 8 não foi apagada ou sobrescrita.
- Publicação local 1 criada pelo formulário real para clipe 50, thumbnail 7, plataforma YouTube: `draft` v1 → `ready` v2 → `exported` v3, com três entradas de histórico. Pacote JSON de 697 bytes e download TXT HTTP 200; vínculos e downloads autenticados do proprietário confirmados. `publication_confirmation=not_confirmed`; nenhuma postagem em rede foi realizada. A evidência cobre essa preparação, não todos os estados e plataformas.
- Nos testes de acesso, downloads, source-preview, thumbnail 7 e publicação 1 redirecionaram visitantes anônimos ao login. A conta administrativa de outro proprietário recebeu HTTP 404 nos recursos privados testados; o papel administrativo não liberou esses arquivos de outra conta.
- No clipe 57, o conjunto 2 está pronto com cinco candidatos, IDs 10–14. O design 15 Split está pronto no offset 9,272 s; root inspecionou o JPEG 1280×720 legível, com apresentador visível e quadro diferente do primeiro frame.
- Publicação local 2 vinculada ao clipe 57 e thumbnail 15, plataforma Shorts: em `ready` v2, os downloads JSON de 742 bytes e TXT de 605 bytes responderam HTTP 200. Depois foi registrada como `exported` v3, com `publication_confirmation=not_confirmed`; o novo JSON está na pasta privada de evidências como `publication-2-exported.json`. Não houve postagem real em rede social.
- A versão revisada 63 tem capa 16 Split, selecionada no offset relativo 6,272 s, JPEG 1280×720 de 77.640 bytes. Download HTTP 200; imagem inspecionada com título íntegro, apresentador visível e logo separado. A publicação 3 vincula clipe 63/capa 16/Shorts, com histórico draft→ready→exported (v3). JSON final de 765 bytes e TXT baixados; `publication_confirmation=not_confirmed` e links autenticados. Evidências: `official-final-publication.json`, `official-eof-final-downloads.json` e `publication-3-exported.json/.txt`.

## Incidente do banco e estado atual

Um teste adicional de recuperação em cliplab_phase5_test executou CREATE TEMPORARY TABLE ... LIKE clip_render_profiles e bloqueou internamente o MariaDB 10.4.32, afetando também leituras de cliplab. O teste foi removido e as conexões de teste receberam cancelamento; a operação continuou como Killed/Creating table.

O desligamento gracioso foi solicitado, mas inicialmente o processo local mysqld PID 6640 permaneceu presente. A tentativa posterior de encerramento forçado foi rejeitada pela auto-review por risco de perda/corrupção. Essa chamada rejeitada não executou encerramento nem cópia. Posteriormente, duas verificações confirmaram que o processo já havia encerrado, sem intervenção forçada, e a porta 3306 estava livre.

Antes de iniciar novamente, foi feita uma cópia fria de `C:/xampp/mysql/data` para a pasta privada e ignorada pelo Git `.superpowers/sdd/2026-09-06-media-homologation/mysql-cold-backup-20260907-normal-stop`. Origem e cópia apresentaram 2.076 arquivos e 239.801.146 bytes. Trata-se de preservação dos arquivos do incidente; não é uma garantia de backup lógico íntegro.

A inicialização normal às 23:44 de 06/09 iniciou o PID 16708, executou recuperação automática InnoDB/Aria e abortou com `Fatal error: Can't open and lock privilege tables: Incorrect file format 'db'`. O processo não permaneceu ativo. Verificação offline somente da cópia, com `aria_chk --check --read-only --skip-update-state`, retornou que `mysql/db.MAI` não é uma tabela Aria reconhecível. Até esse diagnóstico, nenhum reparo, substituição de tabela, reset de permissões ou desativação de autenticação havia sido realizado. Não há evidência suficiente para atribuir a corrupção dessa tabela a uma única causa.

A autorização específica para recuperar a tabela interna de permissões foi apresentada ao usuário, que respondeu “sim” nesta retomada. A espera por autorização foi resolvida. A [documentação oficial de aria_chk](https://mariadb.com/docs/server/clients-and-utilities/aria-clients-and-utilities/aria_chk) orienta usar esse utilitário com o servidor parado; o diagnóstico anterior foi executado apenas na cópia fria.

Após a autorização, o clone `mysql-repair-rehearsal-20260907` recebeu um ensaio offline em bootstrap com `REPAIR TABLE db USE_FRM`, que recuperou quatro linhas. Uma cópia adicional, `mysql-db-before-authorized-repair`, preservou `db.frm`, `db.MAI` e `db.MAD` antes de alterar o original. Essas cópias preservam os arquivos disponíveis; não constituem garantia de integridade lógica completa. Referência: [documentação oficial de REPAIR TABLE](https://mariadb.com/docs/server/reference/sql-statements/table-statements/repair-table).

O original foi reparado offline em 07/09 às 00:02:48 pelo mesmo procedimento, sem reset de grants. O mysqld iniciou normalmente às 00:02:50, PID 14996, com bind em `127.0.0.1`. `CHECK TABLE mysql.db` retornou OK; `mysqlcheck --check --quick cliplab` retornou OK para todas as tabelas verificadas. Uma conta desconhecida foi negada com erro 1045. São verificações de estrutura e acesso, não uma demonstração de integridade lógica de todos os dados ou de todas as funcionalidades.

O servidor PHP web-only permaneceu separado, PID 13084, supervisor 22516, porta 8093. No Chrome separado, o login da conta demo chegou ao dashboard; projeto 2011, `/templates`, `/marca` e `/clips` responderam HTTP 200, sem erros JavaScript observados. O usuário havia relatado `ERR_CONNECTION_REFUSED` no navegador interno, cuja inspeção falhou por ACL; o fluxo posterior no Chrome não constitui nova verificação do navegador interno do usuário.

Na verificação mais recente, o runtime local usa supervisor com worker PID 10700 e PHP PID 19180, porta 8093, com resposta HTTP 200. O MariaDB PID 14996 permanece intacto; essa atualização de runtime não envolve nova recuperação do banco.

O banco está novamente disponível e o pipeline oficial foi retomado. Novos testes MySQL de schema continuam suspensos; o teste que travou o servidor não deve ser repetido. A recuperação autorizada não encerra a homologação nem autoriza outras operações destrutivas.

Os logins repetidos de QA atingiram o limite de cinco tentativas por 900 segundos. A janela foi aguardada e terminou às 03:34:59 UTC; a sessão privada passou a ser reutilizada. Não houve exposição de sessão em conteúdo público.

## Upload positivo e cenários negativos HTTP reais

- Upload positivo criou o projeto 2012 com trecho real do clipe 47; o probe da fonte concluiu. A análise 777 falhou com `ai_response_invalid`, duas tentativas de validação e uma tentativa de job. A resposta bruta não foi armazenada, e a causa específica ainda não foi capturada. Os créditos foram restituídos; o saldo observado da conta demo após a restituição foi 64. Upload/probe concluídos não significam análise completa.
- Para observar novas rejeições sem expor conteúdo, foi aprovado por root e revisão independente o callback de diagnóstico `ai.validation_rejected`: somente `reason_code` do enum existente e quatro inteiros (`job_id`, `project_id`, `analysis_id`, `validation_attempt`). O registro é best-effort e observacional, não contador de tentativas duráveis ou confirmação de checkpoint; falha do callback não muda validação, reembolso, replay ou limite normal de duas gerações. Nenhuma resposta bruta, URL, áudio, chave ou mensagem de exceção é registrada pelo novo evento.
- Um novo upload real do mesmo trecho 47 criou o projeto 2013 pelo formulário, HTTP 302, com exportação automática habilitada. A análise 778 concluiu na terceira tentativa de job, depois de duas ai_unavailable, com uma resposta validada; não houve reason_code de rejeição. Probe 1593, análise 1594, geração 1595 e render 1596 concluíram. A reserva 37 de 2012 está refunded (1 unidade), e a 38 de 2013 consumed (1 unidade). O resultado não explica retroativamente a resposta inválida de 2012.
- O clipe 62 do upload revelou um defeito de precisão: fonte real 45,011634 s, metadado contábil ceil=46 e sugestão/render pedido 0–46. O MP4 tem 11.743.697 bytes e duração 45,011634 s (vídeo 44,978267 s/áudio 45,01 s), decode 0; ele ficou cerca de 0,988 s abaixo do intervalo pedido. Não é aprovação da duração. A correção sem DDL separa precisão técnica de ceil contábil, faz preflight fora de transações e prevê validação antes de publicar o arquivo.
- Correção de precisão implementada: parser decimal separa ceil contábil de milissegundos técnicos; preflight mede fora da transação e revalida identidade/propriedade sob lock. Pedidos manuais além do limite são recusados; o autoexport limita somente o intervalo técnico à fonte, preservando a sugestão original. FFprobe valida vídeo e container antes da publicação, com tolerância de dois frames limitada a 0,05–0,15 s; arquivos materialmente curtos ou longos são recusados e os temporários limpos. Não houve DDL nem mudança da regra de créditos.
- Root repetiu oito arquivos de testes de serviços/parser (68 testes/352 assertions, puro/SQLite) e três arquivos unitários de pós-render (59/216). Integração FFmpeg isolada: 3/108, incluindo EOF fracionário e decode. O preflight real mediu a fonte 1050 em 45.011 ms, limite 45.011 s, fora da transação; outro proprietário foi recusado. O auto-cap tem cobertura isolada; não foi criada outra análise Gemini somente para repetir esse caso.
- Teste real no editor 62: pedido 0–46 retornou HTTP 422 com `Limite: 45.011 s.`, sem criar corte. Pedido corrigido 0–45.011 retornou HTTP 302 e criou o corte 65, agora completed. MP4 de 11.743.697 bytes, H.264 1920×1080/AAC, container 45,011634 s, vídeo 44,978267 s e áudio 45,010 s. Diferença do container para o intervalo pedido: +0,000634 s; vídeo dentro de um frame. Decode integral retornou 0 e download HTTP 200 com SHA-256 `4ce60ad5f95496ce568f516398c2a74c3bc5b1209afe6fe7187e262a208ce268`. O histórico 62 foi preservado. Evidências: `official-eof-negative.json`, `official-eof-corrected-submission.json`, `official-verified-clips-65.json` e `official-eof-final-downloads.json`.

- URL inválida, YouTube sem autorização de direitos, arquivo com extensão `.exe` e MP4 vazio foram rejeitados pelo fluxo HTTP real.
- A consulta de controle encontrou zero projetos com nome `QA negativo %` para o usuário 122; esses pedidos negativos não criaram projetos.
- A mensagem genérica de MP4 vazio foi corrigida para orientar a seleção de outro arquivo válido. Root repetiu o teste HTTP real: redirecionamento 302 ao formulário, orientação exibida e zero projetos criados. O teste de controller usa arquivo realmente vazio e o validador real; root repetiu 8 testes/26 assertions e a revisão independente aprovou a correção. A causa da resposta inválida histórica de 2012 não foi reproduzida; o novo upload 2013 concluiu análise e exportação, com a duração corrigida na versão 65.

## Regressões independentes do banco na retomada

- Regressão Unit: 1.152 testes, 3.894 assertions, zero falhas e um teste ignorado. Excluídos explicitamente `MediaPipeConsentServiceTest` (MySQL) e os dois arquivos de schema em edição concorrente. Não é o resultado de toda a suíte do sistema.
- Após as correções da revisão P2, root repetiu `SaasMigrationSchemaTest` (78/191), `MediaMigrationGuardTest` (5/18) e `MigratorTest` (4/9): 87 testes e 218 assertions passaram com fixtures SQLite de metadados. Os sete contratos completos agora recusam colunas extras que tornariam INSERTs normais inválidos; verificações parciais antigas permanecem compatíveis. O valor literal de default `NULL` é diferenciado dos metadados SQL NULL conforme MySQL/MariaDB. A revisão independente final aprovou a correção, sem achados restantes nesse escopo.
- Root repetiu também `FormRecoveryTest`: seis testes e 89 assertions passaram, sem banco externo.
- Preview de legendas/composição passou. Os runners de reenquadramento inicialmente foram chamados sem seu argumento obrigatório; reexecutados corretamente: 25 testes do editor e oito do rastreador passaram.
- Chrome headless, fixture isolada sem banco/provedor: 13 testes do editor passaram, incluindo 320/360/768/1440 px, teclado, preservação de rascunho, biblioteca, prévia real da fixture e SRT. Esses testes usam vídeo sintético declarado e não substituem a homologação oficial.
- Revisão independente aprovou os ajustes de download YouTube e supervisor, sem bloqueantes. Registrou apenas uma lacuna não bloqueante: falta fixture com handle herdado por mais de cinco segundos e duas iterações para verificar a limpeza adiada do supervisor.
- Uma nova tentativa de teste Unit amplo foi rejeitada pela revisão automática por possível acesso MySQL e não foi executada. Na etapa posterior foram executados apenas testes focados sem banco externo; os resultados específicos acima não representam uma suíte integrada completa.
- Repetições focadas do root: `ClipEditorControllerTest` 13 testes/109 assertions, `PublicationMetadataTest` 3/4, `GeminiTimedTranscriberTest` 10/53 e `CurlGeminiTransportTest` 30/131, todos passando. `SubtitleDomain` havia passado com 40 testes/50 assertions. Estes resultados precedem os ajustes portrait e não são evidência de sua correção visual.
- Observabilidade de análise aprovada por root e revisão independente: 25 testes/152 assertions, sendo `AnalyzeVideoHandlerTest` 22/130 com fakes e `AdminRepositoryTest` 3/22 com SQLite em memória. Cobrem saneamento, duas rejeições, replay/sucesso sem evento, callback lançando erro e observação sem checkpoint após perda de lease. Não foram executados testes MySQL.

## Preparação do pacote de entrega

O gerador de release excluía indevidamente o namespace app/Storage. A correção preserva somente exceções de código explícitas (app/Storage e namespaces PSR Log conhecidos), continuando a excluir dados temporários, backups, arquivos secretos e árvores de desenvolvimento. A primeira tentativa de liberar nomes em app/vendor de forma ampla foi recusada pela revisão independente e corrigida; a versão restrita foi aprovada. Root repetiu Builder 2 testes/9 assertions e Hardening 7 testes/108 assertions, com um skip de symlink por restrição do host.

Uma staging nova, privada e separada foi preparada com a allowlist e Composer 2.10.3 verificado pelo SHA-256 oficial, usando --no-dev --no-scripts --no-plugins --optimize-autoloader. Dependências de produção: packages vazio, 13 arquivos de autoload/vendor; PHPUnit ausente. O autoload foi validado pelo implementador e pelo root para Storage, SourceDurationPreflight, RenderedDurationValidator, OverlaySafeZone e ReleaseBuilder. Nenhum banco, mídia, .env, backup, sessão ou dependência de testes deve acompanhar o ZIP. O ambiente ativo e seu vendor de desenvolvimento foram preservados. Esse gate de empacotamento não é um teste do servidor Hostinger. [Distribuição oficial do Composer](https://getcomposer.org/download/).

## Produção

Os testes locais usaram PHP 8.0.30; isso não aprova esse runtime para produção. O guia Hostinger agora exige uma versão mantida (PHP 8.4 como referência atual) e reteste no host escolhido. [Versões suportadas do PHP](https://www.php.net/supported-versions.php).

Verificação local final do editor 63: 320/360/768/1440 px sem overflow horizontal e zero erros JavaScript. O vídeo usa carregamento explícito: antes de clicar em Abrir prévia, readyState=0 é esperado. Depois do clique, readyState=4, fonte 1920×1080/2072,938231 s, seek correto em 1375,56 s e reprodução pausada. Isso foi testado no Chrome separado; o pedido para abrir no navegador interno retornou queued e não confirma que a aba foi exibida ao usuário.

Ainda não houve implantação na Hostinger nem teste no VPS final, HTTPS público, entrega SMTP ou backup/restauração de produção. OAuth social e pagamentos não foram testados nem declarados operacionais; planos administrados e exportação local de pacotes não equivalem a cobrança integrada ou postagem em rede.

Novo requisito operacional: o POST autenticado de edição/exportação executa FFprobe antes da transação. Portanto, o processo web também precisa de proc_open, FFprobe configurado e acesso aos mesmos arquivos privados do worker. Um worker externo sozinho não supre essa dependência. GET não dispara essa inspeção; falha de precisão recusa o envio, sem fallback para o ceil contábil.

Conforme a fonte oficial registrada em `docs/HOSTINGER.md` (consultada em 06/09/2026), o processamento com FFmpeg na Hostinger exige VPS: Web/Cloud não oferece esse suporte. Um worker separado precisa compartilhar o mesmo banco e os mesmos bytes em storage privado com a aplicação web. [Suporte oficial a mídia e FFmpeg](https://www.hostinger.com/support/which-media-compression-and-web-applications-are-supported-at-hostinger/).

O resultado `ready: true` de `check-production.php` atesta somente capacidades verificadas do host (`scope: host_capabilities`), não certifica o fluxo completo: `end_to_end_verified` permanece `false`. Não foi executado um gate no host de produção nesta homologação; implantação, operação e aceite qualitativo continuam separados dos resultados locais.

## Próximas verificações obrigatórias

1. Auditar os resultados ouvindo o áudio: precisão das legendas, sincronização percebida, contexto e qualidade dos cinco cortes originais. Preservar a distinção entre cues por frase e alinhamento por palavra.
2. Revisar as perdas de participantes nos recortes vertical/quadrado. Margens e colisão título/logo foram corrigidas e verificadas em 63/64, sem certificar reenquadramento automático ou interfaces sociais universais.
3. Ampliar a matriz de importação/interrupções e executar regressões de banco somente em ambiente descartável e autorizado. A precisão EOF foi corrigida e validada em 65; a causa histórica da resposta inválida 777/2012 não foi reproduzida.
4. Preservar o histórico das variantes e a distinção entre exportar pacote e publicar em rede. As publicações 1, 2 e 3 têm registro local de exportação; OAuth e publicação externa exigem configuração e autorização de contas.
5. Completar as regressões autorizadas e o relatório final, mantendo suspensos os novos testes MySQL de schema. Implantação Hostinger/produção permanece pendente e separada. Não declarar o produto homologado ou pronto para produção antes disso.
