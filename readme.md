# Plateforme Clim GREE

Plateforme Docker permettant de détecter, déclarer, planifier et piloter des climatiseurs GREE via Modbus TCP.

Le projet fournit :

- une interface web PHP pour la détection, la configuration des équipements et les plannings ;
- une API Flask pour la découverte automatique Modbus ;
- un hub Modbus FastAPI pour centraliser les lectures et écritures ;
- un scheduler Python pour exécuter les actions programmées ;
- une base MariaDB pour conserver la configuration, les équipements, les plannings et les historiques ;
- une autorité de certification locale permettant de sécuriser l'accès HTTPS interne ;
- un service Node-RED optionnel, branché sur le hub Modbus, pour des automatismes ou tableaux de bord complémentaires.

## Types de matériel gérés

La plateforme gère deux familles de climatiseurs GREE, chacune avec sa propre carte de
registres Modbus (voir `shared/registers.py` et `web/src/lib/registers.php`) :

- **GMV** : système VRF centralisé, plusieurs unités intérieures (UI) derrière un même esclave Modbus ;
- **UMATCH** : splits / multi-splits, une passerelle IP donnant accès à plusieurs esclaves, chaque esclave pilotant une seule unité.

Le type de matériel se configure par passerelle dans la page **Détection** (`discovered_units.php`) :
chaque passerelle IP déclarée (plage IP, ports, esclaves) est associée à un type, qui détermine à la
fois la méthode de découverte et les adresses Modbus utilisées pour le pilotage et la télémétrie.

---

# Evolution

Liste des évolutions envisagées :

- Application mobile ;


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
| node-red   | Automatismes / dashboards via le hub Modbus | ${NODERED_PORT} (LAN, sans HTTPS) |








