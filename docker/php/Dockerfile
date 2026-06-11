FROM php:8.5-fpm-alpine

# pdo_pgsql pour parler à PostgreSQL, git/unzip pour composer
RUN apk add --no-cache postgresql-dev git unzip \
    && docker-php-ext-install pdo_pgsql

# Composer embarqué : tout passe par "docker compose exec app composer ..."
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# La génération locale (Ollama sur CPU) peut dépasser les 30s par défaut
RUN echo "max_execution_time=120" > /usr/local/etc/php/conf.d/zz-rag.ini

# Conteneur de dev : le pool FPM tourne en root pour écrire sans friction
# dans le bind mount (storage/, cache). En production : www-data + droits.
RUN { echo '[www]'; echo 'user = root'; echo 'group = root'; } \
    > /usr/local/etc/php-fpm.d/zz-rag.conf

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm", "-R"]
