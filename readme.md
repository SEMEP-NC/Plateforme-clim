# Plateforme Clim GREE

Plateforme Docker permettant de détecter, déclarer, planifier et piloter des climatiseurs GREE via Modbus TCP.

Le projet fournit :

- une interface web PHP pour la détection, la configuration des équipements et les plannings ;
- une API Flask pour la découverte automatique Modbus ;
- un hub Modbus FastAPI pour centraliser les lectures et écritures ;
- un scheduler Python pour exécuter les actions programmées ;
- une base MariaDB pour conserver la configuration, les équipements, les plannings et les historiques ;
- une autorité de certification locale permettant de sécuriser l'accès HTTPS interne.

---

# Evolution

Liste des évolutions envisagées :

- Logs utilisateurs complets ;
- Amélioration de l'outil historique ;
- Prise en compte splits et multi-splits ;
- Amélioration supervision énergétique ;
- Notifications avancées ;
- Gestion multi-sites.

---

# Architecture

```text
                         HTTPS
                           |
                           v
                +---------------------+
                | Nginx Proxy Manager |
                | clim_proxy          |
                | ports 80/443/81     |
                +----------+----------+
                           |
                           |
                           v
                 +---------+----------+
                 | Interface Web PHP  |
                 | clim_web           |
                 +---------+----------+
                           |
                           v
                    +------+------+
                    | MariaDB    |
                    | clim_db    |
                    +------+------+
                           ^
                           |
        +------------------+------------------+
        |                                     |
+-------+--------+                    +-------+--------+
| API Flask      |                    | Scheduler      |
| clim_api       |                    | clim_scheduler |
+-------+--------+                    +-------+--------+
        |                                     |
        +------------------+------------------+
                           |
                           v
                 +---------+----------+
                 | Modbus Hub FastAPI |
                 | modbus_hub         |
                 +---------+----------+
                           |
                           v

                 Passerelle Modbus GREE
```

## Services

| Service    | Rôle                             | Port      |
| ---------- | -------------------------------- | --------- |
| web        | Interface utilisateur PHP/Apache | interne   |
| api        | Détection Modbus                 | interne   |
| modbus-hub | Communication Modbus TCP         | interne   |
| scheduler  | Exécution des automatismes       | interne   |
| db         | Base MariaDB                     | interne   |
| proxy      | Reverse proxy HTTPS              | 80/443/81 |
| ca         | Autorité de certification locale | interne   |


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
git clone <repository>
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
```
Le certificat racine est généré :
```text
ca/data/root_ca.crt
```
Ce certificat doit être installé sur les postes utilisateurs afin d'éviter les alertes navigateur.
Pour les utilisateurs windows le certificat client pourra etre téléchargé depuis la page d'administration

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


## Debug connu
Plantage scheduler si aucune configuration mail ;
Amélioration de la gestion des erreurs Modbus en cours.

---

Je vous conseille aussi d'ajouter un fichier **`.env.example`** dans le dépôt, car actuellement le README explique les variables mais un nouvel installateur ne sait pas quelles valeurs sont obligatoires.

Autre amélioration importante : ajouter un dossier :


docs/
├── INSTALLATION.md
├── ADMINISTRATION.md
├── DEPANNAGE.md
└── SECURITE.md


