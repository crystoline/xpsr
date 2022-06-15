FROM php:7.4.22-apache-buster

RUN apt-get update
RUN apt-get install -y git libmcrypt-dev openssl libzip-dev zip libicu-dev zlib1g-dev g++ libpq-dev libssl-dev \
        && rm -rf /var/lib/apt/lists/*
RUN pecl channel-update pecl.php.net && pecl install mcrypt-1.0.3 && docker-php-ext-enable mcrypt
RUN docker-php-ext-install mysqli pdo pdo_mysql zip gettext intl

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY ./ /var/www/html/

RUN composer install
RUN a2enmod rewrite && service apache2 restart

WORKDIR /var/www/html/