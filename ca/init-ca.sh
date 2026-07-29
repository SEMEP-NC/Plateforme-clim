#!/bin/bash
set -e

CA_DIR="/home/step"
CERT_DIR="$CA_DIR/certs"

CA_PASSWORD_FILE="/tmp/ca_password"

echo "$CA_PASSWORD" > "$CA_PASSWORD_FILE"

mkdir -p "$CERT_DIR"


echo "=== Initialisation CA ==="


if [ ! -f "$CA_DIR/config/ca.json" ]; then

    echo "Création de la CA..."

    step ca init \
        --name "Clim Local CA" \
        --dns "clim-ca" \
        --address ":9443" \
        --provisioner "admin" \
        --password-file "$CA_PASSWORD_FILE"

else

    echo "CA existante détectée"

fi


export STEPPATH="$CA_DIR"

CA_URL="https://clim-ca:9443"


echo "=== Démarrage Smallstep CA ==="


step-ca "$CA_DIR/config/ca.json" \
    --password-file "$CA_PASSWORD_FILE" &

CA_PID=$!


echo "Attente du démarrage de la CA..."

sleep 10


if ! curl -k "$CA_URL/health"; then
    echo "Erreur: Smallstep CA non démarrée"
    kill $CA_PID
    exit 1
fi


echo ""
echo "=== Génération certificat serveur ==="


if [ ! -f "$CERT_DIR/clim.crt" ]; then

    step ca certificate \
        "$SERVER_IP" \
        "$CERT_DIR/clim.crt" \
        "$CERT_DIR/clim.key" \
        --ca-url "$CA_URL" \
        --root "$CA_DIR/certs/root_ca.crt" \
        --provisioner admin \
        --provisioner-password-file "$CA_PASSWORD_FILE"

else

    echo "Certificat existant"

fi


echo ""
echo "=== Export des certificats ==="

EXPORT_DIR="/export"

mkdir -p "$EXPORT_DIR"


# Root CA pour les postes clients
cp "$CA_DIR/certs/root_ca.crt" \
   "$EXPORT_DIR/root_ca.crt"


# Certificat serveur Apache
cp "$CERT_DIR/clim.crt" \
   "$EXPORT_DIR/server.crt"


# Clé privée Apache
cp "$CERT_DIR/clim.key" \
   "$EXPORT_DIR/server.key"


# Permissions

chmod 644 "$EXPORT_DIR/root_ca.crt"
chmod 644 "$EXPORT_DIR/server.crt"
chmod 640 "$EXPORT_DIR/server.key"


echo ""
echo "================================"
echo "Certificats exportés"
echo ""
ls -l "$EXPORT_DIR"
echo ""
echo "Root CA:"
echo "$EXPORT_DIR/root_ca.crt"
echo ""
echo "Certificat serveur:"
echo "$EXPORT_DIR/server.crt"
echo ""
echo "Clé privée:"
echo "$EXPORT_DIR/server.key"
echo "================================"