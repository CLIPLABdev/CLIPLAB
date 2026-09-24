# Atualização visual — e-mails, administração e área do usuário

Data: 7 de setembro de 2026. Fonte principal: `C:\Users\Acer\Desktop\video`. Localhost: http://localhost:8093/dashboard.

## Entrega implementada

Direção visual: estúdio editorial em carvão/lima, tipografia de sistema, contraste, hierarquia e espaços mais consistentes. Nenhuma fonte externa foi adicionada. As skills de planejamento, desenvolvimento com subagentes, testes e revisão independente orientaram a divisão por arquivos e as verificações de acessibilidade, sem alterar as regras de negócio.

### Painel do usuário

- Navegação em três grupos, ação de novo projeto evidente, perfil e cabeçalho refinados.
- Busca de **páginas**, não busca de vídeos: filtro com e sem acentos, atalhos de teclado, navegação por setas, Escape e retorno de foco. É ocultada quando JavaScript está indisponível.
- Menu móvel com controle de foco e fallback que mantém os links acessíveis sem JavaScript.
- Dashboard com próxima ação, projetos recentes e métricas reais; bibliotecas de projetos/clipes e formulário de criação com copy e composição renovadas.
- Valores de duração/tamanho desconhecidos indicam “A confirmar”, sem simular duração zero.
- Transições leves, foco visível, títulos longos com quebra de linha e preferência de movimento reduzido respeitada.
- Estilos compartilhados também chegam às páginas de plano, créditos, pagamentos, notificações, preferências e perfil; essas páginas não tiveram sua lógica reescrita.

### Administração

- Navegação agrupada em operação, pessoas/planos, financeiro, comunicação e sistema, preservando permissões e recursos habilitados.
- Menu móvel com Escape, backdrop, contenção/retorno de foco, resize e funcionamento sem JavaScript.
- Métricas separadas por produção, contas e financeiro; nenhum gráfico, crescimento ou atualização “em tempo real” inventado.
- Tabelas, formulários, ações sensíveis, paginação, mensagens e estados vazios refinados; nomes, actions, métodos e tokens dos formulários preservados.

### Templates de e-mail

- Documento comum com cabeçalho, corpo claro até 600px, hierarquia tipográfica, CTAs e rodapé. Sem imagens/fontes remotas, rastreamento ou animações dentro do e-mail.
- Sugestões de copy para os 18 eventos existentes, usando exclusivamente as variáveis permitidas.
- Galeria por versão, filtros de texto/categoria/status, estados vazios, prévia desktop/celular e editor com ajuda contextual.
- A sugestão carregada não grava nem ativa uma versão. O administrador continua responsável por salvar e ativar explicitamente. **Textos personalizados existentes não foram sobrescritos.**
- Prévia, teste manual e outbox compartilham o mesmo documento/conteúdo. Envio usa estilos inline fixos; preview usa a folha local equivalente sob CSP restrita.
- `EmailHtmlPolicy` e `SecurityHeadersMiddleware` têm os mesmos hashes do baseline. Nenhum HTML/CSS arbitrário novo foi liberado.
- Configuração de e-mail ganhou organização por remetente, servidor e autenticação, mantendo as ações existentes e identificando claramente o teste como envio real.

## Problemas encontrados e corrigidos nesta rodada

1. Escape era consumido pelo campo de busca e só apagava o texto: agora fecha a busca e devolve o foco.
2. Botão de busca perdia o nome acessível quando seu texto era ocultado em telas menores: adicionado nome permanente, reproduzido e testado a 390 e 1000px.
3. Navegação móvel ficava fora da tela sem JavaScript: fallback visual mantém os links disponíveis.
4. Títulos longos de projetos eram truncados no dashboard móvel: ajustada a quebra e disposição do status.
5. Rótulos ocultos de tabela administrativa causavam overflow: contidos no wrapper da tabela.
6. Novo HTML de e-mail produzia linhas de 1,8–2,7KB que o transporte 8bit enviava sem codificação adequada: corpo passou a quoted-printable antes do dot-stuffing. Testes locais verificam limites de linha e roundtrip de HTML, UTF-8, links longos, CRLF e pontos, sem alterar conexão/TLS/autenticação.
7. Badge indicava “Salvo como rascunho” antes de salvar: alterado para “Será salvo como rascunho”.

## Evidências e testes

