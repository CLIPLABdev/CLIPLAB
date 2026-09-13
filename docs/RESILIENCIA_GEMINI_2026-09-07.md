# Correção da análise Gemini e validação real

## Resultado

Projeto **2016**, importado pela interface com `https://www.youtube.com/watch?v=O1FZD5Zove0`, concluiu a análise e exportou três cortes reais com legendas em 2026-09-07. O HTTP 503 do provedor não foi eliminado: o fluxo passou a tratar e permitir recuperar falhas temporárias com limites e segurança financeira.

Não existe garantia de disponibilidade permanente de uma API externa. A implementação não inventa respostas, não troca de provedor/modelo e não inicia tentativas ilimitadas.

## Diagnóstico

- Importação original: fonte 1053 pronta, 230.758.706 bytes, 1280×720, aproximadamente 65:32 e áudio presente.
- Falha anterior confirmada em diagnóstico separado: geração HTTP 503, cURL errno 0; consulta do arquivo HTTP 200/ACTIVE.
- Verificação de contexto: endpoint do modelo retornou limite de entrada 1.048.576 tokens; contagem do vídeo retornou 405.126. Não houve evidência de excesso de contexto. Uma primeira contagem retornou 400 sem detalhe persistido; a repetição posterior retornou 200, portanto não atribuímos causa à primeira resposta.
- Na execução recuperada, o próprio worker registrou duas falhas HTTP 503 na etapa generate. A tentativa seguinte produziu resposta válida, quatro sugestões e geração automática dos três melhores candidatos.

## Mudanças

1. Tentativas de análise com orçamento próprio: padrão seis, teto oito, configurável por `GEMINI_ANALYSIS_MAX_ATTEMPTS`. Orçamento dos demais tipos de jobs preservado.
2. Espera exponencial com variação aleatória e respeito a Retry-After válido, limitada a 900 segundos. Agendamento na fila, sem bloquear o servidor web dormindo.
3. Jobs antigos preservam seu limite ao serem reencontrados por uma chave idempotente; recuperação explícita usa o novo orçamento.
4. Diagnósticos internos permitem somente campos numéricos e códigos enumerados: HTTP, erro cURL, fase, IDs e tentativas. Não salvam chaves, URLs assinadas, corpos de resposta, prompts ou mensagens arbitrárias.
5. Botão **Tentar análise novamente** para falhas transitórias elegíveis, usando o POST autenticado/CSRF existente. Valida dono, fonte, estado terminal, ausência de resultado/clips e estorno esperado.
6. Recuperação preserva análise/job/reserva e histórico financeiro. Acrescenta débito após estorno, com compare-and-set e bloqueio transacional; token antigo não pode debitar outro ciclo. Não reimporta o YouTube. Reenvia a fonte local para Gemini para não depender de referência remota expirada.
7. Biblioteca, detalhe e polling indicam **Aguardando nova tentativa** quando o job de análise atual está em retry transitório, incluindo próxima tentativa/limite e horário UTC validados. Os estados persistidos e o contrato do endpoint não mudam.

## Execução real e finanças

Reserva 39: débito original 1306 de 66 créditos, estorno 1307, novo débito 1308 na recuperação, consumo após resposta válida. Nenhum segundo débito na execução recuperada e nenhum novo ajuste administrativo. Custos/cota externos da API não foram contabilizados pelo teste.

A recuperação real ocorreu às 18:41:41 UTC (15:41:41 São Paulo). Quando o script de clique do assistente abriu a página, ela já estava processando; o script não encontrou o botão e não submeteu POST. O histórico confirma a recuperação, mas não identifica seu autor. Os testes dos agentes foram auditados e utilizam SQLite/doubles, sem acesso ao projeto real. Não afirmamos ter observado o clique que iniciou este ciclo.

Depois disso, o assistente acompanhou a biblioteca no navegador: Renderizando → Concluído às 18:50:27 UTC. Análise 779 e job 1613 concluídos; reserva consumida. Duas transcrições receberam limite temporário de uso e concluíram nas novas tentativas existentes, sem contornar a exigência de legendas.

## Arquivos validados pela interface

| Corte | Duração | MP4 bytes | Legendas SRT | Resultado |
|---|---:|---:|---:|---|
| 69 | 66 s | 5.688.465 | 13 blocos | Download, editor, reprodução e decode completo OK |
| 70 | 73 s | 7.053.831 | 27 blocos | Download, editor, reprodução e decode completo OK |
| 72 | 65 s | 6.834.039 | 7 blocos | Download, editor, reprodução e decode completo OK |

Todos: 1280×720, H.264/AAC, idioma de legenda pt-BR. A sugestão 71 não foi exportada automaticamente, pois o limite é três; não é um quarto MP4 ausente por falha.

Baixados MP4 e SRT pelos botões reais, abertas as transcrições no editor e reproduzidos os MP4 no Chrome. FFmpeg decodificou todos os streams com saída zero. Timestamps SRT dentro da duração de cada corte. Frames extraídos dos MP4 mostraram legendas visíveis e legíveis.

A conferência não equivale a revisão palavra a palavra, sincronização humana integral ou avaliação editorial de todos os trechos. Em particular, o SRT do corte 69 termina em 48,5 s de um vídeo de 66 s; a cobertura de fala nesse intervalo final não foi auditada e não é apresentada como perfeita. Não foram testados novos formatos/resoluções nesta execução.

## Testes e evidências

- TDD de diagnóstico: três testes reproduziram ausência de status/registro seguro; quatro casos de transporte reproduziram perda do código cURL/origem interna. Após correção: 86 testes/326 assertions passaram.
- Integração focada de análise, fila e diagnóstico: 138 testes/623 assertions passaram.
- Recuperação/interface: execução independente de 43 testes/293 assertions passou.
- Complemento final de mensagem de espera: 48 testes/322 assertions passaram na execução independente; revisão estática adicional aprovada. Não iniciamos uma nova análise somente para exibir a mensagem de espera.
- Regressão Unit disponível: 1.655 testes/6.505 assertions, dois skipped, zero falhas/erros. MediaPipeConsentServiceTest excluída por depender de banco de testes dedicado não configurado.
- Revisão independente aprovou recuperação financeira, tentativas e telemetria. Concorrência exercitada em SQLite; não prometemos cobertura de concorrência MariaDB real.

Evidências privadas em `.superpowers/sdd/2026-09-07-ai-resilience/` e `.superpowers/sdd/2026-09-07-url-interface/`: `recovery-audit.json`, `video-context.json`, `database-evidence.json`, `ui-progress.json`, `browser-clips-results.json`, `media-validation.json`, screenshots e downloads. Sessão/cookies não devem ser publicados.

Código salvo em `C:\Users\Acer\Desktop\video`. Localhost mantido em porta 8093. Não houve deploy na Hostinger nem regeneração do ZIP antigo nesta etapa.

## Referência oficial

[Google — estratégia de novas tentativas](https://ai.google.dev/gemini-api/docs/troubleshooting): recomenda espera exponencial, variação aleatória, tratamento restrito a erros transitórios e número máximo de tentativas. Essa estratégia aumenta a tolerância a falhas, mas não garante disponibilidade contínua do serviço.
