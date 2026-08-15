# Magento publishes no auth-free Docker image, and repo.magento.com requires
# Adobe Marketplace keys (401 without them). This builds a PHP image with the
# extension set Magento requires; the smoke test then installs Mage-OS, the
# auth-free Magento 2 distribution.
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libicu-dev libxml2-dev libxslt1-dev libzip-dev libsodium-dev \
        default-mysql-client unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl pdo_mysql soap sockets sodium xsl zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Magento's installer and DI compiler are memory hungry; the defaults are far
# too low and fail with an opaque exhaustion error.
RUN { echo 'memory_limit=-1'; echo 'max_execution_time=1800'; \
      echo 'realpath_cache_size=10M'; } > /usr/local/etc/php/conf.d/zz-magento.ini
