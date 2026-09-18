"""
Cartes de registres / coils Modbus par type de climatiseur GREE.

Deux familles de matériel sont gérées derrière une même passerelle Modbus TCP :

GMV (VRF centralisé)
    Plusieurs unités intérieures (UI) sont pilotées derrière un même esclave
    Modbus. Chaque UI occupe un bloc de registres/coils décalé de
    `step * (UI - 1)` par rapport à l'UI 1. C'est la logique historique de
    la plateforme (cf. web/src/config/"Table GREE").

UMATCH (splits / multi-splits)
    Une passerelle IP donne accès à plusieurs esclaves Modbus, mais
    contrairement au GMV, un esclave = une seule unité intérieure. Il n'y a
    donc pas de décalage à appliquer : les adresses sont fixes. On modélise
    quand même chaque unité avec `UI = 1` afin de rester compatible avec le
    schéma de base existant (unicité UI+ip+port+slave_id).

Toute nouvelle logique de lecture/écriture (discovery, scheduler, API web)
doit passer par ce module plutôt que par des adresses codées en dur, afin
que les deux familles de matériel restent cohérentes entre elles.
"""

GMV = "GMV"
UMATCH = "UMATCH"

EQUIPMENT_TYPES = (GMV, UMATCH)


# ---------------------------------------------------------------------------
# Défauts
# ---------------------------------------------------------------------------

# Coils défauts GMV : base 408, bloc de 17 coils, décalage 64*(UI-1).
GMV_FAULTS = {
    408: "Défaut général",
    409: "Erreur communication",
    410: "Protection unité intérieure",
    411: "Protection ventilateur indoor",
    412: "Bac à condensats plein",
    413: "Protection surintensité",
    414: "Protection anti-gel",
    415: "Conflit mode",
    416: "Défaut carte indoor",
    417: "Défaut sonde reprise",
    418: "Défaut sonde ambiance",
    419: "Défaut sonde liquide",
    420: "Défaut sonde gaz",
    421: "Défaut sonde humidité",
    422: "Conflit adresse",
    423: "Pas de master",
    424: "Défaut quantité unités",
}

# Coils défauts UMATCH : adresses fixes, non contiguës (une seule unité par
# esclave, donc pas de décalage par UI).
UMATCH_FAULTS = {
    95: "Défaut général",
    97: "Défaut capteur température évaporateur",
    98: "Défaut capteur température retour air",
    99: "Défaut capteur température afficheur",
    101: "Bac à condensats plein",
    105: "Défaut ventilateur",
    122: "Défaut communication intérieur / extérieure",
    152: "Protection haute pression",
    153: "Protection",
    154: "Protection moteur ventilateur intérieur",
    155: "Protection vanne 4 voies",
    156: "Protection surpuissance",
    157: "Protection surcharge",
    158: "Protection basse pression",
    159: "Protection haute pression",
    160: "Protection prise en glace",
    161: "Défaut capteur température extérieure",
    162: "Défaut capteur température HP",
    163: "Défaut capteur température BP",
    174: "Défaut communication cartes",
    184: "Protection tension bus DC",
    185: "Protection tension bus DC",
}
for _code in range(186, 197):
    UMATCH_FAULTS[_code] = "Protection compresseur"
del _code


# ---------------------------------------------------------------------------
# Modes / vitesses de ventilation (numérotations différentes selon le type)
# ---------------------------------------------------------------------------

MODES = {
    GMV: {
        1: "Froid",
        2: "Déshumidification",
        3: "Ventilation",
        4: "Chauffage",
        5: "Auto",
    },
    UMATCH: {
        1: "Froid",
        2: "Chauffage",
        3: "Déshumidification",
        4: "Ventilation",
        5: "Auto",
    },
}

# Vitesses de ventilation à l'écriture (la lecture peut renvoyer une échelle
# légèrement différente côté GMV, cf. Table GREE - non géré ici, valeurs
# d'écriture uniquement).
FAN_SPEEDS = {
    GMV: {
        1: "Auto",
        2: "Faible",
        3: "Moyen faible",
        4: "Moyen",
        5: "Moyen fort",
        6: "Fort",
        7: "Turbo",
    },
    UMATCH: {
        0: "Auto",
        1: "Vitesse 1",
        2: "Vitesse 2",
        3: "Vitesse 3",
        4: "Vitesse 4",
        5: "Vitesse 5",
        6: "Turbo",
    },
}


# ---------------------------------------------------------------------------
# Cartes de registres
# ---------------------------------------------------------------------------

