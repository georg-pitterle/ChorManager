FROM php:8.5-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    nodejs \
    npm \
    mysql-client \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype \
    libzip-dev \
    mariadb-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev

# Build and enable PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    mbstring \
    pdo_mysql \
    gd \
    zip \
    pcntl \
    bcmath \
    && apk del --no-cache \
    libzip-dev \
    mariadb-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# (Alpine image) Install additional PHP extensions if needed
# Note: Alpine uses apk, so apt-get is not available.
# The required extensions are installed via apk packages above.

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock* ./

# Copy Composer patch definitions before install so patches can be applied
COPY patches/ ./patches/
COPY patches.lock.json ./

# Copy npm manifest files and install frontend dependencies
COPY package.json package-lock.json* ./
RUN npm ci --omit=dev --no-audit --no-fund

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code
COPY . .

# Now run the copy-assets script
RUN php bin/copy-assets.php

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY bin/mail-queue-worker.sh /usr/local/bin/mail-queue-worker.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/mail-queue-worker.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
