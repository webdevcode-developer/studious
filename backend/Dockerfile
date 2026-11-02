# Studious API image (Render "Docker" runtime). curl, openssl, fileinfo and mbstring ship with the base image.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\nServerName localhost\n' > /etc/apache2/conf-available/studious.conf \
 && a2enconf studious \
 && printf 'upload_max_filesize=20M\npost_max_size=24M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/studious.ini

WORKDIR /var/www/html
COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh /var/www/html/.htaccess \
 && chmod +x /usr/local/bin/docker-entrypoint.sh \
 && mkdir -p /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80
CMD ["docker-entrypoint.sh"]
