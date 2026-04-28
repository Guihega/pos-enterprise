# ==============================================================
# POS Enterprise - Backend PHP-FPM
# ==============================================================
# Multi-stage:
#   - base         común a dev y prod
#   - development  con Xdebug, sin opcache agresivo
#   - composer     instalación de dependencias
#   - production   imagen final ligera
# ==============================================================

# ------------------------------------------------------------
# Stage: base
# ------------------------------------------------------------
FROM php:8.3-fpm-alpine AS base

ARG WWW_USER_ID=1000
ARG WWW_GROUP_ID=1000

RUN apk add --no-cache \
        bash \
        git \
        curl \
        unzip \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        postgresql-client \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libxml2-dev \
        linux-headers \
        autoconf \
        gcc \
        g++ \
        make \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        bcmath \
        zip \
        gd \
        opcache \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf gcc g++ make linux-headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuario no-root para igualar permisos con el host (Linux)
RUN addgroup -g ${WWW_GROUP_ID} appuser \
    && adduser -u ${WWW_USER_ID} -G appuser -s /bin/bash -D appuser \
    && mkdir -p /var/www/html /home/appuser/.composer \
    && chown -R appuser:appuser /var/www/html /home/appuser

WORKDIR /var/www/html

# ------------------------------------------------------------
# Stage: development
# ------------------------------------------------------------
FROM base AS development

# Xdebug solo en desarrollo
RUN apk add --no-cache --virtual .build-deps autoconf gcc g++ make linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Para que el contenedor escriba archivos con uid del host
USER appuser

EXPOSE 9000
CMD ["php-fpm", "-F"]

# ------------------------------------------------------------
# Stage: composer (sólo build de vendor)
# ------------------------------------------------------------
FROM base AS composer-build

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ------------------------------------------------------------
# Stage: production
# ------------------------------------------------------------
FROM base AS production

# Opcache optimizado para producción
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-php.ini

# Código + vendor instalado en stage anterior
COPY --from=composer-build --chown=appuser:appuser /var/www/html /var/www/html

USER appuser

EXPOSE 9000

# Healthcheck para orquestador
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php artisan health:check || exit 1

CMD ["php-fpm", "-F"]
