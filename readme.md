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
- Logs utilisateurs (a completer)
- Alarme temperatures (implanté mais a verifier)
- Etat inter a carte
- Amelioration de l'outil historique
- Mise en place d'un affichage sous forme de calendrier du planning
- Prise en compte splits et multi splits

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
| `api` | Endpoint de detection Modbus | interne |
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
http://<ip>:8085
```


## Configuration

Les variables principales sont definies dans `.env`.

| Variable | Description | Exemple |
| `SCHEDULER_INTERVAL` | Intervalle de boucle scheduler en secondes | `60` |
| `DISCOVERY_INTERVAL` | Intervalle discovery automatique en secondes | `300` |


## Hub Modbus

Le hub Modbus expose des endpoints HTTP pour les services internes

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

### Lecture GET

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

### Ecriture GET

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


