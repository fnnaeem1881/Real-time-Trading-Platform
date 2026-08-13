# Real-time Trading Platform — PHP 8.3 + Swoole application image.
FROM php:8.3-cli

# System libs: libpq for pdo_pgsql, libbrotli/ssl for the Swoole build.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libbrotli-dev libssl-dev git unzip \
    && rm -rf /var/lib/apt/lists/*

# Core + assignment extensions.
RUN docker-php-ext-install -j"$(nproc)" bcmath pdo_pgsql \
    && pecl install swoole redis \
    && docker-php-ext-enable swoole redis

# Composer (for the PSR-4 autoloader; there are no third-party deps).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --no-progress --no-scripts || true
COPY . .
RUN composer dump-autoload -o

EXPOSE 8080
ENV HTTP_HOST=0.0.0.0 HTTP_PORT=8080
CMD ["php", "bin/server.php"]
