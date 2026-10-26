ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev \
    && docker-php-ext-install -j2 mbstring dom xml xmlwriter pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . .
EXPOSE 80
