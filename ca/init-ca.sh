#!/bin/bash
set -euo pipefail

CA_DIR="/home/step"
CERT_DIR="$CA_DIR/certs"
EXPORT_DIR="/export"

CA_PASSWORD_FILE="/tmp/ca_password"

echo "$CA_PASSWORD" > "$CA_PASSWORD_FILE"

mkdir -p "$CERT_DIR" "$EXPORT_DIR"

echo "=== Initialisation CA ==="

if [ ! -f "$CA_DIR/config/ca.json" ]; then

    echo "Création de la CA..."

    step ca init \
        --name "Clim Local CA" \
        --dns "clim-ca" \
        --address ":9443" \
        --provisioner admin \
        --password-file "$CA_PASSWORD_FILE"

else

    echo "CA existante détectée"

fi

export STEPPATH="$CA_DIR"

CA_URL="https://clim-ca:9443"

echo ""
echo "=== Démarrage Smallstep CA ==="

step-ca "$CA_DIR/config/ca.json" \
    --password-file "$CA_PASSWORD_FILE" &

CA_PID=$!

echo "Attente de la disponibilité de la CA..."

until curl -sk "$CA_URL/health" >/dev/null
do
    sleep 1
done

echo "CA opérationnelle"

NEWCERT=0
RENEW=0

echo ""
echo "=== Vérification certificat ==="

if [ ! -f "$CERT_DIR/clim.crt" ] || [ ! -f "$CERT_DIR/clim.key" ]; then

    echo "Certificat absent"

    NEWCERT=1

elif openssl x509 -checkend 604800 -noout \
        -in "$CERT_DIR/clim.crt"
then

    echo "Certificat valide"

else

    echo "Certificat à renouveler"

    RENEW=1

fi


########################################################
# Création
########################################################

if [ "$NEWCERT" -eq 1 ]; then

    echo "Création du certificat..."

    step ca certificate \
        "$HOST_NAME" \
        "$CERT_DIR/clim.crt" \
        "$CERT_DIR/clim.key" \
        --ca-url "$CA_URL" \
        --root "$CA_DIR/certs/root_ca.crt" \
        --provisioner admin \
        --provisioner-password-file "$CA_PASSWORD_FILE"

fi


########################################################
# Renouvellement
########################################################

if [ "$RENEW" -eq 1 ]; then

    echo "Renouvellement..."

    if ! step ca renew \
        "$CERT_DIR/clim.crt" \
        "$CERT_DIR/clim.key" \
        --ca-url "$CA_URL" \
        --root "$CA_DIR/certs/root_ca.crt" \
        --provisioner admin \
        --provisioner-password-file "$CA_PASSWORD_FILE"
    then

        echo "Renew impossible."

        echo "Réémission..."

        rm -f \
            "$CERT_DIR/clim.crt" \
            "$CERT_DIR/clim.key"

        step ca certificate \
            "$HOST_NAME" \
            "$CERT_DIR/clim.crt" \
            "$CERT_DIR/clim.key" \
            --ca-url "$CA_URL" \
            --root "$CA_DIR/certs/root_ca.crt" \
            --provisioner admin \
            --provisioner-password-file "$CA_PASSWORD_FILE"

    fi

fi


########################################################
# Export
########################################################

cp "$CA_DIR/certs/root_ca.crt" \
   "$EXPORT_DIR/root_ca.crt"

cp "$CERT_DIR/clim.crt" \
   "$EXPORT_DIR/server.crt"

cp "$CERT_DIR/clim.key" \
   "$EXPORT_DIR/server.key"

chmod 644 "$EXPORT_DIR/root_ca.crt"
chmod 644 "$EXPORT_DIR/server.crt"
chmod 640 "$EXPORT_DIR/server.key"

echo ""
echo "========================================"
echo "Certificats exportés"
echo "========================================"

openssl x509 \
    -in "$EXPORT_DIR/server.crt" \
    -noout \
    -dates

echo ""
echo "CA prête"

########################################################
# Garde step-ca au premier plan
########################################################

wait $CA_PID