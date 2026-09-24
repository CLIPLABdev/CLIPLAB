# ClipLab — reforma de apresentação e experiência
Data: 2026-09-06. Direção aprovada no anexo do usuário e autorização para execução integral.

## Objetivo e limites
Reformular a landing e levar uma identidade coerente a todas as telas existentes, sem substituir o PHP/MySQL nem os fluxos reais de importação, Gemini, edição e exportação. Localhost 127.0.0.1:8093 permanece ativo. A hospedagem futura é Hostinger; nenhuma publicação externa ou troca de stack está incluída.

## Referências e originalidade
Analisados https://cut.pro/pt-BR e https://realoficial.com.br/pt em 2026-09-06. Usar somente princípios: demonstração antes de explicação longa, jornada curta, CTA contextual, três etapas, benefício concreto e prova verificável. Não copiar headlines, código, imagens, identidade nem números. A ClipLab não anuncia publicação automática, redes conectadas, analytics sociais, billing automático ou viralização garantida.
Marca preservada: ClipLab. Tese: um estúdio editorial grafite com acento lima, onde a linha do tempo e os recortes são a linguagem visual.

## Sistema visual
Tokens: background #0b0d10, surface #12161b, elevated #191f26, border #303942, text #f4f6f8, muted #aab4c0, accent #d2f86b, accent-ink #142009, secondary #8cdce8. Fonte system-ui/Segoe UI, títulos compactos e grandes na landing, corpo >=16px; controles >=14px. Radius 12px controles, 20px superfícies; sombras discretas. Sem dependências novas, fontes remotas, imagens genéricas ou arte representacional em CSS. Reutilizar ícones locais e componentes reais. CSS geométrico para interface/timeline é adequado.
Nova folha compartilhada design-system.css, carregada após estilos base. Páginas próprias ainda podem ter estilos locais, com correções escopadas para cascata. Mesmo sistema em login/cadastro, dashboard, projetos, clipes/editor, conta/perfil, admin, informativas/erros. Preservar legibilidade, contrastes, estados, modais, tabelas, gráficos, aria, CSP e foco.

## Landing
Hero: headline original sobre recuperar conteúdo já gravado, subtítulo com upload/URL pública, análise IA, revisão e exportação; CTA cadastro e demonstração. Prévia de interface em HTML com rótulo Demonstração ilustrativa e botão avançar etapa, sem processamento fictício de input ou requests externos. Três estados controlados: origem, análise/sugestões, exportação. Funciona sem JS como exemplo estático; animação respeita reduced-motion e não esconde informação.
Seções: problema concreto; três passos; recursos reais em demonstração editorial; benefícios; planos atuais vindos do banco; depoimentos somente se cadastrados, autorizados e publicados; FAQ nativa details; CTA final e links legais. Sem vídeos/imagens privados tornados públicos.
Públicos: criadores, podcasters, educadores e pessoas que reaproveitam vídeos autorizados. Fontes: upload ou URL HTTPS direta; YouTube público quando habilitado, sujeito a disponibilidade e direitos. Não prometer qualquer plataforma/live.
Planos: preços, créditos, minutos, upload e armazenamento reais; recomendação editorial de plano pago (não popularidade falsa), sem alterar preço. Explicar que contratação/alteração é confirmada pela administração e não há cobrança online automática.

## Conteúdo editável
Criar área admin /admin/conteudo para manter até três depoimentos em campos textuais limitados, com nome, contexto, citação e confirmação de autorização/publicação. Sem seed de avaliações nem números inventados. Armazenar em configuração já suportada pelo app, separada dos segredos Gemini; nunca expor configuração arbitrária. Se infraestrutura não permite reuso, tabela aditiva e migração idempotente (não destrutiva). Lista pública vazia por padrão. Rotas protegidas por AdminMiddleware e CSRF; escape na saída; validação servidor. Dados de planos públicos vêm de catálogo ativo existente.

## Contratos entre frentes
Task 1: somente sistema visual, layouts exceto marketing, views auth/dashboard/projetos/clipes/conta/perfil/informativas/erros, CSS compartilhado e seus testes. Não alterar lógica de filas/controladores nem JS de intake/Gemini.
Task 2: home.php, layouts/marketing.php, CSS landing.css, JS landing-demo.js/app.js, testes da landing. Incluir partial components/marketing-plans.php e components/marketing-proof.php somente se presentes; variáveis $publicPlans e $publicTestimonials default [].
Task 3: HomeController, routes/web.php e admin.php, backend catálogo/conteúdo admin, view admin/content.php, partials marketing-plans.php e marketing-proof.php, testes. Dados passados a home com nomes acima. Task 1 não edita navegação array de admin; task3 adiciona item /admin/conteudo. Theme controla CSS desses partials via classes landing-plan-grid, landing-plan-card, landing-proof-grid, landing-proof-card.

## Validação
Testes antes de lógica nova. Preservar testes existentes exceto assertivas de copy deliberadamente substituída (mantendo seu propósito). Testes unit/feature seguros e navegador local para navegação, demonstração, formulários e responsividade; não consumir Gemini nem criar projetos reais para testar aparência. Verificar desktop/mobile, menu teclado, FAQ, ausência de overflow e erros CSP/console, credenciais locais existentes. Não alterar arquivos .env nem segredos.
