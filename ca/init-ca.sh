#!/bin/bash
set -e

CA_DIR="/home/step"
CERT_DIR="/home/step/certs"

CA_PASSWORD_FILE="/tmp/ca_password"

echo "$CA_PASSWORD" > "$CA_PASSWORD_FILE"

mkdir -p "$CERT_DIR"


echo "=== Initialisation CA ==="


if [ ! -f "$CA_DIR/config/ca.json" ]; then

    echo "Création de la CA..."

    step ca init \
        --name "Clim Local CA" \
        --dns "clim-ca" \
        --address ":9000" \
        --provisioner "admin" \
        --password-file "$CA_PASSWORD_FILE"

else

    echo "CA existante détectée"

fi


export STEPPATH=$CA_DIR

CA_URL="https://localhost:9000"


echo "=== Démarrage Smallstep CA ==="


step-ca "$CA_DIR/config/ca.json" &
CA_PID=$!


echo "Attente du démarrage de la CA..."

sleep 5


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



cp "$CA_DIR/certs/root_ca.crt" \
   "$CA_DIR/root_ca.crt"



echo ""
echo "================================"
echo "Certificats générés"
echo ""
echo "Certificat serveur:"
echo "$CERT_DIR/clim.crt"
echo ""
echo "Clé privée:"
echo "$CERT_DIR/clim.key"
echo ""
echo "CA utilisateur:"
echo "$CA_DIR/root_ca.crt"
echo "================================"


# garde le conteneur actif tant que step-ca tourne
wait $CA_PID