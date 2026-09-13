# Templates completos e efeitos de vídeo

Pedido: corrigir inconsistências sem remover recursos. Auditoria encontrou schema/POST/JSON coerentes para campos antigos, mas preview não mostra accent/karaoke e fallback ignora novas opções. Não há perda de word timings ao reabrir: TranscriptRevision::merge já corrige isso. Não refazer esse mecanismo.

Escolha: estender o EditorOptions atual, não criar um segundo formato de template nem migrar dados. JSON legado recebe defaults neutros em leitura. Conteúdo SRT, intervalo e reframe continuam fora do template visual. Alterar schema sem UI ou só UI sem render não conclui a entrega. Código na pasta video, PHP8.0 compatível, sem env/DB real/migrações/SMTP/publicação/commits. Prioridade: controlar recursos realmente renderizados, mantendo copy honesta sobre preview.

## Contrato adicional (campos existentes preservados)

Todos os números abaixo são inteiros; transporte HTML pode usar strings decimais inteiras estritas, JSON normalizado usa int.

| Campo | Default | Limite / significado |
|---|---|---|
| font_italic | 0 | 0 ou 1, texto |
| letter_spacing | 0 | 0..10, espaçamento ASS |
| caption_margin_x | 0 | 0..120, margem adicional em referência 720px, limitada à área útil |
| caption_offset_y | 0 | -120..120, deslocamento adicional em referência 720px, respeitar área visível |
| outline_color | auto | auto ou #RRGGBB; auto usa background_color legado |
| background_mode | auto | auto/none/box; auto conserva caixa podcast e estilos antigos |
| animation_duration_ms | 150 | 0..2000, duração entrada none/fade/pop existente |
| animation_out | auto | auto/none/fade; auto conserva fade de saída somente quando animation=fade |
| animation_out_duration_ms | 150 | 0..2000; clamp à duração do evento |
| video_fade_in_ms | 0 | 0..3000; clamp a metade da duração do corte |
| video_fade_out_ms | 0 | 0..3000; clamp a metade da duração do corte |
| brightness | 0 | -100..100; FFmpeg brightness /100 |
| contrast | 100 | 0..200; FFmpeg contrast /100 |
| saturation | 100 | 0..300; FFmpeg saturation /100 |
| blur | 0 | 0..20; sigma gblur |
| noise | 0 | 0..30; força noise |
| vignette | 0 | 0..100; ângulo máximo PI/4, força proporcional |
| zoom_percent | 100 | 100..150; 100=sem zoom |
| motion | none | none/zoom_in/zoom_out/pan_left/pan_right; diferente de none requer zoom_percent>100 |

Movimento é opcional, em torno do quadro já reenquadrado, sem editar consentimento/keyframes. Quando ativado, vídeo de saída usa 30fps para curva determinística; duração e áudio mantidos. Zoom none com zoom_percent>100 é zoom estático central. Fonte continua Arial/Georgia/Verdana. Peso explícito normal/bold deve afetar os textos; auto conserva defaults de cada papel (caption/title/brand/CTA). Background/outline separados somente por campo explícito; legado auto reproduz o documento anterior.

Ordem do vídeo: reframe existente → zoom/movimento → brilho/contraste/saturação → blur → noise → vinheta → fade de entrada/saída do vídeo → legendas/título/CTA/logo. Os efeitos de vídeo não alteram a legibilidade dos overlays nem o áudio. São transições de entrada/saída do corte, não transições entre múltiplos clipes; não criar timeline multiclipe fictícia. Neutral deve produzir exatamente o filtergraph antigo.

API compartilhada: EditorOptions::defaults():array, integerFields():array (nomes), fromForm(array):self; fromArray continua estrito e normalizado. Numeric strings de formulário aceitas apenas regex inteira assinada sem expoentes/NaN e dentro dos limites. keys desconhecidas continuam rejeitadas. VideoEffectsFilterBuilder::build(EditorOptions,int width,int height,int durationMs):string gera somente filtros tipados, nunca concatena texto arbitrário.

Preview: servidor fornece snapshot de transcrição ao view como previewTranscript (array ou null). JS usa words somente quando texto/tempos da cue atual correspondem ao snapshot; edição sem alinhamento não inventa palavras temporizadas. Prévia de canvas permanece aproximada, mas deve representar cores/fontes/peso/italic/margens/outline/background/animações e efeitos controlados. Qualquer efeito que o navegador não reproduza deve ser explicitamente indicado, nunca silenciosamente ignorado. Sem vídeo, fallback aplica as opções visuais possíveis e se identifica como prévia de estilo.

Aplicação de template é atômica: normalizar payload allowlisted com defaults antes de tocar controles, rejeitar valores malformados sem aplicação parcial, aplicar todos os controles e só depois emitir eventos. Preservar conversão obrigatória do estilo none legado para minimal e bloqueio reframe busy. Usar como padrão no kit significa copiar para marca e aplicar no editor, não alterar cortes existentes.

Aceitação: testes de limites/injeção/legado; todas as opções com valores distintos fazem roundtrip por serviço/repo/controller; ASS demonstra valores; FFmpeg produz arquivos reais com duração/dimensões válidas e diferenças de pixels para efeitos; browser testa aplicação completa, save payload, reabertura e preview. Regressões existentes e revisão independente. Não acionar processamento dos vídeos do usuário para testar efeitos: usar fixture sintética local e um trecho real já autorizado para homologação final quando disponível.
