FROM composer:2 AS deps
WORKDIR /build
COPY wordpress/plugin/oldev-health-report/composer.json wordpress/plugin/oldev-health-report/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs --no-scripts

FROM wordpress:7.1.4-php8.5-apache
COPY --from=wordpress:cli-2.12.0-php8.5 /usr/local/bin/wp /usr/local/bin/wp

RUN a2enmod headers proxy proxy_http \
 && sed -i -E 's#^(\s*CustomLog .* combined)$#\1 env=!dontlog#' /etc/apache2/sites-available/000-default.conf

COPY docker/wordpress/shr-apache.conf /etc/apache2/conf-enabled/shr.conf
COPY docker/wordpress/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/wordpress/shr-php.ini /usr/local/etc/php/conf.d/shr.ini
COPY docker/wordpress/shr-entrypoint.sh /usr/local/bin/shr-entrypoint.sh
COPY docker/wordpress/shr-bootstrap.sh /usr/local/bin/shr-bootstrap.sh
COPY docker/wordpress/wordfence-waf.php docker/wordpress/wordfence-config.php docker/wordpress/shr-remoteip.php /opt/shr/
RUN sed -i 's/\r$//' /usr/local/bin/shr-entrypoint.sh /usr/local/bin/shr-bootstrap.sh \
 && chmod +x /usr/local/bin/shr-entrypoint.sh /usr/local/bin/shr-bootstrap.sh

COPY wordpress/plugin/oldev-health-report/oldev-health-report.php /opt/shr/plugin/
COPY wordpress/plugin/oldev-health-report/src /opt/shr/plugin/src
COPY wordpress/plugin/oldev-health-report/blocks /opt/shr/plugin/blocks
COPY wordpress/plugin/oldev-health-report/assets /opt/shr/plugin/assets
COPY --from=deps /build/vendor /opt/shr/plugin/vendor
COPY wordpress/theme/oldev-health /opt/shr/theme

ENTRYPOINT ["/usr/local/bin/shr-entrypoint.sh"]
CMD ["apache2-foreground"]
