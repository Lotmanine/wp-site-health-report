# Development tools: Composer, PHPUnit, PHPCS and PHPStan on the production PHP version.
FROM php:8.5.12-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip subversion libzip-dev libpng-dev libicu-dev mariadb-client \
 && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql zip gd intl bcmath \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=wordpress:7.1.4-php8.5-apache /usr/src/wordpress /opt/wordpress
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
