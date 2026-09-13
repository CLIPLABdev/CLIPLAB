# Homologação pela interface — URL do YouTube

**Atualização posterior:** a análise foi recuperada e o projeto 2016 concluiu três exportações reais com legendas, downloads e reprodução verificados. Consulte [o relatório da correção](RESILIENCIA_GEMINI_2026-09-07.md). O relato abaixo preserva a execução anterior, que falhou; não deve ser interpretado como o estado atual do projeto.

Vídeo: https://www.youtube.com/watch?v=O1FZD5Zove0.
Conta: Demo ClipForge. Projeto criado pela interface: **2016**, nome **Homologação pela interface — YouTube O1FZD5Zove0**.

## Etapas observadas

1. Sessão demo aberta no navegador real, sem mocks e sem inserir projeto diretamente no banco.
2. Formulário `/projetos/novo`: nome preenchido, aba Importar link, URL colada, confirmação de direitos e geração automática marcadas.
3. Clique real em Criar projeto → POST HTTP 302 → `/projetos?created=1` → aviso de projeto enviado e card Na Fila.
4. Card do projeto 2016 passou para Baixando (20%).
5. Card passou para Aguardando Créditos (75%), mostrando duração 65:32 (arredondamento de contabilização) e tamanho 220,1 MB.

## Bloqueio encontrado e corrigido

Conta demo com 63 créditos; vídeo exige 66 no custo de um crédito/minuto arredondado para cima. O sistema interrompe explicitamente antes da análise e informa na tela que é necessário adicionar créditos. Não é falha silenciosa, nem prova de geração completa.

Autorização solicitada ao usuário para adicionar somente os créditos de teste necessários na conta local, sem compra/cobrança. Até esta etapa o saldo não foi alterado por este teste.

Após confirmação explícita “sim”, foram adicionados exatamente 3 créditos via serviço administrativo existente, com lançamento no extrato e log. Saldo confirmado: 66. Nenhuma compra/cobrança. A operação tem marcador específico para impedir repetição acidental no script de homologação.

O teste revelou uma falha real de retomada: com saldo suficiente, o projeto permanecia aguardando créditos e a interface não oferecia nenhuma ação para iniciar a análise. Corrigido com botão e POST autenticado, CSRF e validação de dono, reaproveitando reserva e fila idempotentes, sem reimportar nem forçar estados pelo banco. Também corrigido o conflito de identidade quando o modelo configurado muda durante a espera por créditos.

Às 18:21:20 UTC (15:21:20 de São Paulo), clique real em **Retomar análise** no projeto 2016 retornou HTTP 302 e feedback “Análise solicitada”. O projeto avançou para fila de IA, envio (80%), preparação (82%) e análise (88%). Fonte 1053 e análise 779 foram reutilizadas; job 1613 criado e reserva única 39 de 66 créditos. Nenhum novo download.

Verificação independente da correção: 74 testes/262 assertions, dos quais 71 passaram e três testes MySQL foram pulados por ausência de TEST_DB_DSN. Concorrência foi testada sobre SQLite isolado, não MySQL. Revisão independente sem problemas pendentes.

## Resultado final desta execução

**Homologação ponta a ponta não aprovada.** Importação por URL e retomada pela interface funcionaram; a análise falhou antes de gerar qualquer corte.

- Às 18:27:30 UTC (15:27:30 de São Paulo), a biblioteca mostrou **Falhou — O serviço de IA está temporariamente indisponível**.
- Job 1613 encerrou após três tentativas efetivas com `ai_unavailable`; análise 779 com zero respostas submetidas à validação.
- Reserva 39 foi estornada automaticamente. Consulta posterior confirmou saldo demo **66**, sem novo ajuste manual e sem cobrança do aplicativo por análise concluída. Isso não equivale a comprovação de ausência de consumo/custo na API externa.
- Diagnóstico separado, limitado a uma chamada adicional sobre o mesmo arquivo: GET do arquivo Gemini HTTP 200, estado ACTIVE; geração com o mesmo serviço/configuração/esquema retornou **HTTP 503**, cURL errno 0, em 23,45 segundos. Registro às 18:30:50 UTC (15:30:50 de São Paulo). Nenhuma alteração do projeto ou saldo por esse diagnóstico.
- O diagnóstico confirma falha HTTP do provedor nessa chamada. As três tentativas anteriores guardaram somente o código genérico, portanto não é possível provar retroativamente o status HTTP individual de cada uma.
- Fonte importada preservada: 230.758.706 bytes, 1280×720, áudio presente, duração contabilizada 3.932 segundos.
- **Zero cortes e zero legendas gerados neste projeto.** Não foram executados downloads/reprodução/validação de MP4 finais, porque não existem arquivos de cortes para validar. Os scripts preparados para essas etapas não constituem prova de sucesso.

Pendente após normalização do provedor: análise → seleção → transcrição → render com legendas → abrir/download/reproduzir e validar arquivos. Não alteramos artificialmente o status do projeto nem inserimos resultados de diagnóstico no banco. O botão implementado cobre espera por créditos; não habilita reprocessamento de projetos que já falharam.

Além dos testes focados, regressão Unit disponível: 1.613 testes/6.280 assertions, dois skipped, sem falhas/erros. A classe MediaPipeConsentServiceTest foi excluída deste comando por exigir banco de testes dedicado não configurado. Isso não representa aprovação de todas as integrações ou do produto inteiro.

## Evidências

Diretório privado `.superpowers/sdd/2026-09-07-url-interface/`: `01-url-form.png`, `03-project-status.png`, `created-project.json`, `ui-progress.json`, `before-resume.png`, `after-resume.png`, `resume-result.json`, `database-evidence.json`, `gemini-diagnostic.json`, `failed-project.png` e `resume-fix-report.md`. Não publicar sessão/cookies de teste. Código e relatórios permanecem na pasta principal `C:\Users\Acer\Desktop\video`; o ZIP anterior à correção de retomada não foi regenerado nesta etapa.

O script inicial de automação precisou corrigir o seletor de botão para aba (`role=tab`) e a expectativa de redirecionamento (biblioteca, não detalhe). São ajustes do teste, não correções da aplicação. Uma única submissão criou o projeto; não houve duplicação após o redirecionamento.
