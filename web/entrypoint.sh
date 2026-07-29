#!/bin/bash
set -e
echo "Initialisation des espaces de stockage..."
mkdir -p /var/www/storage/documents

echo "Vérification des certificats TLS..."
CERT="/var/www/storage/certificates/server.crt"
KEY="/var/www/storage/certificates/server.key"

while [ ! -f "$CERT" ]; do
    echo "Attente certificat serveur..."
    sleep 5
done

while [ ! -f "$KEY" ]; do
    echo "Attente clé privée..."
    sleep 5
done

echo "Certificats TLS disponibles."
echo "Installation certificats Apache."
cp "$CERT" /etc/ssl/certs/clim.crt
cp "$KEY" /etc/ssl/private/clim.key

chmod 644 /etc/ssl/certs/clim.crt
chmod 600 /etc/ssl/private/clim.key

echo "Correction permissions stockage documents..."
chown -R www-data:www-data /var/www/storage/documents
chmod 750 /var/www/storage/documents

echo "Activation Apache SSL..."
a2enmod ssl headers rewrite
a2ensite default-ssl

echo "Test configuration Apache..."
apachectl configtest

echo "Démarrage Apache..."
exec apache2-foreground