# Plateforme Clim GREE

Plateforme Docker pour detecter, declarer, planifier et piloter des climatiseurs via Modbus TCP.

Le projet fournit:

- une interface web PHP pour la detection, les equipements et les plannings;
- une API Flask pour lancer la detection Modbus;
- un hub Modbus FastAPI pour centraliser les lectures/ecritures;
- un scheduler Python pour executer les actions programmees;
- une base MariaDB pour conserver la configuration, les equipements, les plannings et les logs.

## Evolution 
La liste des evolutions envisagés
- passage https
- Envoi de mail sur défaut equipement
- Amelioration de l'outil historique
- Mise en place d'un affichage sous forme de calendrier du planning

## Architecture

```text
                 +--------------------+
                 |  Interface Web PHP |
                 |  port 8085         |
                 +---------+----------+
                           |
                           v
                    +------+------+
                    |  MariaDB    |
                    |  clim_db    |
                    +------+------+
                           ^
                           |
        +------------------+------------------+
        |                                     |
+-------+--------+                    +-------+--------+
| API Flask      |                    | Scheduler      |
| clim_api:5001  |                    | clim_scheduler |
+-------+--------+                    +-------+--------+
        |                                     |
        +------------------+------------------+
                           |
                           v
                 +---------+----------+
                 | Modbus Hub FastAPI |
                 | port 8500          |
                 +---------+----------+
                           |
                           v
                    Passerelle Modbus/GREE GMV
```

## Services

| Service | Role | Port |
| --- | --- | --- |
| `web` | Interface utilisateur PHP/Apache | `8085` |
| `api` | Endpoint de detection Modbus | `5001` |
| `modbus-hub` | Lectures/ecritures Modbus TCP | `8500` |
| `scheduler` | Execution des plannings | interne |
| `db` | Base MariaDB | interne |

## Lancement

Depuis la racine du depot:

```bash
docker compose up -d --build
```

Puis ouvrir:

```text
http://localhost:8085
```

Pour consulter les logs:

```bash
docker compose logs -f web
docker compose logs -f api
docker compose logs -f modbus-hub
docker compose logs -f scheduler
```

## Configuration

Les variables principales sont definies dans `.env`.

| Variable | Description | Exemple |
| `API_BASE_URL` | URL interne de l'API | `http://clim_api:5001` |
| `HUB_URL` | URL de lecture du hub Modbus | `http://modbus-hub:8500/read` |
| `HUB_WRITE_URL` | URL d'ecriture du hub Modbus | `http://modbus-hub:8500/write` |
| `SCHEDULER_INTERVAL` | Intervalle de boucle scheduler en secondes | `60` |
| `DISCOVERY_INTERVAL` | Intervalle discovery automatique en secondes | `300` |

## Base de donnees

Le schema initial est dans:

```text
mysql/init.sql
```

Tables principales:

- `discovery_config`: plage IP, ports et slave IDs a scanner;
- `discovered_units`: unites detectees automatiquement;
- `equipments`: equipements ajoutes a la gestion;
- `schedules`: actions planifiees;
- `command_logs`: traces d'execution des commandes.


## Detection automatique

La detection se configure depuis:

```text
http://localhost:8085/discovered_units.php
```

Champs:

- `START IP`: premiere IP a scanner;
- `END IP`: derniere IP a scanner;
- `PORTS`: ports Modbus separes par des virgules, par exemple `502`;
- `SLAVE IDS`: slave IDs Modbus separes par des virgules, par exemple `1,2,3`.

La detection lit les coils de presence UI entre les adresses `120` et `247`, puis lit la puissance sur le registre correspondant.

## Equipements

Apres detection, les unites trouvees peuvent etre ajoutees comme equipements depuis l'interface de detection.

Chaque equipement conserve:

- un nom utilisateur;
- l'IP de passerelle Modbus;
- le port Modbus;
- le slave ID;
- le numero UI;
- la puissance detectee.

## Plannings

Les plannings sont geres depuis:

```text
http://localhost:8085/schedules.php
```

Un planning peut contenir:

- une action `ON`;
- une action `OFF`;
- aucun changement ON/OFF;
- une temperature;
- aucun changement de temperature;
- une repetition hebdomadaire sur un ou plusieurs jours.

Il faut choisir au moins une action ou une temperature.

### Fuseau horaire

L'interface web attend une heure locale `UTC+11`.

Lors de l'enregistrement:

