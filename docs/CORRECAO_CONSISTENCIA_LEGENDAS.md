# Correção de consistência das legendas

Data da validação: 7 de setembro de 2026. Ambiente local: `C:\Users\Acer\Desktop\video`, `http://localhost:8093`.

## Resultado

O fluxo de novas exportações exige uma transcrição sincronizada não vazia e um estilo de legenda visível antes de renderizar. Foram testados os serviços, as rejeições de entrada e três MP4s reais derivados do vídeo oficial. Os arquivos anteriores concluídos foram preservados.

## Causas encontradas

1. A admissão comum (`ClipRenderRequestService`, também usada pelo agendamento automático) despachava diretamente `render_clip`, sem criar perfil/track de legendas nem o job `generate_subtitles`.
2. O editor permitia modo `none`, estilo `none` e SRT vazio. O modelo genérico de transcrição aceita uma lista vazia para representar ausência de fala, mas esse resultado também podia ser persistido como track `ready`.
3. O worker aceitava perfil de editor ausente; assim, renderizar sem legenda era um caminho de sucesso válido. Certos estados inválidos podiam terminar o job de legendas sem liberar o trabalho seguinte, ou permanecer em espera indefinida.
4. A interface de projeto/editor mostrava uma falha genérica após recarregar, ocultando a causa pública de transcrição.

Evidência anterior: clipes 47–51 não possuíam perfil nem transcrição; 54–56 e 65 eram exportações legadas com modo/estilo `none`. O 57 tinha 26 blocos. Os 52 e 53 estavam corretamente marcados como falha de provedor, não como exportações concluídas.

## Correções

- Admissão comum cria perfil `auto`/`minimal`, track `pending` e job `generate_subtitles`, atomicamente com revisão/intervalo e chave de idempotência.
- Exportação automática sem faixa de áudio é rejeitada com instrução para fornecer SRT sincronizado no editor.
- O editor aceita `auto` ou `manual`, sempre com estilo visível; SRT manual exige ao menos um bloco válido e não vazio.
- Toda exportação passa pelo job de legendas. Uma faixa manual pronta é validada e reutilizada sem nova chamada de transcrição e sem sobrescrever as correções.
- O repositório rejeita transcrição vazia antes de gravar o estado `ready`.
- A fronteira de renderização exige perfil válido, track pronta, texto não vazio e duração correspondente. Não chama o renderizador nem publica arquivos se essa condição falhar.
- Ausência de fala retorna `subtitle_empty`; inconsistências, track falha ou perfil inválido não geram sucesso sem legenda. Erros estruturais de snapshot são terminais; falhas não-PDO de persistência têm tentativas limitadas. Falhas transitórias de banco/lease continuam aguardando com segurança, sem publicar MP4.
- Novos rascunhos abrem com legenda automática/minimalista; sem áudio, abrem em manual. Templates legados sem legenda são adaptados apenas no formulário, com aviso; os templates armazenados não são alterados.
- Erros públicos de legenda permanecem visíveis no projeto e editor após recarregar. Códigos desconhecidos usam mensagem genérica, sem expor dados do provedor.

Não houve migração de schema, reparo de banco, alteração da chave Gemini, mudança do renderizador genérico/ASS ou remoção de mídia. Foram preservados limites de duração precisa, cotas, controle de concorrência, estilos existentes e regras de revisão manual.

## Homologação real

