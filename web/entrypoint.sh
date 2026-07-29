#!/bin/bash
set -e
echo "Initialisation des espaces de stockage..."
mkdir -p /var/www/storage/documents

echo "Vérification des certificats TLS..."
CERT="/etc/ssl/certs/clim.crt"
KEY="/etc/ssl/private/clim.key"

while [ ! -f "$CERT" ]; do
    echo "Attente certificat serveur..."
    sleep 5
done

while [ ! -f "$KEY" ]; do
    echo "Attente clé privée..."
    sleep 5
done

echo "Certificats TLS disponibles."

echo "Correction permissions stockage documents..."
chown -R www-data:www-data /var/www/storage/documents
chmod 750 /var/www/storage/documents

echo "Génération configuration Apache..."

envsubst '${HOST_NAME}' \
< /etc/apache2/sites-available/000-default.conf.template \
> /etc/apache2/sites-available/000-default.conf

envsubst '${HOST_NAME}' \
< /etc/apache2/sites-available/default-ssl.conf.template \
> /etc/apache2/sites-available/default-ssl.conf

echo "Configuration Apache générée :"
grep ServerName /etc/apache2/sites-available/*.conf

echo "Activation Apache SSL..."
a2enmod ssl headers rewrite
a2ensite default-ssl

echo "Test configuration Apache..."
apachectl configtest

echo "Démarrage Apache..."
exec apache2-foreground