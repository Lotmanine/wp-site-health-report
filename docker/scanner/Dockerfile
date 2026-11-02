FROM composer:2 AS deps
WORKDIR /build
COPY scanner/ ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs --no-scripts

FROM php:8.5.12-apache
RUN a2enmod rewrite
COPY docker/scanner/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/wordpress/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/scanner/php.ini /usr/local/etc/php/conf.d/scanner.ini
COPY docker/scanner/entrypoint.sh /usr/local/bin/scanner-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/scanner-entrypoint.sh \
 && chmod +x /usr/local/bin/scanner-entrypoint.sh \
 && echo 'Listen 8080' > /etc/apache2/ports.conf

COPY --from=deps /build /var/www/html
RUN rm -rf /var/www/html/bootstrap/cache/*.php \
 && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
 && mkdir -p /data && chown www-data:www-data /data

ENV DB_CONNECTION=sqlite DB_DATABASE=/data/scanner.sqlite QUEUE_CONNECTION=database CACHE_STORE=file \
    SESSION_DRIVER=array LOG_CHANNEL=stderr APP_ENV=production APP_DEBUG=false

ENTRYPOINT ["/usr/local/bin/scanner-entrypoint.sh"]
CMD ["apache2-foreground"]
