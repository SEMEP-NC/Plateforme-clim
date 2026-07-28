FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    ca-certificates \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

WORKDIR /var/www/html


RUN mkdir -p \
    /var/www/storage/documents \
    /var/www/storage/certificates \
    && chown -R www-data:www-data /var/www/storage \
    && chmod -R 750 /var/www/storage


COPY entrypoint.sh /entrypoint.sh

COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini


RUN chmod +x /entrypoint.sh


ENTRYPOINT ["/entrypoint.sh"]