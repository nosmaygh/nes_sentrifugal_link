FROM php:7.4-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions gd pdo pdo_mysql mysqli \
    && a2enmod rewrite

WORKDIR /var/www/html
