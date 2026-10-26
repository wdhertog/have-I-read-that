FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo_mysql gd \
 && a2enmod rewrite \
 && sed -ri 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/app.conf \
 && printf 'upload_max_filesize=20M\npost_max_size=20M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/app.ini
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY bin ./bin
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY templates ./templates
RUN mkdir -p var/sessions && chown -R www-data:www-data var
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
