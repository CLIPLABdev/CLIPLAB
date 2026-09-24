# Entrega técnica do ClipLab

## Estado

Correção adicional em 08/09/2026: projetos demo 2017 e 2018 foram importados corretamente, mas tiveram respostas rejeitadas por timestamps/durações inválidos. O schema enviado à IA agora respeita a duração da fonte; o prompt exige consistência entre início/fim/duração e revisa respostas rejeitadas; a recuperação explícita aceita essa falha sem perder as proteções de créditos. Cinco regressões reproduzidas antes da correção passaram depois. Unit disponível: 1.665 testes, 6.565 assertions, dois skips (classe dependente de banco dedicado excluída). A recuperação dos dois projetos foi acionada pela interface; o reteste ainda enfrentou HTTP 503 na geração e timeouts no upload, portanto não equivale a homologação real concluída desses vídeos.

Atualização do pacote em 08/09/2026: a fonte principal é `C:\Users\Acer\Desktop\video`, incluindo as correções recentes de resiliência Gemini. O código inclui tentativas limitadas com espera exponencial, diagnóstico seguro, recuperação explícita de análises elegíveis e indicação de nova tentativa na interface. Na homologação de 07/09, o projeto 2016 concluiu a análise após falhas HTTP 503 e exportou três MP4 com SRT; downloads, reprodução no navegador e decodificação completa foram verificados. Isso não garante disponibilidade permanente do Gemini nem validação integral da qualidade das legendas: no corte 69, o intervalo final após 48,5 s ainda não foi auditado quanto à cobertura de fala. A implantação e homologação na Hostinger permanecem pendentes. Os números de testes nas seções abaixo são históricos, não resultados do empacotamento atual.

Atualização de 07/09/2026: o resultado mais recente está em [HOMOLOGACAO_VIDEO_OFICIAL.md](HOMOLOGACAO_VIDEO_OFICIAL.md). O vídeo oficial foi importado e analisado pelo Gemini, cinco cortes originais foram renderizados e versões com legendas/capas/exportação de metadados foram verificadas. O defeito de duração EOF foi corrigido e retestado no corte 65. O aceite qualitativo e a implantação de produção permanecem pendentes; os números históricos abaixo não representam uma execução completa da suíte nesta atualização.

O SaaS está implementado na branch local do worktree, sem remote Git configurado. O ambiente local em `http://127.0.0.1:8093` está funcional para acompanhamento, mas esta entrega **não equivale a publicação 100% homologada em produção**.

A demonstração local existente foi preservada. Este documento não registra senhas, chaves de API nem caminhos de backup.

Limitações atuais de qualidade: áudio e sincronização percebida não passaram por auditoria ouvindo os resultados; a transcrição real tem cues por frase e zero tempos por palavra; os recortes centrais vertical/quadrado ainda perdem participantes. Multipista não foi implementado; remoção de silêncios, autozoom, alternância e rastreamento facial no vídeo oficial não foram homologados. Não confundir silencedetect com remoção nem o Smart Reframe existente com seguimento facial comprovado.

## Escopo entregue

- autenticação, recuperação de acesso e isolamento por conta;
- projetos, upload/URL privada, inspeção de mídia e processamento em fila;
- análise Gemini, reserva/consumo de créditos e sugestões de cortes;
- biblioteca de clipes com filtros, paginação, status e downloads privados;
- renderização MP4/thumbnail, Smart Reframe e versões que preservam o original;
- editor de intervalo, proporção, foco, título, marca e legendas manuais ou automáticas;
- conta com plano, limites reais e extrato de créditos;
- administração separada para usuários, planos, créditos, projetos, vídeos, jobs, erros, logs e configuração Gemini protegida;
- migrations, checker de produção, empacotamento e comandos finitos para cron/worker.

Stack de execução: PHP 8, MySQL/MariaDB, FFmpeg/FFprobe e Gemini API. O navegador usa assets locais; mídia e configuração sensível permanecem privadas.

A comparação de planos mostra minutos, créditos e limites de upload e armazenamento efetivamente utilizados. As flags `exports_hd`, `priority_processing` e `team_access` permanecem no catálogo/admin por compatibilidade, mas não ativam equipes, prioridade de fila ou resolução diferenciada por plano nesta versão.

## Rotas principais

