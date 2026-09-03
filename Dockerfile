# Frontend-Abhängigkeiten einmal bauen, nicht je Architektur.
#
# Das Abbild entsteht für linux/amd64 und linux/arm64. Ohne diese Stufe liefe
# `npm ci` im arm64-Zweig unter QEMU-Emulation - dort ist alles um ein
# Vielfaches langsamer, und die Downloads brachen mit ECONNRESET ab, weil npms
# voreingestellte Zeitgrenzen für emulierte Läufe zu knapp sind.
#
# `--platform=$BUILDPLATFORM` heftet diese Stufe an die Maschine, die den Build
# ausführt. Sie läuft damit genau einmal und nativ. Das ist zulässig, weil alle
# Produktionspakete reine JS- und CSS-Dateien sind (Bootstrap, TinyMCE,
# FullCalendar, Icons) - ihr Ergebnis ist auf beiden Architekturen dasselbe.
# Käme je ein Paket mit kompilierten Bestandteilen dazu, müsste es an dieser
# Stelle wieder architekturabhängig gebaut werden.
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets

WORKDIR /assets

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev --no-audit --no-fund

FROM php:8.5-fpm-alpine

# Install system dependencies
# Node fehlt hier bewusst: die Frontend-Pakete werden in der assets-Stufe
# installiert und fertig herüberkopiert. Zur Laufzeit ruft nichts node auf -
# bin/copy-assets.php ist PHP -, also gehört die Werkzeugkette nicht ins Abbild.
RUN apk add --no-cache \
    git \
    curl \
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

# Die Frontend-Abhängigkeiten kommen fertig aus der assets-Stufe. Sie werden
# gleich von bin/copy-assets.php nach public/vendor/ ausgelesen.
COPY --from=assets /assets/node_modules ./node_modules

# Install PHP dependencies. --no-scripts: composer.json's post-install-cmd
# runs bin/copy-assets.php, which needs the app source tree (COPY . . below)
# and would fail this early. The explicit copy-assets RUN step further down
# already runs it at the correct point in the build.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application code
COPY . .

# Now run the copy-assets script
RUN php bin/copy-assets.php

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY bin/mail-queue-worker.sh /usr/local/bin/mail-queue-worker.sh
COPY bin/registration-reminder-worker.sh /usr/local/bin/registration-reminder-worker.sh
COPY bin/notification-reminder-worker.sh /usr/local/bin/notification-reminder-worker.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/mail-queue-worker.sh \
    /usr/local/bin/registration-reminder-worker.sh /usr/local/bin/notification-reminder-worker.sh

# PHP upload limits are set to unlimited (0) because the Nginx layer already
# enforces the effective request body size limit via the fixed
# client_max_body_size in nginx.conf (see dist/README.md for the SWAG-side
# limit that must match).
# memory_limit is raised to 512M so GD can decode large smartphone JPEGs in RAM.
# max_execution_time and max_input_time are raised to match the Nginx
# fastcgi_read_timeout of 120s for slow mobile connections.
RUN echo "upload_max_filesize = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 120" >> /usr/local/etc/php/conf.d/uploads.ini

# The base image's php-fpm.d/docker.conf points the FastCGI access log at
# stderr, so every request adds a plain-text line to the same container stream
# that carries the structured Monolog JSON. The reverse proxy already logs every
# request with the real client IP, so this pool override drops the duplicate and
# keeps the app stream to application events. Filename starts with zz- because
# php-fpm.d/*.conf is read in alphabetical order and the last value wins.
RUN printf '[www]\naccess.log = /dev/null\n' > /usr/local/etc/php-fpm.d/zz-access-log.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
