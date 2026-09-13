# Biblioteca global de clipes — design
Data: 2026-09-06

## Resultado
A rota autenticada GET /clips reúne os cortes da análise atual de todos os projetos do usuário. Um render concluído aparece na biblioteca e oferece thumbnail e download privados imediatamente. A biblioteca usa o estado persistido existente; não cria jobs, cobranças, cópias de mídia nem uma etapa adicional de publicação.

## Escopo
Filtros recent (todos), processing (approved, queued, rendering), completed e failed. Ordenação por clips.updated_at DESC, clips.id DESC; 24 itens por página. Filtro inválido vira recent; página inválida vira 1; página além do fim é limitada à última; lista vazia usa page=last_page=1. Somente a análise de maior id por projeto é elegível, como nas rotas privadas existentes.

Cards exibem título, nome do projeto e da origem, duração, data, status, score 0–100, gancho, motivo e proporção/modo. Texto obrigatório: "A pontuação é uma estimativa da IA e não garante viralização." Estados vazios são reais, com ação para criar ou abrir projetos. Nenhum resultado fictício.

## Arquitetura
ClipLibraryRepository(PDO)::paginateForUser(int $userId, string $filter, int $page, int $perPage = 24): array.
Retorno: items, filter, page, per_page, total, last_page.
Itens, em allowlist explícita:
id:int, project_id:int, project_name:string, source_type:string, source_name:string,
title:string, status:string, display_duration_seconds:float, viral_score:int, hook:string,
reason:string, category:string, output_aspect_ratio:string, reframe_mode:string,
created_at:string, updated_at:string, rendered_at:?string, has_thumbnail:bool, has_download:bool.
Origem usa somente nome público do arquivo (basename Windows/Unix), ou rótulo "Vídeo importado por URL". Nunca source_url, object_key, output_file, thumbnail path ou detalhes internos.
Duração usa render_end_time-render_start_time se ambos são válidos, senão duration_seconds.
Perfil de render é o da revisão exata; ausência usa original/original.
Assets disponíveis apenas se completed com chave não vazia e tamanho positivo. Não fazer I/O por card.

ClipLibraryController(View $view, callable $library, ?callable $user = null)::index(Request $request): Response.
$library recebe userId, filter, page, perPage e retorna a paginação. $user recebe userId e retorna perfil existente.
View clips.index recebe title="Clipes", user, library. Rota usa AuthMiddleware existente, owner exclusivamente da sessão. HTML privado com Cache-Control: private, no-store.

UI reutiliza layout autenticado, ícones e paleta atuais, acrescenta item Clipes na navegação. cards ativos oferecem todos os hooks exigidos por clip-status.js sem reescrever o poller. Cards completed usam /clips/{id}/thumbnail e /clips/{id}/download; todo card tem /projetos/{id}. Controles são links GET válidos sem JS. Carregar imagens lazy; não carregar vídeos na biblioteca.
Em Recentes, polling atualiza os cards já carregados. Um filtro aberto não recebe cards novos até atualizar a página, explicado por uma ação "Atualizar". Nenhum download automático sem gesto do usuário.

## Compatibilidade e segurança
PHP 8.0+, PDO, MySQL/MariaDB suportados, HTML/CSS/JS existentes; nenhum Node em produção. Usar prepared statements, limites de paginação e escape HTML de toda informação variável. Parâmetros array/numéricos/expoente/overflow não causam warnings nem erro 500. Query user_id é ignorada. Projeção ownership/latest deve concordar com status/assets.
Usar os índices existentes e registrar EXPLAIN; criar índice adicional somente se evidência mostrar necessidade. Não executar migration em GET.
Não alterar .env, provedor IA, demonstração 8093 nem dados fora das fixtures criadas em clipforge_phase5_test. Não publicar na Hostinger nesta fatia.

## Verificação
Repo real DB: dois owners, vários projetos/análises, quatro filtros, paginação 25+, desempate, limites, paths ausentes, duração e perfil exato, flags de assets.
Controller/view: guest, input adversarial, sessão, private/no-store, escape de textos, links, vazio, paginação e hooks.
Fluxo real: fila/render existentes produzem clipe, biblioteca o revela sem novo job/débito, owner baixa bytes e foreign não vê; navegador 320/768/1440 e sem JavaScript.

