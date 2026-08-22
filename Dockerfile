FROM serversideup/php:8.3-fpm-nginx

# Switch to root to copy files and set permissions
USER root

COPY . /var/www/html

# Set working directory
WORKDIR /var/www/html

# Laravel production environment configs
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

# Ensure proper permissions for Laravel storage and cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Switch back to web user
USER www-data