- Testes comportamentais foram escritos antes das correções e mostraram RED/GREEN; inclui busca/foco, metadados ausentes, documento e-mail e transporte MIME.
- Regressão funcional selecionada: **81 testes / 472 asserções, sem falhas**, incluindo administração, contas, cobrança, campanhas, cabeçalhos e apresentação.
- Browser autenticado, somente GET/HEAD: **24 vistas** (12 rotas em 1440/390), HTTP 200, sem overflow, erros JavaScript ou CSP; navegação real pela busca confirmada.
- Browser isolado administrativo: navegação/foco/teclado/no-JS/reduced-motion, dashboard e tabela de usuários.
- Browser isolado de e-mail com controller e CSP reais, SQLite em memória: filtros, teclado, ajuda, editor, preview e fallback sem JS; nenhum request externo ou envio SMTP.
- Duas revisões independentes contra o baseline, com testes adicionais; os achados de acessibilidade, MIME e copy foram tratados.
- Resultado final Unit: **1.551 testes, 5.854 asserções, zero falhas/erros, 2 skips**. Os três testes dependentes de MySQL foram excluídos explicitamente como explicado abaixo. XML em `dist/visual-polish/unit-results.xml`.
- Sintaxe: **371 arquivos PHP, zero erros**; os quatro scripts compartilhados/admin/e-mail também passaram no `node --check`.
- Última rodada dos três browsers (workspace, admin e email) passou após as correções finais. O browser de e-mail registrou novamente zero erros e zero requests externos.

Capturas do usuário e resultado das 24 vistas: `.superpowers/sdd/2026-09-07-visual-polish/evidence/`.
Capturas administrativas e de e-mail: `dist/visual-polish/` e `dist/visual-polish/emails/`.

## Limitações e transparência

- A rodada não enviou SMTP real, não realizou cobrança, publicação ou reprocessamento de mídia. O fluxo de envio foi testado com transporte/socket controlado. A aparência em Outlook, Gmail e Apple Mail reais ainda requer homologação nesses clientes.
- A tentativa da suíte Feature completa não ficou verde: testes de banco exigem DSNs dedicados, testes Hostinger dependem de artefato versionado/engine de banco, e um teste antigo de projeção espera lista sem `render_error_message`. O controller relacionado tem hash idêntico ao baseline anterior a esta rodada. Nenhum desses testes foi silenciado ou alterado para aparentar aprovação; o recorte pertinente acima foi executado separadamente.
- A primeira suíte Unit coincidiu com uma alteração temporária de teste de mutação do agente de e-mail. O arquivo foi restaurado, congelado e a suíte repetida; não se usou aquela execução como evidência de sucesso.
- Três testes `MediaPipeConsentServiceTest` exigem o banco isolado `cliplab_phase5_test` e foram excluídos explicitamente do run Unit final. Os skips reportados pelo próprio PHPUnit continuam identificados no XML.
- Preservados `.env`, chave de criptografia, credenciais, banco real, mídia, templates ativos e configurações. Não houve novos workers, migração, commit ou publicação externa.
- Esta entrega conclui o refinamento visual descrito; não equivale a declarar homologação de todo o produto em produção/Hostinger.

## Recuperação e entrega

Baseline anterior às alterações: `.local-history/visual-polish-20260907/baseline` (código e assets, sem segredos).
Todos os fontes novos continuam dentro da pasta `video`. O checkpoint anterior permanece intacto. O novo pacote, quando finalizado, contém somente os arquivos permitidos pelo empacotador, sem `.env`, banco, mídia privada ou sessão QA.

Pacote final verificado: `dist/cliplab-visual-20260907-checkpoint.zip` — 1.569 arquivos + manifesto, 9.407.731 bytes. Todos os arquivos do manifesto coincidem com os fontes canônicos; os 13 componentes/assets visuais esperados estão presentes. SHA-256: `245ec146f9df25966e1def2dd459e1ba32e842650e9a75598d026b3eeca8f396`. Manifesto: `e9cedbb08076d3832472878bf58fe317590367f5cd723eb64f619880ece8d1df`.

O resumo visual está incluído no relatório geral de aprimoramento que acompanha o ZIP; este relatório detalhado e as capturas ficam disponíveis separadamente na pasta principal. A primeira checagem do ZIP classificou erroneamente a pasta de código `app/Storage` como dados; o verificador foi corrigido para distinguir esse código dos diretórios privados e repetido com sucesso, sem modificar o pacote. Última checagem local: `/login` HTTP 200, porta 8093 ativa, `git diff --check` sem saída.
