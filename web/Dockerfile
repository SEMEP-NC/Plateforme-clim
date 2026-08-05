FROM php:8.3-apache
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    ca-certificates \
    gettext-base \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite ssl headers

WORKDIR /var/www/html

RUN mkdir -p \
    /var/www/storage/documents \
    /var/www/storage/certificates \
    /var/www/storage/tmp_backups \
    && chown -R www-data:www-data /var/www/storage/documents \
    && chown -R www-data:www-data /var/www/storage/tmp_backups \
    && chmod 751 /var/www/storage \
    && chmod 751 /var/www/storage/certificates \
    && chmod 750 /var/www/storage/documents \
    && chmod 700 /var/www/storage/tmp_backups \
    && chmod 700 /etc/ssl/private

COPY entrypoint.sh /entrypoint.sh
COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY apache/000-default.conf.template \
     /etc/apache2/sites-available/000-default.conf.template

COPY apache/default-ssl.conf.template \
     /etc/apache2/sites-available/default-ssl.conf.template

RUN a2ensite default-ssl \
    && chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]