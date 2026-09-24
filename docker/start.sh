#!/bin/sh
# Inicia o ClipLab no Railway: site + worker de mídia + fila de e-mail no MESMO container,
# para que o worker enxergue os mesmos arquivos de mídia que o site.
set -u
cd /app

MEDIA_DIR="${MEDIA_PRIVATE_ROOT:-/app/storage/media}"
mkdir -p "$MEDIA_DIR" /app/storage/logs 2>/dev/null || true

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
exec php -d upload_max_filesize="${PHP_UPLOAD_MAX:-512M}" -d post_max_size="${PHP_POST_MAX:-520M}" \
    -S 0.0.0.0:"${PORT:-8080}" -t public public/index.php