1. l'heure saisie est interpretee en `UTC+11`;
2. elle est convertie en UTC;
3. elle est stockee dans `schedules.execution_time`.

Le scheduler compare ensuite avec:

```sql
UTC_TIMESTAMP()
```

Cela evite les decalages si MariaDB n'utilise pas le meme fuseau horaire que l'utilisateur.

### Repetition hebdomadaire

La colonne `repeat_days` contient les jours ISO:

| Valeur | Jour |
| --- | --- |
| `1` | Lundi |
| `2` | Mardi |
| `3` | Mercredi |
| `4` | Jeudi |
| `5` | Vendredi |
| `6` | Samedi |
| `7` | Dimanche |

Exemple:

```text
1,3,5
```

signifie lundi, mercredi et vendredi.

Pour un planning ponctuel, le scheduler met `executed = 1` apres execution reussie.

Pour un planning repete, le scheduler ne met pas fin au planning: il deplace `execution_time` vers la prochaine occurrence hebdomadaire.

## Hub Modbus

Le hub Modbus expose des endpoints HTTP pour les services internes et pour FUXA.

URL locale:

```text
http://localhost:8500
```

Dans Docker:

```text
http://modbus-hub:8500
```

### Healthcheck

```text
GET /health
```

Exemple:

```text
http://localhost:8500/health
```

### Lecture POST

Utilise par l'API et la discovery.

```text
POST /read
```

Payload registre:

```json
{
  "ip": "10.5.0.20",
  "port": 502,
  "device_id": 1,
  "type": "register",
  "address": 123,
  "count": 1
}
```

Payload coils:

```json
{
  "ip": "10.5.0.20",
  "port": 502,
  "device_id": 1,
  "type": "coils",
  "address": 120,
  "count": 8
}
```

### Lecture GET pour FUXA

Lire un registre:

```text
http://localhost:8500/read?ip=10.5.0.20&port=502&device_id=1&type=register&address=123
```

Lire plusieurs registres:

```text
http://localhost:8500/read?ip=10.5.0.20&port=502&device_id=1&type=register&address=123&count=4
```

Lire des coils:

```text
http://localhost:8500/read?ip=10.5.0.20&port=502&device_id=1&type=coils&address=120&count=8
```

Reponse registre unique:

```json
{
  "success": true,
  "cached": false,
  "registers": [45],
  "value": 45
}
```

Quand `count=1`, le champ `value` est ajoute pour faciliter le mapping dans FUXA.

### Ecriture POST

Utilise par le scheduler.

```text
POST /write
```

Payload registre:

```json
{
  "ip": "10.5.0.20",
  "port": 502,
  "slave": 1,
  "type": "register",
  "address": 102,
  "value": 170
}
```

Payload coil:

```json
{
  "ip": "10.5.0.20",
  "port": 502,
  "slave": 1,
  "type": "coil",
  "address": 120,
  "value": true
}
```

### Ecriture GET pour FUXA

Ecrire un registre:

```text
http://localhost:8500/write?ip=10.5.0.20&port=502&device_id=1&type=register&address=102&value=170
```

Ecrire une coil:

```text
http://localhost:8500/write?ip=10.5.0.20&port=502&device_id=1&type=coil&address=120&value=true
```

Reponse:

```json
{
  "success": true,
  "queued": true
}
```

L'ecriture est mise en file d'attente par le hub pour eviter les acces concurrents au meme equipement Modbus.

## Registres utilises

Pour une UI `n`:

| Fonction | Calcul adresse | Exemple UI 1 |
| --- | --- | --- |
| Commande ON/OFF | `102 + 25 * (n - 1)` | `102` |
| Temperature consigne | `104 + 25 * (n - 1)` | `104` |
| Puissance detectee | `123 + 25 * (n - 1)` | `123` |

Valeurs commande:

| Action | Valeur |
| --- | --- |
| `ON` | `0xAA` / `170` |
| `OFF` | `0x55` / `85` |

La temperature est envoyee en dixiemes de degre.

Exemple:

```text
24 deg C -> 240
```

## Depannage

Voir l'etat des conteneurs:

```bash
docker compose ps
```

Voir les logs du scheduler:

```bash
docker compose logs scheduler --tail=100
```

Voir les logs du hub Modbus:

```bash
docker compose logs modbus-hub --tail=100
```

Tester le hub:

```text
http://localhost:8500/health
```


