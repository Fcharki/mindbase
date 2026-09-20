FROM php:8.2-fpm-bookworm

# Install system dependencies: Composer deps, Postgres extension, nginx + supervisor
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libpq-dev \
    nginx \
    supervisor \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy Laravel project
COPY . .

# Install PHP dependencies
RUN composer install

# Ship process supervisor + boot entrypoint
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=10s --start-period=30s --retries=8 \
    CMD curl -fsS http://localhost:8000/up > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]