FROM php:8.2-cli
# Set working directory
WORKDIR /var/www

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl

# Install Redis extension
RUN pecl install redis-5.3.7 \
    && docker-php-ext-enable redis

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy composer files FIRST (for better caching and to avoid script execution issues)
COPY composer.json composer.lock ./

# Install dependencies - note the --no-scripts flag to prevent script execution
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts

# NOW copy the rest of the application
COPY . .

# Run any post-install scripts now that all files are present
RUN composer run-script post-autoload-dump || true

# Set permissions AFTER everything is copied
RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 /var/www/storage \
    && chmod -R 775 /var/www/bootstrap/cache

# Create a start script that uses Railway's PORT variable
RUN echo '#!/bin/sh\n\
echo "Starting M-CONNECT on Railway"\n\
php artisan config:clear\n\
php artisan route:clear\n\
php artisan config:cache\n\
php artisan route:cache\n\
php artisan migrate --force\n\
php artisan storage:link || true\n\
echo "Starting Laravel server on port $PORT"\n\
exec php artisan serve --host=0.0.0.0 --port=$PORT' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

# Expose port (not required by Railway but harmless)
EXPOSE 8000

# Use the start script
CMD ["/usr/local/bin/start.sh"]