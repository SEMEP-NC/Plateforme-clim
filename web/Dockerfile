FROM php:8.3-apache
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    ca-certificates \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite ssl headers

WORKDIR /var/www/html

RUN mkdir -p \
    /var/www/storage/documents \
    /var/www/storage/certificates \
    && chown -R www-data:www-data /var/www/storage/documents \
    && chmod 750 /var/www/storage \
    && chmod 750 /var/www/storage/documents

COPY entrypoint.sh /entrypoint.sh
COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY apache/000-default.conf \
     /etc/apache2/sites-available/000-default.conf
COPY apache/default-ssl.conf \
     /etc/apache2/sites-available/default-ssl.conf

RUN a2ensite default-ssl \
    && chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]