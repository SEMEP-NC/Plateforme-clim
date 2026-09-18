<?php
/**
 * Cartes de registres / coils Modbus par type de climatiseur GREE.
 *
 * Équivalent PHP de shared/registers.py (utilisé côté Python par le
 * scheduler et la découverte). Les deux fichiers doivent rester
 * cohérents entre eux si la table de registres évolue.
 *
 * GMV    : plusieurs UI derrière un même esclave, adresses décalées de
 *          step*(UI-1) par rapport à l'UI 1.
 * UMATCH : 1 esclave = 1 unité, adresses fixes (UI toujours = 1).
 */

const REGISTER_TYPE_GMV = 'GMV';
const REGISTER_TYPE_UMATCH = 'UMATCH';

const REGISTER_MAPS = [
    'GMV' => [
        'cmd' => 102,
        'mode' => 103,
        'setpoint' => 104,
        'fan' => 105,
        'low_limit' => 106,
        'high_limit' => 107,
        'return_temp' => 113,
        'step' => 25,

        // Coils "shield" : bloc contigu, ordre energy/setpoint/mode/power/lock
        'shield_base' => 440,
        'shield_step' => 64,
        'shield_order' => ['energy', 'setpoint', 'mode', 'power', 'lock'],

        'fault_base' => 408,
        'fault_step' => 64,
        'fault_count' => 17,
    ],
    'UMATCH' => [
        'cmd' => 2,
        'return_temp' => 4,
        'mode' => 17,
        'fan' => 19,
        'setpoint' => 20,
        'low_limit' => 35,
        'high_limit' => 36,
        'step' => 0,

        // Coils "shield" : adresses fixes, PAS dans le même ordre que GMV.
        'shield_coils' => [
            'lock' => 17,
            'setpoint' => 18,
            'mode' => 19,
            'power' => 20,
            'energy' => 21,
        ],
        'shield_order' => ['energy', 'setpoint', 'mode', 'power', 'lock'],

        'fault_scan_start' => 95,
        'fault_scan_count' => 102,
    ],
];

// Numérotations différentes selon le type de matériel.
const MODES = [
    'GMV' => [
        1 => 'Froid',
        2 => 'Déshumidification',
        3 => 'Ventilation',
        4 => 'Chauffage',
        5 => 'Auto',
    ],
    'UMATCH' => [
        1 => 'Froid',
        2 => 'Chauffage',
        3 => 'Déshumidification',
        4 => 'Ventilation',
        5 => 'Auto',
    ],
];

const FAN_SPEEDS = [
    'GMV' => [
        1 => 'Auto',
        2 => 'Faible',
        3 => 'Moyen faible',
        4 => 'Moyen',
        5 => 'Moyen fort',
        6 => 'Fort',
        7 => 'Turbo',
    ],
    'UMATCH' => [
        0 => 'Auto',
        1 => 'Vitesse 1',
        2 => 'Vitesse 2',
        3 => 'Vitesse 3',
        4 => 'Vitesse 4',
        5 => 'Vitesse 5',
        6 => 'Turbo',
    ],
];

function reg_type($type) {
    return array_key_exists($type, REGISTER_MAPS) ? $type : REGISTER_TYPE_GMV;
}

function reg_offset($type, $ui) {
    $m = REGISTER_MAPS[reg_type($type)];
    return $m['step'] * ((int)$ui - 1);
}

function reg_cmd($type, $ui) {
    $m = REGISTER_MAPS[reg_type($type)];
    return $m['cmd'] + reg_offset($type, $ui);
}

function reg_mode($type, $ui) {
    $m = REGISTER_MAPS[reg_type($type)];
    return $m['mode'] + reg_offset($type, $ui);
}

function reg_setpoint($type, $ui) {
    $m = REGISTER_MAPS[reg_type($type)];
    return $m['setpoint'] + reg_offset($type, $ui);
}

function reg_fan($type, $ui) {
    $m = REGISTER_MAPS[reg_type($type)];
    return $m['fan'] + reg_offset($type, $ui);
}

function reg_low_limit($type, $ui) {
    $m = REGISTER_MAPS[reg_type($type)];
    return $m['low_limit'] + reg_offset($type, $ui);
}

/**
 * Adresses des 5 registres pilotés par la modale de commande, dans l'ordre
 * historique [power, mode, setpoint, fan, min_setpoint].
 */
function reg_command_addresses($type, $ui) {
    return [
        'power' => reg_cmd($type, $ui),
        'mode' => reg_mode($type, $ui),
        'setpoint' => reg_setpoint($type, $ui),
        'fan' => reg_fan($type, $ui),
        'min_setpoint' => reg_low_limit($type, $ui),
    ];
}

/**
 * Adresse du coil "shield"/verrouillage pour la clé sémantique donnée
 * (energy, setpoint, mode, power, lock).
 */
function reg_shield_address($type, $ui, $key) {
    $type = reg_type($type);
    $m = REGISTER_MAPS[$type];

    if (isset($m['shield_coils'])) {
        return $m['shield_coils'][$key];
    }

    $base = $m['shield_base'] + $m['shield_step'] * ((int)$ui - 1);
    $index = array_search($key, $m['shield_order']);

    return $base + $index;
}

/**
 * Ordre sémantique standard des 5 cases à cocher "shield" côté interface web.
 */
function reg_shield_order($type) {
    return REGISTER_MAPS[reg_type($type)]['shield_order'];
}

/**
 * Adresses des 5 coils "shield" dans l'ordre reg_shield_order().
 */
function reg_shield_addresses($type, $ui) {
    $type = reg_type($type);
    $addresses = [];

    foreach (reg_shield_order($type) as $key) {
        $addresses[] = reg_shield_address($type, $ui, $key);
    }

    return $addresses;
}
