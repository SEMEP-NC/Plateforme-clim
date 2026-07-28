#Installation

## Prérequis

Serveur recommandé :

Debian 11 ou supérieur ;
Docker Engine ;
Docker Compose v2 ;
Adresse IP fixe ;
Accès réseau aux équipements GREE.

## Installation Docker :
```bash
apt update

apt install -y docker.io docker-compose-plugin

systemctl enable docker
systemctl start docker

Vérification :

docker --version

docker compose version
```

## Installation : 
### 1 - Récupération du projet
Depuis le serveur :
```bash
git clone https://github.com/SEMEP-NC/Plateforme-clim
cd Plateforme-clim
```

### 2 - Configuration environnement

modifier le fichier .env :
```bash
nano .env
```
Modifier les paramètres :
```text
HOST_IP=<nom DNS>

DB_NAME=clim
DB_USER=clim_user
DB_PASSWORD=mot_de_passe

MYSQL_ROOT_PASSWORD=mot_de_passe_root

SCHEDULER_INTERVAL=60
DISCOVERY_INTERVAL=300
```

### Génération de l'autorité de certification locale

La plateforme utilise une CA interne pour générer les certificats HTTPS.

Lancement :
```bash
docker compose run --rm ca
```bash
Le certificat racine est généré :
```text
ca/data/root_ca.crt
```
Ce certificat doit être installé sur les postes utilisateurs afin d'éviter les alertes navigateur.
Pour les utilisateurs windows le certificat client pourra etre téléchargé depuis la page d'administration

Les certificat serveurs sont a recuperer :
```bash
/home/Plateforme-clim/ca/data/certs
```

## Démarrage de la plateforme

### Depuis la racine :
```bash
docker compose up -d --build
```

Vérification :
```bash
docker compose ps
```
Les services doivent être en état :
```text
running
healthy
Configuration HTTPS
```

### La plateforme utilise Nginx Proxy Manager.

Accès administration :
```text
http://IP_SERVEUR:81
```
Créer un Proxy Host :

Domain Names :
```text
<nom DNS>
```
Scheme :
```text
http
```
Forward Hostname :
```text
clim_web
```
Forward Port :
```text
80
```
Activer :
```text
Block Common Exploits
Websocket Support
```
Dans SSL :

Certificate :
```text
clim-ca
```
Force SSL :
```text
Oui
```
## DNS

### Créer un enregistrement DNS interne :
```text
<nom DNS>    A    10.0.0.39
```
Le nom doit correspondre exactement au certificat généré.

## Premier accès

### Ouvrir :
```text
https://<nom DNS>
```
Installer la CA si nécessaire.

Connexion avec le compte administrateur créé lors de l'installation.

## Base de donnée
### Connexion MariaDB :
```bash
docker exec -it clim_db mariadb \
-u root \
-p
Sauvegarde
Base MariaDB
```
### Créer une sauvegarde :
```bash
docker exec clim_db mariadb-dump \
-u root \
-p clim > backup.sql
```
### Restauration :
```bash
docker exec -i clim_db mariadb \
-u root \
-p clim < backup.sql
```

## Mise à jour

### Arrêt :
```bash
docker compose down
```
### Reconstruction :
```bash
docker compose pull
docker compose build
docker compose up -d
```