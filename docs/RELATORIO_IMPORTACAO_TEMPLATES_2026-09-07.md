# Importação YouTube, templates e efeitos

Fonte: `C:/Users/Acer/Desktop/video`. Homologação em 7 de setembro de 2026.

## Importação real

Vídeo solicitado: https://www.youtube.com/watch?v=O1FZD5Zove0.

O projeto antigo2015 registrou apenas erro genérico; portanto não é possível provar retrospectivamente o stderr daquela tentativa. A mesma URL e o mesmo runtime reproduziram estouro do limite de1MiB de metadados, transformado em vídeo indisponível. Outra resposta escolheu vídeo1080p+áudio somando mais de500MiB.

Correções:

- Metadados projetados por `--print`, sem descrição/manifests desnecessários; limite de segurança mantido. Resposta real passou a aproximadamente156KiB.
- Seleção de versão menor quando a estimativa ultrapassa o orçamento, preservando áudio/idioma e reserva para mux. Tamanho desconhecido continua limitado pelo downloader; não se assume que cabe.
- Códigos específicos para verificação humana, idade, região, privado/login, formato, rede, rate limit, metadados e tamanho. Rede/rate limit seguem tentativas limitadas da fila.
- Logs com ID público do vídeo, código finito, exit code e contadores, nunca stderr bruto, URL assinada, senha ou token. Falha do logger não interrompe importação.
- Allowlist de CDN, DNS público/SSRF, limites, staging, MP4, FFprobe e descarte em falhas preservados. Não há cookies/login nem contorno de bloqueios.

Resultado em armazenamento privado isolado: download e mux reais de720p,230.758.706bytes, H264/AAC,1280×720, duração3.931.254ms. Dois arquivos idênticos, ambos decodificados integralmente com FFmpeg `-xerror`, exit0. SHA-256: `c884d8fcfb5107b9b3b068150af43eaeda9fe98a008a7502861a4ed8439276b0`.

O primeiro script auxiliar falhou somente ao formatar o relatório após download/FFprobe, por chamar um getter inexistente; foi corrigido. A verificação final reutilizou os arquivos e não depende desse relatório inicial. Evidência: `.superpowers/sdd/2026-09-07-import-templates/real-import-results.json`.

Não foram reprocessados projetos antigos, consumidos créditos Gemini ou feitas publicações. O projeto que já estava falho mantém seu histórico; a correção vale para novas tentativas. Disponibilidade de qualquer vídeo público continua sujeita às restrições reais do YouTube, região, duração/plano e servidor.

## Templates e efeitos

Backend: configurações tipadas, defaults legados neutros e persistência integral de template/kit/snapshot. Efeitos de imagem separados de legendas/logo; cores, fontes, contorno/fundo, animações, zoom/movimento e fades suportados por render real. Nenhuma migração de banco necessária.

Os formulários do editor, templates e marca compartilham os novos controles. A aplicação valida o conjunto antes de alterar campos; payload inválido não aplica parcialmente. Campos ausentes voltam aos defaults, e a gravação envia o conjunto completo. Legado recebe valores neutros sem migração.

Controles: tipografia, peso/itálico, espaçamento, margens, contorno e fundo separados, duração/saída de animações, brilho, contraste, saturação, desfoque, ruído, vinheta, zoom, movimento e fades do vídeo. Não são transições entre vários clipes: entrada/saída afetam o corte individual.

A prévia usa palavras temporizadas somente quando o trecho e texto coincidem com o snapshot; ao editar texto, volta ao segmento sem inventar alinhamento. Canvas continua explicitamente aproximado em relação a FFmpeg/libass. Sem vídeo, apresenta uma prévia de estilo com aviso das limitações.

124 testes backend/630 asserções; 3 integrações FFmpeg/108 asserções; 15 cenários sintéticos com diferenças de pixels, duração/resolução e áudio preservado. Revisão adicional encontrou e corrigiu o caminho neutro para fontes acima de 8192 pixels: não há limite novo quando os efeitos estão desligados, enquanto efeitos ativos conservam seu limite. Regressão específica passou.

UI: 16 testes de navegador; 18 testes PHP/183 asserções; scripts de geometria, efeitos e editor. Cobrem aplicação, reset, rejeição atômica, payload de salvamento, pixels, palavras alinhadas, edição de texto, teclado, mobile, sem JS e movimento reduzido. Teste antigo que tentava selecionar modo sem legendas foi atualizado para a política já existente; não foi removida funcionalidade de produção.

Homologação adicional do render de um trecho real do vídeo informado, com todos os ajustes de imagem combinados, zoom animado, fades e caixa/contorno de texto:

| Formato | Resolução | Duração | Tamanho | Decode completo |
|---|---|---|---|---|
| Vertical | 1080×1920 | 3 s | 402.665 bytes | exit 0 |
| Quadrado | 1080×1080 | 3 s | 422.339 bytes | exit 0 |
| Horizontal | 1920×1080 | 3 s | 734.867 bytes | exit 0 |

O texto desses três testes é explicitamente “Teste visual — não é transcrição”. Valida a integração gráfica e não pretende provar acurácia de transcrição/IA. Não foi feita nova análise Gemini. Evidência: `real-effects-results.json` no diretório de homologação.

## Regressões e limites

115 testes de YouTube/fila/downloader passaram,312asserções; teste adicional do log passou com5asserções. Suíte ampla:1.607 testes,6.207asserções,3erros exclusivamente na exigência de banco de testes dedicado MediaPipe,2ignorados. Essa execução não constitui suíte integralmente verde. Não foi usado o banco real como substituto.

Nenhuma garantia de compatibilidade com todo bloqueio futuro do YouTube, nem conclusão geral de todo o produto. Localhost retornou HTTP200 em `/login` durante esta etapa.

Nova execução excluindo somente a classe que exige banco dedicado: 1.604 testes, 6.208 asserções, nenhuma falha/erro, dois ignorados. Após ajuste final do caminho neutro: 189 testes pertinentes, 866 asserções, todos aprovados. Os três testes de consentimento com banco dedicado continuam pendentes; não são regressões corrigidas ou resultados positivos presumidos.

## Decisões de execução

Mantida a pasta principal solicitada, com snapshots locais e sem commits/worktrees externos. O custo é revisar diferenças de arquivos em vez de um histórico de commits consolidado. Recursos automáticos são opcionais e neutros por padrão para preservar projetos antigos. Nenhum segredo, banco real, mídia anterior ou integração externa foi alterado. Artefatos privados de teste não devem integrar o ZIP de publicação.

## Encerramento e pacote

Revisão independente final aprovada, sem achados pendentes no escopo. A observação final sobre detecção de suporte a blur no canvas foi corrigida e testada com e sem API. Sintaxe de 374 arquivos PHP aprovada; reexecução final do navegador: 16 testes em 27,9 s; 18 testes Feature/183 asserções aprovados. Localhost `/login` retornou HTTP 200 ao encerrar.

Pacote novo: `dist/clipforge-import-templates-20260907.zip`, 9.420.033 bytes, 1.574 arquivos mais manifesto. Todos os hashes do manifesto conferem com os arquivos atuais; nenhuma entrada `.env`, mídia privada, banco local ou logs. Este relatório é entregue separadamente do ZIP.

SHA-256 do ZIP: `7adcaf6cf03f321ee21449d68decf5c42af9a0ca78d1255bcde0fc5fce0f9c69`.

O ZIP é artefato de código, não uma publicação já efetuada na Hostinger. Não sobrescreve configuração, dados ou mídia do servidor. Os três testes que exigem banco dedicado seguem pendentes como descrito acima.
