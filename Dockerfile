# Real-time Trading Platform — PHP 8.3 + Swoole (TLS) + rdkafka application image.
FROM php:8.3-cli

# System libs: pgsql, brotli/ssl/curl for the Swoole TLS build, librdkafka for Kafka.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libbrotli-dev libssl-dev libcurl4-openssl-dev \
        librdkafka-dev git unzip \
    && rm -rf /var/lib/apt/lists/*

# Core extensions.
RUN docker-php-ext-install -j"$(nproc)" bcmath pdo_pgsql sockets

# Swoole built from source WITH OpenSSL so the WebSocket client can reach wss://.
RUN cd /tmp \
    && pecl download swoole \
    && tar xf swoole-*.tgz && rm swoole-*.tgz && cd swoole-* \
    && phpize \
    && ./configure --enable-openssl --enable-sockets --enable-swoole-curl --enable-brotli \
    && make -j"$(nproc)" && make install \
    && docker-php-ext-enable swoole \
    && cd / && rm -rf /tmp/swoole-*

# Redis + Kafka client extensions.
RUN pecl install redis rdkafka \
    && docker-php-ext-enable redis rdkafka

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
