FROM php:8.1-apache

# Минимальные зависимости - только самое необходимое
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli gd zip

RUN a2enmod rewrite
COPY ./app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

WORKDIR /var/www/html
EXPOSE 80
