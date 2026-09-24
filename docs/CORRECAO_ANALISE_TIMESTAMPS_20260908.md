# Correção da análise de cortes — 08/09/2026

## Diagnóstico dos projetos demo

- Projeto 2018, `rafaaa`: URL YouTube `avITvJUuiL4`, fonte importada e pronta, 631 segundos e 51.313.760 bytes. As duas respostas anteriores foram rejeitadas por `invalid_timeline`.
- Projeto 2017, `testeds`: URL YouTube `0mNITl6Eesg`, fonte importada e pronta, 2.592 segundos e 492.353.913 bytes. Respostas anteriores rejeitadas por `invalid_timeline` e `invalid_duration`.
- Reservas 41 e 40 foram estornadas na falha original. Importação não foi o estágio que falhou.
- Os logs retêm motivos enumerados, não a resposta inválida integral. Não é possível afirmar os valores numéricos exatos das respostas anteriores. Uma chamada diagnóstica adicional retornou HTTP 503; ela não substitui nem explica as rejeições numéricas originais.

## Lacunas corrigidas

1. O schema usava limites genéricos de 24 horas e duração mínima zero, mesmo para fontes curtas. O worker agora envia limites derivados da duração da fonte. Vídeos de até 20 segundos aceitam somente uma janela completa no schema.
2. O prompt explicita segundos absolutos e `duration = end_time - start_time`, limites de texto e seleção de um trecho autossuficiente quando um momento é longo demais.
3. A segunda tentativa recebe uma instrução de revisão; não apenas repete o pedido original. A resposta anterior não é interpolada no prompt, evitando transmitir conteúdo não confiável como instrução.
4. Falhas `ai_response_invalid` podem ser recuperadas explicitamente pelo botão existente, com proprietário, estorno atual, token, transação e bloqueio contra duplicação. Cada recuperação reinicia o orçamento limitado de validação; não cria análises ou débitos duplicados por repetição do mesmo POST.
5. O validador estrito não foi relaxado: duração, limites da fonte, duplicatas e demais regras continuam obrigatórios. Sem resposta válida não são criados cortes.

O contrato JSON e a identidade `viral-clips-v1` foram preservados para compatibilidade com jobs e projetos existentes. Não houve troca automática de modelo, provedor ou migração de banco.

## Testes desta correção

- Cinco testes novos falharam antes da implementação pelos motivos esperados.
- Focados após correção: 59 testes, 410 assertions, zero falhas. Revisão independente repetiu essa execução com SQLite e provider falso, sem acessar conta demo, Gemini ou worker.
- Unit disponível: 1.665 testes, 6.565 assertions, dois skipped e zero falhas/erros. `MediaPipeConsentServiceTest` excluída por exigir banco dedicado.
- Feature focados: oito testes, 46 assertions, zero falhas.
- Sintaxe PHP validada nos três arquivos alterados.

## Homologação real em andamento

Os projetos 2018 e 2017 foram recuperados pelo botão real da interface às 14:57:45 e 14:57:48 UTC, respectivamente, com POST HTTP 302 e mensagem de análise solicitada. A importação existente foi reutilizada; houve novo envio da fonte privada ao Gemini, conforme o fluxo de recuperação.

Nas duas primeiras tentativas do vídeo maior ocorreu timeout de upload (`curl_errno=28`, `ai_timeout`), tratado pelo limite de novas tentativas existente. No projeto 2018, o novo arquivo ficou ativo, mas a primeira geração retornou HTTP 503. Esses eventos são separados da rejeição original de timestamps; não houve ainda resposta válida que permita afirmar sucesso real dos dois projetos nesta atualização.

Esta seção será complementada com o resultado efetivamente observado. Testes locais não garantem disponibilidade permanente do Gemini nem qualidade editorial integral das sugestões. Evidências privadas ficam em `.superpowers/sdd/2026-09-08-invalid-windows/`, fora dos ZIPs de implantação.

### Atualização às 15:11 UTC

O upload do projeto 2017 concluiu na terceira tentativa. No projeto 2018, uma resposta com as instruções atualizadas ainda foi rejeitada por `invalid_duration`; ela não gerou cortes. A tentativa de revisão encontrou novos HTTP 503. Portanto, o fortalecimento do fluxo passou nos testes automatizados, mas NÃO está confirmado como solução definitiva para esses dois vídeos.

O usuário informou mensagem de limite de taxa no Gemini. As últimas consultas locais mostram HTTP 503 e timeouts, não um HTTP 429 que confirme qual cota desse projeto foi excedida. Não foi ativado faturamento, trocado modelo/chave nem iniciada nova análise diagnóstica após essa informação. Os jobs já retomados mantêm suas tentativas limitadas na fila existente.

Novo pacote de código: `dist/cliplab-correcao-analise-20260908.zip`, 8.095.203 bytes; 490 arquivos conferidos contra manifesto e fonte atual. SHA-256: `f4932789b9d1436bb41366ade73157831b23755034aafdcd070f2a62a14a7f72`. Exclui configuração real, banco, mídias e segredos locais. O ZIP anterior foi preservado. Este é um pacote com ajustes do fluxo, não uma homologação integral concluída.
