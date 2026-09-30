# Installation

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

## Types de climatiseurs (GMV / U-Match)

Depuis la page **Détection** (`discovered_units.php`), chaque passerelle Modbus est déclarée
avec un type :

- **GMV** : système centralisé, plusieurs unités intérieures derrière un même esclave
  (scan des bits de présence) ;
- **U-Match** : splits/multi-splits, un esclave Modbus par unité derrière la passerelle IP
  (scan direct des esclaves déclarés).

Le type conditionne à la fois la méthode de découverte et les adresses Modbus utilisées pour
le pilotage et la télémétrie (voir `shared/registers.py`). Une passerelle existante peut être
modifiée à tout moment depuis cette page ; le type des équipements déjà importés reste celui
choisi au moment de leur ajout (modifiable directement en base si besoin).

## Node-RED

Un service Node-RED est fourni pour construire des automatismes ou tableaux de bord
complémentaires à partir du hub Modbus existant, sans aucune modification de celui-ci.

### Accès

```text
http://<IP_SERVEUR>:1880
```
(accès réseau interne uniquement, pas de HTTPS via le reverse proxy)

### Permissions du volume de données

L'image `nodered/node-red` tourne normalement avec un utilisateur non-root (uid/gid 1000).
Si `./nodered/data` a été créé par Docker (root) avant le premier démarrage, cela provoque :
```text
Error: EACCES: permission denied, mkdir '/data/node_modules'
```
Le `docker-compose.yml` fixe `user: root` sur le service `node-red` : l'entrypoint officiel
de l'image détecte ce cas, corrige lui-même les permissions de `/data`, puis repasse en
utilisateur `node-red` avant de démarrer l'application. Aucune action manuelle n'est
nécessaire ; il suffit de relancer :
```bash
docker compose up -d node-red
```

### Appeler le hub Modbus depuis un flow

Depuis un nœud "http request" (nœud du cœur de Node-RED, rien à installer) :

- Lecture : `POST http://modbus-hub:8500/read` avec un corps JSON
  `{"ip":"...","port":502,"device_id":1,"address":102,"count":5,"type":"register"}` ;
- Écriture : `POST http://modbus-hub:8500/write` avec un corps JSON
  `{"ip":"...","port":502,"slave":1,"address":102,"value":170,"type":"register"}`.

Le fichier `nodered/exemple-flow-modbus-hub.json` fournit un flow d'exemple prêt à
importer (lecture et écriture) : dans l'éditeur Node-RED, menu ≡ → Import → coller le
contenu du fichier, puis adapter l'IP/port/slave/adresse dans les deux nœuds "function"
avant de déployer.

### Nœuds custom "GREE Modbus"

Deux nœuds dédiés sont fournis dans `nodered/nodes/node-red-contrib-gree-modbus/` et
montés automatiquement dans le conteneur (catégorie **GREE Clim** dans la palette,
à droite de l'éditeur) :

- **GREE lecture** : lit un champ (Marche/Arrêt, mode, consigne, ventilation, température
  de reprise/extérieure, puissance...) d'une unité GMV ou U-Match ;
- **GREE écriture** : écrit un champ (y compris les verrouillages "shield") sur une unité.

Les deux nœuds calculent eux-mêmes l'adresse Modbus à partir du type sélectionné (GMV /
U-Match), sans avoir à connaître les adresses de `shared/registers.py`. IP/port/esclave/UI
peuvent être fixés dans la config du nœud, ou laissés vides pour être fournis
dynamiquement via `msg.ip`/`msg.port`/`msg.slave`/`msg.ui` (utile pour boucler sur
plusieurs équipements avec un seul nœud).

Ces nœuds sont montés directement dans `/data/node_modules` (pas d'installation npm) :
un redémarrage du conteneur suffit pour qu'ils apparaissent dans la palette.
```bash
docker compose restart node-red
```

### Sécuriser l'éditeur (recommandé)

L'éditeur Node-RED permet d'exécuter du code arbitraire sur le serveur (nœud Function) : il est
fortement recommandé de le protéger par un compte avant toute utilisation durable.

```bash
docker compose run --rm node-red node-red admin hash-pw
```

Copier le hash obtenu dans `.env` :
```text
NODERED_USER=admin
NODERED_PASSWORD_HASH=<hash généré>
```

Puis redémarrer le service :
```bash
docker compose up -d node-red
```

Tant que `NODERED_PASSWORD_HASH` est vide, l'éditeur reste accessible sans authentification.