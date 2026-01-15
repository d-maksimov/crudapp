FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

RUN a2enmod rewrite
COPY ./app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

WORKDIR /var/www/html
EXPOSE 80
