FROM serversideup/php:8.3-fpm-nginx

# Switch to root user to install dependencies and set permissions
USER root

# Copy project files into the container image
COPY . /var/www/html

# Set the working directory
WORKDIR /var/www/html

# Install dependencies via Composer explicitly during build
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Set permissions for Laravel storage and cache directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Switch back to the unprivileged web user
USER www-data
