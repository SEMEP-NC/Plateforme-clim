#!/bin/bash
set -e
echo "Initialisation des dossiers documents..."
mkdir -p /var/www/html/documents/uploads
chown -R www-data:www-data /var/www/html/documents
chmod -R 755 /var/www/html/documents
echo "Démarrage Apache..."
exec apache2-foreground