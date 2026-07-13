FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    unzip \
    git \
    && docker-php-ext-install intl pdo_mysql zip gd mbstring opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# APP_ENV/APP_SECRET par défaut pour que le build (asset-mapper:compile) puisse
# démarrer le kernel ; Render écrase ces valeurs à l'exécution avec les vraies.
ENV APP_ENV=prod
ENV APP_SECRET=build_time_placeholder

# Copié avant le reste du code pour profiter du cache Docker tant que
# composer.json/lock ne changent pas. Les extensions PHP sont déjà installées
# ici, donc Composer peut vérifier les platform requirements correctement.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && php bin/console asset-map:compile \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var public/documents

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
