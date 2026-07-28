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