Vídeo principal: [YouTube — referência oficial 7YC9tf-qmmw](https://www.youtube.com/watch?v=7YC9tf-qmmw). Foi reutilizada a origem real já importada no projeto 2011, sem dados simulados e sem novo download do YouTube.

| Clipe | Caminho testado | Legenda | Resolução | Duração do contêiner | Tamanho |
|---|---|---|---|---|---|
| 52, revisão 2 | Nova tentativa pela admissão comum | Automática, minimalista, 26 blocos, pt | 1080 × 1920 | 49,482767 s | 12.589.023 bytes |
| 66 | Nova versão do clipe 47 pelo editor | Automática, minimalista, 19 blocos, pt-BR | 1920 × 1080 | 45,011634 s | 13.096.098 bytes |
| 67 | Reexportação manual do 66 | SRT preservado, estilo viral, 19 blocos | 1080 × 1080 | 45,011633 s | 8.858.905 bytes |

Os seis jobs (legendas e render para cada versão) concluíram na primeira tentativa. A transcrição antecedeu a criação dos respectivos jobs de renderização.

Verificações efetuadas:

- MP4 H.264 + AAC nos três casos, com tamanho do arquivo igual ao registrado no banco.
- FFprobe e decodificação completa de áudio/vídeo com FFmpeg `-xerror`: código de saída zero nos três arquivos.
- Diferença entre duração solicitada e duração do contêiner: aproximadamente 33 ms no 52 e 2 ms nos 66/67, dentro da tolerância de frames existente.
- Extração de frames aos 5, 15 e 43 segundos. Inspeção visual dos frames de 15 segundos confirmou legendas incorporadas e legíveis nos três formatos.
- Downloads autenticados MP4 e SRT retornaram HTTP 200. Os hashes SHA-256 dos MP4s baixados coincidiram com os arquivos do storage.
- SRT e representação completa da transcrição do 67 coincidiram exatamente com os do 66, incluindo idioma, texto e timestamps.
- Envios HTTP reais de SRT manual vazio, estilo `none` e modo `none` foram recusados com HTTP 422, sem criar exportação.

### Limites desta validação

- O Gemini retornou timestamps por segmento, não por palavra. Esta correção não torna karaokê palavra a palavra disponível quando o provedor não fornece esse alinhamento.
- A ausência de legendas e os caminhos de falha foram corrigidos; não foi medida taxa de erro da transcrição nem auditada palavra por palavra a sincronização de todo o vídeo original.
- Texto já incorporado ao vídeo de origem não é tratado como uma transcrição do sistema e não é removido por esta correção.
- Arquivos legados concluídos sem legenda não são sobrescritos automaticamente. Para aplicar a correção a eles, criar nova versão pelo editor. O clipe 66 demonstra esse procedimento a partir do 47; o 47 original continua disponível.
- Esta é uma homologação local de legendas, não uma declaração de conclusão de todo o produto nem um teste na Hostinger.

## Testes automatizados executados

- 197 testes Unit focados, 819 asserções: admissão, worker, render guard, integridade, edição, EOF, scheduler, domínio de legendas e renderizador.
- 12 testes Feature sem banco externo, 123 asserções: editor e tela de projeto, incluindo apresentação segura de erros.
- Teste JavaScript de legenda/preview passou.
- Total PHP desta rodada: **209 testes e 942 asserções**, todos aprovados.
- `git diff --check` passou. O servidor PHP continuou ouvindo em 127.0.0.1:8093.

Não foi executada a suíte de integração que cria/altera schema no MariaDB compartilhado. Os testes de falhas usam SQLite em memória e dublês controlados; a validação de sucesso descrita acima usou mídia, Gemini, fila, storage e navegador reais.

## Acesso e evidências

- [Editor com legenda automática](http://localhost:8093/clips/66/editar)
- [Editor da versão manual/viral](http://localhost:8093/clips/67/editar)
- [Projeto do vídeo oficial](http://localhost:8093/projetos/2011)

Evidências privadas, fora do pacote de entrega e do Git: `.local-history/subtitle-consistency-20260907/` (metadados, SRTs, resultados HTTP e capturas). FFprobe, logs de decodificação e frames: `.superpowers/sdd/2026-09-06-media-homologation/evidence/official-verified-clips-52-66-67.json` e arquivos correspondentes.

O pacote ZIP de homologação anterior é histórico e não inclui esta correção. O código atualizado está na pasta canônica `video`.
