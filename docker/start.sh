#!/bin/sh
# Inicia o ClipLab no Railway: site + worker de mídia + fila de e-mail no MESMO container,
# para que o worker enxergue os mesmos arquivos de mídia que o site.
set -u
cd /app

MEDIA_DIR="${MEDIA_PRIVATE_ROOT:-/app/storage/media}"
mkdir -p "$MEDIA_DIR" /app/storage/logs 2>/dev/null || true

# Cookies do YouTube (opcional): cole o cookies.txt em base64 na variável YTDLP_COOKIES_B64.
COOKIES_TARGET="${YTDLP_COOKIES_FILE:-/tmp/yt-cookies.txt}"
if [ -n "${YTDLP_COOKIES_B64:-}" ]; then
    if printf '%s' "$YTDLP_COOKIES_B64" | tr -d ' \r\n' | base64 -d > "$COOKIES_TARGET" 2>/dev/null && [ -s "$COOKIES_TARGET" ]; then
        chmod 600 "$COOKIES_TARGET"
        export YTDLP_COOKIES_FILE="$COOKIES_TARGET"
        echo "[start] Cookies do YouTube carregados"
    else
        rm -f "$COOKIES_TARGET"
        echo "[start] YTDLP_COOKIES_B64 inválido; seguindo sem cookies"
    fi
fi

# Mantém o yt-dlp atualizado: o YouTube muda com frequência e versões antigas passam a falhar.
if [ "${YTDLP_AUTO_UPDATE:-true}" = "true" ]; then
    timeout 60 yt-dlp -U >/tmp/yt-dlp-update.log 2>&1 && echo "[start] yt-dlp: $(yt-dlp --version)" \
        || echo "[start] Não foi possível atualizar o yt-dlp; seguindo com $(yt-dlp --version 2>/dev/null)"
fi

# Cache do yt-dlp (guarda o solucionador de desafio do YouTube entre execuções).
if [ -n "${YTDLP_CACHE_DIR:-}" ]; then
    mkdir -p "$YTDLP_CACHE_DIR" 2>/dev/null || true
fi

# Restos de downloads interrompidos (ex.: container reiniciado no meio de um vídeo).
rm -rf "$MEDIA_DIR"/.ytdlp-* 2>/dev/null || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[start] Aplicando migrations..."
    php bin/migrate.php >/tmp/migrate.log 2>&1 && echo "[start] Migrations OK" \
        || { echo "[start] Falha nas migrations (o site sobe mesmo assim):"; grep -o 'RuntimeException[^<]*\|PDOException[^<]*' /tmp/migrate.log | head -3; }
fi

if [ "${RUN_WORKER:-true}" = "true" ]; then
    echo "[start] Worker de mídia ativo"
    (
        while true; do
            php bin/process-jobs.php --queue=media --limit=1 --time-budget=50 || sleep 15
            sleep "${WORKER_POLL_SECONDS:-5}"
        done
    ) &
fi

if [ "${RUN_EMAIL_WORKER:-true}" = "true" ]; then
    echo "[start] Fila de e-mail ativa"
    (
        while true; do
            php bin/process-email.php --limit=10 >/dev/null 2>&1 || true
            sleep 60
        done
    ) &
fi

echo "[start] Site na porta ${PORT:-8080}"
exec php -d upload_max_filesize="${PHP_UPLOAD_MAX:-4G}" -d post_max_size="${PHP_POST_MAX:-4100M}" \
    -S 0.0.0.0:"${PORT:-8080}" -t public public/index.php