O smoke local confirmou HTTP 200 em dez entradas: `/dashboard`, `/projetos`, `/clips`, `/conta/plano`, `/conta/creditos`, `/perfil`, `/projetos/novo`, `/privacidade`, `/termos` e `/clips/43/editar`. A rota de login também permanece disponível como suporte ao fluxo.

Nesse smoke, a conta comum da demonstração recebeu HTTP 403 em `/admin`, como esperado; ele não comprovou uma sessão administrativa HTTP 200.

Também estão implementados os endpoints autenticados de render/status, thumbnail, download, prévia privada e SRT. O módulo administrativo inclui páginas e ações para usuários, projetos, vídeos, jobs, erros, créditos, planos, logs e Gemini. A autorização é revalidada no servidor; IDs de outra conta não concedem acesso.

## Evidências históricas — anteriores à homologação oficial atual

- Unit + Feature final: **1.259 testes, 5.811 assertions, 1 skip**.
- Integrações finais sem `DROP`/`TRUNCATE`: **153 testes, 1.486 assertions, zero falhas**, com isolamento de processos e FFmpeg real.
- Integração histórica: **163 testes, 1.774 assertions**, executada antes dos últimos ajustes.
- E2E novo do editor: **1 teste, 36 assertions**, com transcritor fake e FFmpeg, WAV e download reais.
- Cleanup de fontes: **49 testes, 242 assertions**.
- Chrome: **7 cenários da UI autenticada** e **7 cenários públicos**.
- Chrome Smart Reframe: **1 cenário aprovado em 20,1 s**.
- Localhost 8093: dez rotas funcionais e demonstração existente.

Esses grupos possuem cobertura sobreposta e **não devem ser somados como um total final sem deduplicação**.

Os checks de banco, migrations aplicadas, armazenamento privado, `proc_open`, FFmpeg/FFprobe, captions e orçamento operacional ficaram verdes. O helper de recuperação de migrations passou nos testes unitários e a verificação somente leitura confirmou os schemas conhecidos.

O teste destrutivo controlado `SaasMigrationRecoveryTest` não foi autorizado: o opt-in permanece desligado e registra **7 skips**. Ele só pode rodar com autorização informada, banco descartável com sufixo `_test` e os guards previstos.

O pacote foi preparado em uma área separada, com Composer `--no-dev --no-scripts --no-plugins --optimize-autoloader`, sem acesso à rede. Seu autoload de produção carregou home, login, cadastro, privacidade e termos com HTTP 200; PHPUnit não estava disponível nesse autoload. O ZIP final inclui manifesto SHA-256 e exclui configuração real, mídia, logs, testes e backups.

## Pendências para produção

- O bloqueio histórico de geração Gemini foi superado no teste oficial: análise 776 e transcrição real concluíram. Ainda ocorreram falhas transitórias e uma resposta inválida em outro projeto; os resultados e limitações atuais estão no relatório de homologação, sem promessa de disponibilidade do provedor.
- Domínio, plano contratado e acesso da Hostinger não foram fornecidos.
- FFmpeg deve executar em VPS/worker compatível; hospedagem Hostinger compartilhada não foi homologada para esse processamento.
- O processo web também exige proc_open/FFprobe e acesso ao mesmo storage privado: o POST autenticado de exportação mede a duração precisa fora da transação. Apenas mover o worker para um VPS não basta. Consulte a composição atual em HOSTINGER.md.
- SMTP real, cron, HTTPS, backup e restauração ainda exigem homologação no ambiente final.
- Identidade do operador, domínio, contato, retenção e textos legais precisam ser preenchidos e revisados antes do lançamento.
- Planos e créditos são administráveis, mas checkout e gateway de pagamento ficaram fora do escopo aprovado.

## Operação e publicação

Use o [README](../README.md) para instalação local, worker e visão funcional. Siga [HOSTINGER.md](HOSTINGER.md) para build, backup, migrations, HTTPS, armazenamento privado, cron, checker, criação segura de administrador e rollback.

Comandos de referência:

```bash
composer install --no-dev --optimize-autoloader
php bin/migrate.php
php bin/create-admin.php --email=operador@dominio.example --name=Operador
php bin/build-release.php --output=/caminho-privado/cliplab.zip
php bin/check-production.php --role=all-in-one --verify-http --verify-gemini
php bin/process-jobs.php --queue=media --limit=1 --time-budget=50
```

Execute a criação/promoção de administrador pelo comando documentado no guia, com senha segura fora de argumentos e sem credencial padrão.
