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

# Extensões PHP exigidas pelo projeto
RUN docker-php-ext-install pdo pdo_mysql mbstring

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chmod +x /app/docker/start.sh

# O Railway injeta $PORT em tempo de execução.
EXPOSE 8080
CMD ["/app/docker/start.sh"]
