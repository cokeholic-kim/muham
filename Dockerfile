FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql

RUN a2enmod rewrite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/server-name.conf \
    && a2enconf server-name

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