REGISTER_MAPS = {
    GMV: {
        # Registres "par UI", décalage step*(UI-1)
        "cmd": 102,
        "mode": 103,
        "setpoint": 104,
        "fan": 105,
        "low_limit": 106,
        "high_limit": 107,
        "return_temp": 113,
        "power": 123,
        "step": 25,

        # Coils "shield" (verrouillages commande filaire), décalage
        # shield_step*(UI-1). Ordre du bloc : energy, setpoint, mode,
        # power(on/off), lock.
        "shield_base": 440,
        "shield_step": 64,
        "shield_order": ["energy", "setpoint", "mode", "power", "lock"],

        # Coils défauts, décalage fault_step*(UI-1)
        "fault_base": 408,
        "fault_step": 64,
        "fault_count": 17,
        "faults": GMV_FAULTS,

        # Température extérieure : registre "système" (6507), non décalé
        # par UI dans l'implémentation actuelle (une seule unité extérieure
        # supposée par esclave).
        "outside_temp_register": 6507,
    },
    UMATCH: {
        # Adresses fixes : 1 esclave = 1 unité, aucun décalage.
        "cmd": 2,
        "return_temp": 4,
        "mode": 17,
        "fan": 19,
        "setpoint": 20,
        "low_limit": 35,
        "high_limit": 36,
        "power": None,           # pas de registre puissance connu
        "step": 0,

        # Coils "shield" : adresses fixes, PAS dans le même ordre que GMV.
        "shield_coils": {
            "lock": 17,
            "setpoint": 18,
            "mode": 19,
            "power": 20,
            "energy": 21,
        },
        "shield_order": ["energy", "setpoint", "mode", "power", "lock"],

        # Défauts non contigus : on lit un bloc unique [95..196] (102 coils)
        # et on filtre les adresses présentes dans UMATCH_FAULTS.
        "fault_scan_start": 95,
        "fault_scan_count": 102,
        "faults": UMATCH_FAULTS,

        "outside_temp_register": 49,   # lu directement sur l'esclave

        # Registre utilisé pour tester la présence d'une unité lors du scan
        # (1 esclave par IP ; on lit ON/OFF pour vérifier qu'il répond).
        "presence_probe_register": 2,
    },
}


def _map(eq_type):
    try:
        return REGISTER_MAPS[eq_type]
    except KeyError:
        raise ValueError(f"Type d'équipement inconnu: {eq_type!r}")


def _offset(eq_type, ui):
    return _map(eq_type)["step"] * (int(ui) - 1)


def cmd_register(eq_type, ui):
    return _map(eq_type)["cmd"] + _offset(eq_type, ui)


def mode_register(eq_type, ui):
    return _map(eq_type)["mode"] + _offset(eq_type, ui)


def setpoint_register(eq_type, ui):
    return _map(eq_type)["setpoint"] + _offset(eq_type, ui)


def fan_register(eq_type, ui):
    return _map(eq_type)["fan"] + _offset(eq_type, ui)


def low_limit_register(eq_type, ui):
    return _map(eq_type)["low_limit"] + _offset(eq_type, ui)


def high_limit_register(eq_type, ui):
    return _map(eq_type)["high_limit"] + _offset(eq_type, ui)


def return_temp_register(eq_type, ui):
    return _map(eq_type)["return_temp"] + _offset(eq_type, ui)


def power_register(eq_type, ui):
    """Registre de puissance nominale de l'unité (None si non disponible)."""
    base = _map(eq_type).get("power")
    if base is None:
        return None
    return base + _offset(eq_type, ui)


def outside_temp_register(eq_type, ui):
    """
    Registre de température extérieure.

    - GMV : registre "système", fixe (pas de décalage par UI dans cette
      implémentation).
    - UMATCH : lu directement sur l'esclave de l'unité (pas de décalage,
      chaque split ayant son propre capteur extérieur).
    """
    return _map(eq_type)["outside_temp_register"]


def shield_address(eq_type, ui, key):
    """
    Adresse du coil "shield"/verrouillage correspondant à `key`
    ("energy", "setpoint", "mode", "power", "lock").
    """
    m = _map(eq_type)
    if "shield_coils" in m:
        return m["shield_coils"][key]
    base = m["shield_base"] + m["shield_step"] * (int(ui) - 1)
    index = m["shield_order"].index(key)
    return base + index


def shield_order(eq_type):
    """Ordre sémantique standard utilisé par l'UI web pour les 5 cases à cocher."""
    return _map(eq_type)["shield_order"]


def fault_addresses(eq_type, ui):
    """
    Retourne la liste ordonnée [(adresse, libellé), ...] des coils de défaut
    à surveiller pour une unité donnée.
    """
    m = _map(eq_type)
    faults = m["faults"]

    if "fault_base" in m:
        base = m["fault_base"] + m["fault_step"] * (int(ui) - 1)
        return [(base + i, label) for i, (code, label) in enumerate(sorted(faults.items()))]

    # Adresses fixes (UMATCH) : pas de décalage par UI.
    return [(code, label) for code, label in sorted(faults.items())]


def fault_scan_range(eq_type, ui):
    """
    Bloc de coils à lire en une seule requête pour couvrir tous les défauts
    d'une unité. Retourne (adresse_depart, nombre_de_coils).
    """
    m = _map(eq_type)

    if "fault_base" in m:
        base = m["fault_base"] + m["fault_step"] * (int(ui) - 1)
        return base, m["fault_count"]

    return m["fault_scan_start"], m["fault_scan_count"]


def presence_probe_register(eq_type):
    """Registre lu pour confirmer la présence d'une unité UMATCH sur un esclave."""
    return _map(eq_type).get("presence_probe_register")
