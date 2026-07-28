#!/bin/bash
set -e

echo "Initialisation stockage..."

mkdir -p /var/www/storage/documents

echo "Permissions documents..."
chown -R www-data:www-data /var/www/storage/documents
chmod 750 /var/www/storage
chmod 750 /var/www/storage/documents

if [ -f /var/www/storage/certificates/root_ca.crt ]; then
    echo "CA détectée"
    chmod 644 /var/www/storage/certificates/root_ca.crt
else
    echo "Attention : root_ca.crt absent"
fi

echo "Démarrage Apache..."
exec apache2-foreground