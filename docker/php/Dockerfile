FROM php:8.4-cli-alpine

# pdo_pgsql pour parler à PostgreSQL, git/unzip pour composer
RUN apk add --no-cache postgresql-dev git unzip \
    && docker-php-ext-install pdo_pgsql

# Composer embarqué : tout passe par "docker compose exec app composer ..."
COPY --from=composer:lts /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
