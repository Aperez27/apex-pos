# APEX — imagen para producción (Apache + PHP 8.2)
FROM php:8.2-apache

ENV TZ=America/Bogota
RUN docker-php-ext-install pdo_mysql \
 && a2enmod headers \
 && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/apex.conf \
 && a2enconf apex \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php = Off\ndate.timezone = America/Bogota\n' > "$PHP_INI_DIR/conf.d/apex.ini"

COPY --chown=www-data:www-data . /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/apex-entrypoint
RUN chmod +x /usr/local/bin/apex-entrypoint \
 && mkdir -p /var/www/html/logs \
 && chown -R www-data:www-data /var/www/html/logs

ENTRYPOINT ["apex-entrypoint"]
CMD ["apache2-foreground"]
