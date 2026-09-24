# ClipLab — homologação real e estúdio de cortes
Data: 6 de setembro de 2026. Pedido integral e aprovação anterior do usuário; autorização explícita confirmada para o vídeo informado.

## Objetivo
Homologar YouTube https://www.youtube.com/watch?v=7YC9tf-qmmw como fonte principal, sem substituir por amostra. Metadados lidos: vídeo público não-live, 2073 segundos, “1 SOLTEIRO vs 20 CASADAS | ft. Zago”. Projeto real 2011 criado pelo formulário; usar o mesmo projeto ao diagnosticar, não duplicar cobranças. Verificar importação, análise, seleção, transcrição real dos cortes, legendas, editor, thumbnails, arquivos exportados e preparação de publicação.

## Arquitetura escolhida
Evoluir o PHP/MySQL com snapshots atuais, fila serial de mídia e armazenamento privado. Uma reescrita em outro framework atrasaria homologação e quebraria contratos; um editor genérico multipista acrescentaria motor de composição desnecessário. A timeline será de intervalo e legendas sincronizadas, com preview composto e ações reais. Controles opcionais de reenquadramento existentes continuam disponíveis com consentimento; não simular remoção de silêncio, alternância de participantes ou música se não houver motor testado.

## Subprojetos e ordem
1. Media: novas exportações Full HD mantendo leitura e render de snapshots720 antigos; opções tipográficas/CTA/logo reais; preservação da sincronização por palavra ao reutilizar/corrigir legendas.
2. Biblioteca e editor: templates reutilizáveis/brand kit privado, logo PNG, aplicação como valores; timeline por cue, edição/seek e preview proporcional com zonas seguras. Não editar intervalos silenciosamente nem inventar tempos.
3. Thumbnails e publicação: studio separado com candidatos reais de múltiplos momentos, tratamento/texto/templates, render privado; metadados por plataforma e histórico local/manual. Sem postagem externa/OAuth inventado.
4. Integração e homologação: root compõe rotas/worker, executa migrações aditivas, corrige importação/qualidade com evidência, testa upload e URL, exporta três formatos e verifica bytes/codecs/duração/imagem/áudio.

## Contrato comum de aparência
EditorOptions mantém chaves atuais e acrescenta: font_family Arial/Georgia/Verdana; background_color hexadecimal; outline_width inteiro0..6; shadow_depth0..6; font_weight auto/normal/bold; animation none/fade/pop; cta_text UTF8 até120; logo_asset_id inteiro>=0; logo_position top_left/top_right/bottom_left/bottom_right; logo_scale inteiro5..25 (percentual da largura). Defaults compatíveis: Arial,#151515,2,0,auto,none,'',0,top_right,10. Existing style pode controlar caixa (podcast); presets Clean/Impacto são configurações reais, não estilos de render inexistentes.
Logo: Asset ID nunca caminho. Biblioteca resolve owned asset/project para objectKey privado. Renderer recebe callable opcional (int assetId,int projectId): ?string; ClipEditService recebe callable opcional (int assetId,int userId): bool para validar antes da fila. A composição root injeta ambos. PNG imutável<=2MiB e <=2048px por dimensão; máximo5 ativos por usuário, sem excluir bytes referenciados.
CTA aparece nos últimos min(5,duração) segundos com estilo ASS seguro. Preview deve identificar-se como aproximado; export é autoridade.

## Templates/brand
Tabela user_editor_templates, limite50; id,user_id,name<=80,category viral/podcast/clean/impact/custom, options_json,aspect_ratio, timestamps. Kit único por usuário, defaults/options e favorites (IDs owned), CTA/logo e fontes permitidas. APIs listOwned/findOwned/saveOwned/removeOwned e kitForUser/saveKit; sempre copiar dados normalizados para versão, não referência mutável. Preparar método snapshot de template para aplicação futura em lote sem prometer batch UI ainda.
Novos endpoints /templates e /marca; módulos de rotas próprios, root faz includes/nav. Editor usa catálogo lazy opcional via configuração/factory e partial separado; preservar construtor atual e testes sem DB.

## Legendas e preview
SRT permanece fallback semJS. Editor lista cues com texto/tempos e botões seek; pode corrigir, remover legenda (não remove automaticamente o áudio), selecionar cue como intervalo com aviso/confirmar e navegar na timeline. Nenhuma palavra apagada corta áudio sem pedido explícito de trim.
Em intervalo igual, cues intactos preservam language/words; correção mantendo número de palavras preserva tempos ao substituir tokens; cues com estrutura/tempos alterados descartam somente words daquele cue, com aviso e possibilidade de retranscrever. Não estimar word timings e chamá-los reais.
Preview acompanha vídeo, reenquadramento, títulos/legendas/logo, ratio e zonas seguras. Timeline e playback não disputam o vídeo com detecção automática: observar estado busy/cancel e desabilitar seek/edição durante amostragem.
Tamanhos legíveis, labels, foco, erros, mobile320/360; sem autoplay privado.

## Thumbnails e preparação
5 candidatos no máximo, amostrados em momentos distintos, descartar frames pretos e priorizar contraste/nitidez por heurística local documentada. Sem afirmar análise facial/emoção não implementada. Opção original é thumbnail legado virtual, nunca duplicar seu objectKey. Usuário pode escolher outro tempo válido do corte. Designs final1280x720 JPEG a partir do vídeo, não ampliar preview640. Templates Clean/Bold/Split com título até120, posição/tamanho/cor e logo owned, variantes armazenadas como artefatos imutáveis privados. Jobs limitados, idempotência/lease/cleanup.
Metadados YouTube,Shorts,Instagram,TikTok,Facebook: título/descrição/caption/hashtags/CTA, thumbnail owned ready e MP4completed; estados draft/ready/exported/marked_published/archived com histórico append-only. marked_published é “publicação informada manualmente”, não verificação da rede. Pacote JSON/TXT+links privados/downloads reais; sem fingir link público.

## Validação e aceite
Baselines conhecidos anteriores:1365 testes PHP semfalhas/1skipWindows e19browser. Não substituem esta homologação. Root faz fluxo real autorizado; arquivo completo fonte serve também upload quando dentro dos limites reais. Erros inválidos/grandes/interrupção são testados em fixtures isoladas e claramente separados da execução real principal.
Pelo menos um corte com transcrição real e estilos/minimal,karaoke,viral, exportações1080x1920,1080x1080,1920x1080; ffprobe e decode integral; capturas de frames legendados/thumbnail/preview, comparação temporal, análise semântica de início/fim/contexto de cada corte criado. Registrar limitações em vez de declarar qualidade subjetiva absoluta.
Relatório docs/HOMOLOGACAO_VIDEO_OFICIAL.md lista evidências, IDs, tamanhos, falhas/correções e tudo ainda não comprovado. Hostinger permanece implantação separada.
