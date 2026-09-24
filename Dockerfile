FROM php:8.2-cli-bookworm

# Dependências de sistema: bibliotecas para as extensões PHP + FFmpeg (probing/render local)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libonig-dev \
    libcurl4-openssl-dev \
    unzip \
    git \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Extensões PHP exigidas pelo projeto (pdo_mysql é a que estava faltando no Nixpacks)
RUN docker-php-ext-install pdo pdo_mysql mbstring

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction

# O Railway injeta a variável $PORT em tempo de execução; usamos forma shell pra expandir.
EXPOSE 8080
CMD php -S 0.0.0.0:${PORT:-8080} -t public public/index.php
