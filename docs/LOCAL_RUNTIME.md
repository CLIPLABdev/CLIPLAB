# Runtime local no Windows

O launcher local mantém o servidor web em primeiro plano e escuta somente em `127.0.0.1`. Ele aplica limites de upload apenas aos processos filhos, sem alterar o `php.ini` global:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tools\start-local.ps1
```

O padrão usa `C:\xampp\php\php.exe`, porta `8093`, `upload_max_filesize=500M` e `post_max_size=501M`. O megabyte adicional cobre o overhead multipart; assim, o limite efetivo da aplicação pode chegar a 500 MiB. Informe outro binário ou porta quando necessário:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tools\start-local.ps1 `
  -PhpBin C:\caminho\php.exe -Port 8088
```

O launcher recusa uma porta que já esteja ocupada. Ele não reutiliza nem encerra o processo proprietário. Identifique esse processo e decida separadamente o que fazer antes de tentar novamente. `Ctrl+C` encerra somente os processos filhos iniciados pela execução atual.

## Worker opcional

O servidor web não consome a fila. Ative o worker explicitamente:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tools\start-local.ps1 -WithWorker
```

Essa opção pode baixar vídeos informados por URL e, conforme o pipeline avança, chamar o Gemini e consumir créditos. Cada chamada do worker continua finita e usa:

```text
bin/process-jobs.php --queue=media --limit=1 --time-budget=50
```

O launcher espera a chamada terminar antes de iniciar a próxima; portanto, não há sobreposição local. A saída contém eventos JSON e somente os contadores allowlisted `claimed`, `completed`, `retried`, `deferred`, `failed` e `operational_errors`. Payloads, stderr do worker, caminhos de mídia e configuração do provedor não são impressos.

Para uma execução limitada de diagnóstico, use `-WorkerIterations 1`. Sem essa opção, `-WithWorker` continua repetindo chamadas até `Ctrl+C`. `-PollSeconds` controla o intervalo entre chamadas concluídas.

## Status somente leitura

Consulte limites efetivos, capacidades booleanas do worker e contagens agregadas da fila sem iniciar processos de mídia ou o provedor:

```powershell
C:\xampp\php\php.exe bin\local-status.php
```

O comando executa somente um `SELECT` agregado em `processing_jobs`. O JSON não inclui IDs, nomes de projetos, payloads, URLs, identificadores de worker, mensagens de erro, caminhos privados nem segredos.

Os limites desse diagnóstico refletem o PHP que executou o comando, não outro processo já iniciado. Para reproduzir os limites do launcher, use `php -d upload_max_filesize=500M -d post_max_size=501M bin/local-status.php`. A tela de criação também aplica o limite do plano da conta, que pode ser menor.

## Chave de criptografia e Gemini

Antes de salvar a chave Gemini pela primeira vez, execute na raiz da aplicação:

```powershell
C:\xampp\php\php.exe bin\generate-encryption-key.php
```

O comando gera uma chave aleatória em `.env` sem imprimi-la. Ele preserva uma chave válida existente e recusa substituir a proteção de segredos já criptografados. Faça backup privado de `APP_ENCRYPTION_KEY` junto ao banco: perdê-la impede descriptografar a configuração salva. Nunca a publique no Git ou distribua o `.env` com a aplicação.

Em `/admin/configuracoes/gemini`, salve a configuração antes de usar **Testar conexão**. O formulário avisa se há alterações ainda não salvas. O teste de conexão verifica a credencial e o modelo; a validação completa de processamento exige criar um projeto com vídeo.

## Importação do YouTube

Instale o yt-dlp a partir da distribuição oficial e configure caminhos absolutos para ele e para um runtime JavaScript compatível. O FFmpeg/FFprobe também precisam estar instalados. Exemplo sem segredos:

```dotenv
YOUTUBE_IMPORT_ENABLED=true
YTDLP_BINARY="C:/ferramentas/yt-dlp.exe"
YTDLP_JS_RUNTIME="node:C:/ferramentas/node.exe"
YTDLP_TIMEOUT_SECONDS=90
QUEUE_LEASE_SECONDS=600
```

Na tela **Novo projeto → Importar URL**, cole um link HTTPS de vídeo individual e confirme que possui autorização para importá-lo. São aceitos vídeos públicos sem restrições; playlists, transmissões ao vivo, conteúdo privado, restrição etária, DRM e exigências de autenticação não são contornados. Links diretos HTTPS de arquivos de vídeo continuam disponíveis.

O yt-dlp obtém os metadados. O servidor baixa as faixas com validação de endereço público, limite agregado de bytes e prazo definido; quando áudio e vídeo são separados, o FFmpeg os une localmente. Não são aceitos cookies do navegador ou credenciais do YouTube. A disponibilidade depende também do YouTube e do IP da hospedagem; em caso de bloqueio, envie o arquivo autorizado.

O prazo de lease deve cobrir resolução + download + junção + inspeção e a margem de segurança, além das demais operações. `bin/local-status.php` informa se o prazo configurado é suficiente. Para produção na Hostinger, esse processamento exige ambiente com execução de processos e os binários instalados, como uma VPS; o launcher Windows é apenas para desenvolvimento local.
