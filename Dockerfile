FROM serversideup/php:8.3-fpm-nginx

# 1. Switch to root to handle system operations and dependencies
USER root

# 2. Copy application files
COPY . /var/www/html

# 3. Set working directory
WORKDIR /var/www/html

# 4. Run composer install with flags that prevent environment/extension blocking (Exit Code 2 fix)
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# 5. Fix permissions for Laravel storage and bootstrap cache so www-data can write to them
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 6. Switch back to the unprivileged web user for security
USER www-data
