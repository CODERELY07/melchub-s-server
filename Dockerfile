FROM serversideup/php:8.4-fpm-nginx

USER root

COPY . /var/www/html

WORKDIR /var/www/html

RUN php -v

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data
