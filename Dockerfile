FROM php:8.2-cli-bookworm

# Dependências de sistema: extensões PHP + FFmpeg (probe/render) + yt-dlp (importação por URL/YouTube)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libonig-dev \
    libcurl4-openssl-dev \
    unzip \
    git \
    ffmpeg \
    ca-certificates \
    curl \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp (binário autônomo) e Node.js como runtime JavaScript opcional do yt-dlp
RUN curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node

# Limites de PHP para site E worker (vídeos de até ~2 h). Sem isso o PHP CLI usa upload_max_filesize=2M,
# e o worker recusa qualquer vídeo maior que 2 MB ("versão compatível dentro do limite").
RUN printf 'upload_max_filesize=4G\npost_max_size=4100M\nmemory_limit=512M\nmax_input_time=3600\n' > /usr/local/etc/php/conf.d/cliplab-limits.ini

# Extensões PHP exigidas pelo projeto
RUN docker-php-ext-install pdo pdo_mysql mbstring

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chmod +x /app/docker/start.sh

# Padrões para vídeos de até ~2 horas (as Variables do Railway sobrescrevem).
# O lease precisa cobrir a soma dos timeouts; com estes valores o mínimo é 1620 s.
ENV MEDIA_MAX_UPLOAD_BYTES=3221225472 \
    MEDIA_DOWNLOAD_TIMEOUT_SECONDS=900 \
    PROCESS_TIMEOUT_SECONDS=300 \
    QUEUE_LEASE_SECONDS=1800 \
    YTDLP_TIMEOUT_SECONDS=90 \
    YTDLP_JS_RUNTIME=node:/usr/local/bin/node \
    YTDLP_REMOTE_COMPONENTS=ejs:github \
    YTDLP_COOKIES_FILE=/tmp/yt-cookies.txt \
    YTDLP_CACHE_DIR=/tmp/yt-dlp-cache

# O Railway injeta $PORT em tempo de execução.
EXPOSE 8080
CMD ["/app/docker/start.sh"]
