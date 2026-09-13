# Verificação das correções locais — 6 de setembro de 2026

## Resultado confirmado

O projeto local **2010**, criado pelo formulário da conta de demonstração, concluiu o fluxo real:

1. Importação HTTPS do trailer público de Sintel, da Blender Foundation.
2. Armazenamento privado e inspeção do vídeo.
3. Análise real no Gemini 3.1 Flash-Lite com resposta validada.
4. Geração automática de um corte, renderizado pelo FFmpeg.
5. Download autenticado, thumbnail HTTP 200 e reprodução no Chrome.

O corte **45** foi baixado com 3.652.915 bytes, H.264/AAC, 854×480 e duração de 52,208333 segundos. FFmpeg decodificou integralmente o arquivo sem erros. Não foram usadas respostas simuladas da IA nesse projeto.

- Projeto: http://127.0.0.1:8093/projetos/2010
- Download: http://127.0.0.1:8093/clips/45/download
- Origem pública: https://download.blender.org/durian/trailer/sintel_trailer-480p.mp4
- Créditos/licença: Blender Foundation, Sintel, CC BY 3.0 — https://durian.blender.org/about/

## Correções cobertas

- APP_ENCRYPTION_KEY provisionada com segurança; segredo Gemini salvo criptografado, sem reflexão no HTML. O gerador preserva uma chave válida existente.
- Botões de salvar, testar e criar projeto apresentam feedback e evitam submissões duplicadas. Alterações Gemini não salvas são preservadas.
- Validações de tamanho, formato, URL e autorização YouTube são apresentadas junto ao formulário, com associação acessível aos campos.
- Respostas de upload maior que o permitido usam uma página 413 explicativa.
- Importação YouTube com validação de vídeo público individual, faixas progressivas ou adaptativas, download com IPs públicos fixados e junção local.
- Teste real separado do YouTube armazenou e inspecionou um MP4 de 744.412 bytes antes de encaminhá-lo à IA.
- Corrigida a lista CURLOPT_RESOLVE: múltiplas entradas do mesmo host sobrescreviam endereços IPv4 pelo último IPv6. Uma entrada agrupada preserva todos os endereços previamente validados.
- Payload Gemini compatível com responseMimeType/responseSchema; conversão do esquema remove additionalProperties e normaliza tipos REST, mantendo intacta a validação estrita no domínio.
- Launcher Windows com limites adequados de upload, worker serial e cálculo de lease que inclui resolução, download, junção e inspeção.

## Evidências automatizadas

- Unit + Feature: **1.328 testes, 6.146 assertions, nenhuma falha**. Um teste de symlinks foi ignorado porque o Windows não permite sua criação sem os privilégios necessários.
- Formulário no Chrome: 6 testes passaram, incluindo erros associados aos campos e prevenção de duplo envio.
- Teste JavaScript do feedback Gemini: passou.
- Revisões independentes dos componentes Gemini e YouTube: nenhum achado crítico ou importante pendente no escopo revisado.
- Sintaxe PHP/JavaScript e git diff --check: verificações realizadas sem erros nos arquivos alterados.

## Configuração e limites

O modelo efetivo local foi alterado para **gemini-3.1-flash-lite** após o Gemini 3.7 Flash retornar HTTP 503 por alta demanda. A chave criptografada foi preservada. O modelo continua ajustável no painel administrativo.

O PHP web permite até 500 MiB; o limite da conta/plano pode ser menor. URLs externas continuam sujeitas à disponibilidade do provedor, limites do plano e prazos definidos. Não se contornam login, DRM, restrições etárias ou bloqueios do YouTube.

Esta validação é local. Não comprova implantação na Hostinger, SMTP, domínio/HTTPS ou backups de produção. O processamento exige FFmpeg, FFprobe, yt-dlp e execução de processos, normalmente em VPS. Consulte LOCAL_RUNTIME.md para iniciar os serviços da aplicação; o banco MySQL/MariaDB precisa estar ativo.

Os projetos anteriores usados no diagnóstico foram preservados como registros de teste; seus estados de falha anteriores não indicam o resultado do projeto 2010.
