'use strict';

/**
 * Cartes de registres / coils Modbus GREE pour Node-RED.
 *
 * Équivalent JS de shared/registers.py (Python) et web/src/lib/registers.php.
 * Les trois fichiers doivent rester cohérents si la table évolue.
 *
 * GMV   : plusieurs UI derrière un même esclave, décalage step*(UI-1).
 * SPLIT : 1 esclave = 1 unité, adresses fixes (UI toujours = 1).
 *         "UMATCH" / "U-MATCH" sont acceptés comme alias.
 *
 * Échelles : alignées sur ce que fait l'interface web (equipments.js),
 * qui est la référence validée sur site :
 *   - GMV   : consigne / limites / temp. extérieure en dixièmes de °C
 *   - SPLIT : consigne / limites / temp. extérieure en °C entiers
 *   - température de reprise en dixièmes de °C pour les deux.
 */

const GMV = 'GMV';
const SPLIT = 'SPLIT';
const EQUIPMENT_TYPES = [GMV, SPLIT];

const TYPE_ALIASES = {
    GMV: GMV,
    VRF: GMV,
    SPLIT: SPLIT,
    UMATCH: SPLIT,
    'U-MATCH': SPLIT,
    U_MATCH: SPLIT
};

const POWER_ON = 0xAA;   // 170
const POWER_OFF = 0x55;  // 85

const SETPOINT_MIN = 16;
const SETPOINT_MAX = 30;

// ---------------------------------------------------------------------------
// Défauts
// ---------------------------------------------------------------------------

const GMV_FAULTS = {
    408: 'Défaut général',
    409: 'Erreur communication',
    410: 'Protection unité intérieure',
    411: 'Protection ventilateur indoor',
    412: 'Bac à condensats plein',
    413: 'Protection surintensité',
    414: 'Protection anti-gel',
    415: 'Conflit mode',
    416: 'Défaut carte indoor',
    417: 'Défaut sonde reprise',
    418: 'Défaut sonde ambiance',
    419: 'Défaut sonde liquide',
    420: 'Défaut sonde gaz',
    421: 'Défaut sonde humidité',
    422: 'Conflit adresse',
    423: 'Pas de master',
    424: 'Défaut quantité unités'
};

const SPLIT_FAULTS = {
    95: 'Défaut général',
    97: 'Défaut capteur température évaporateur',
    98: 'Défaut capteur température retour air',
    99: 'Défaut capteur température afficheur',
    101: 'Bac à condensats plein',
    105: 'Défaut ventilateur',
    122: 'Défaut communication intérieur / extérieure',
    152: 'Protection haute pression',
    153: 'Protection',
    154: 'Protection moteur ventilateur intérieur',
    155: 'Protection vanne 4 voies',
    156: 'Protection surpuissance',
    157: 'Protection surcharge',
    158: 'Protection basse pression',
    159: 'Protection haute pression',
    160: 'Protection prise en glace',
    161: 'Défaut capteur température extérieure',
    162: 'Défaut capteur température HP',
    163: 'Défaut capteur température BP',
    174: 'Défaut communication cartes',
    184: 'Protection tension bus DC',
    185: 'Protection tension bus DC'
};
for (let code = 186; code <= 196; code++) {
    SPLIT_FAULTS[code] = 'Protection compresseur';
}

// ---------------------------------------------------------------------------
// Modes / ventilation
// ---------------------------------------------------------------------------

const MODES = {
    GMV: { 1: 'Froid', 2: 'Déshumidification', 3: 'Ventilation', 4: 'Chauffage', 5: 'Auto' },
    SPLIT: { 1: 'Froid', 2: 'Chauffage', 3: 'Déshumidification', 4: 'Ventilation', 5: 'Auto' }
};

// Valeurs d'écriture
const FAN_SPEEDS = {
    GMV: { 1: 'Auto', 2: 'Faible', 3: 'Moyen faible', 4: 'Moyen', 5: 'Moyen fort', 6: 'Fort', 7: 'Turbo' },
    SPLIT: { 0: 'Auto', 1: 'Vitesse 1', 2: 'Vitesse 2', 3: 'Vitesse 3', 4: 'Vitesse 4', 5: 'Vitesse 5', 6: 'Turbo' }
};

// Valeurs de lecture (le GMV renvoie une échelle différente, cf. Table GREE GMV)
const FAN_SPEEDS_READ = {
    GMV: { 1: 'Arrêt', 2: 'Très faible', 3: 'Faible', 4: 'Moyen faible', 5: 'Moyen', 6: 'Moyen fort', 7: 'Fort', 8: 'Très fort' },
    SPLIT: FAN_SPEEDS.SPLIT
};

// Alias acceptés en entrée (minuscules, sans accents)
const MODE_ALIASES = {
    froid: 'Froid', cool: 'Froid', clim: 'Froid', climatisation: 'Froid',
    chauffage: 'Chauffage', chaud: 'Chauffage', heat: 'Chauffage',
    deshumidification: 'Déshumidification', deshu: 'Déshumidification', dry: 'Déshumidification',
    ventilation: 'Ventilation', ventil: 'Ventilation', fan: 'Ventilation',
    auto: 'Auto'
};

const FAN_ALIASES = {
    GMV: { auto: 1, low: 2, faible: 2, medium: 4, moyen: 4, high: 6, fort: 6, turbo: 7 },
    SPLIT: { auto: 0, low: 1, faible: 1, medium: 3, moyen: 3, high: 5, fort: 5, turbo: 6 }
};

// ---------------------------------------------------------------------------
// Cartes
// ---------------------------------------------------------------------------

const MAPS = {
    GMV: {
        step: 25,
        registers: {
            cmd: 102,
            mode: 103,
            setpoint: 104,
            fan: 105,
            lowLimit: 106,
            highLimit: 107,
            returnTemp: 113,
            gateStatus: 117,
            power: 123
        },
        // Registre "système", non décalé par UI
        outsideTempRegister: 6507,
        // Bloc lu en une fois pour l'état : 102..123
        statusBlock: { start: 102, count: 22 },

        shieldBase: 440,
        shieldStep: 64,
        shieldOrder: ['energy', 'setpoint', 'mode', 'power', 'lock'],

        faultBase: 408,
        faultStep: 64,
        faultCount: 17,
        faults: GMV_FAULTS
    },
    SPLIT: {
        step: 0,
        registers: {
            cmd: 2,
            returnTemp: 4,
            mode: 17,
            fan: 19,
            setpoint: 20,
            lowLimit: 35,
            highLimit: 36,
            outsideTemp: 49
        },
        outsideTempRegister: 49,
        // Bloc lu en une fois pour l'état : 2..49
        statusBlock: { start: 2, count: 48 },

        shieldCoils: { lock: 17, setpoint: 18, mode: 19, power: 20, energy: 21 },
        shieldOrder: ['energy', 'setpoint', 'mode', 'power', 'lock'],

        faultScanStart: 95,
        faultScanCount: 102,
        faults: SPLIT_FAULTS
    }
};

// Facteur d'échelle Modbus par champ (valeur Modbus = °C * facteur)
const SCALE = {
    GMV: { setpoint: 10, lowLimit: 10, highLimit: 10, returnTemp: 10, outsideTemp: 10 },
    SPLIT: { setpoint: 1, lowLimit: 1, highLimit: 1, returnTemp: 10, outsideTemp: 1 }
};

// Anciens noms (snake_case) acceptés pour compatibilité
const FIELD_ALIASES = {
    low_limit: 'lowLimit',
    high_limit: 'highLimit',
    return_temp: 'returnTemp',
    outside_temp: 'outsideTemp',
    gate_status: 'gateStatus',
    on_off: 'cmd',
    onoff: 'cmd'
};

const TEMPERATURE_FIELDS = ['setpoint', 'lowLimit', 'highLimit', 'returnTemp', 'outsideTemp'];
const LOCK_KEYS = ['energy', 'setpoint', 'mode', 'power', 'lock'];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function normalizeText(s) {
    return String(s).trim().toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[\s_-]+/g, ' ');
}

/** Normalise un type ; vide -> GMV ; inconnu -> exception. */
function normalizeType(t) {
    if (t === undefined || t === null || String(t).trim() === '') {
        return GMV;
    }
    const key = String(t).trim().toUpperCase();
    if (TYPE_ALIASES[key]) {
        return TYPE_ALIASES[key];
    }
    throw new Error('Type d\'équipement inconnu : "' + t + '" (attendu GMV ou SPLIT)');
}

// Compatibilité avec l'ancienne API du module
const typeOrDefault = normalizeType;

function normalizeField(field) {
    if (!field) {
        return field;
    }
    return FIELD_ALIASES[field] || field;
}

function getMap(type) {
    return MAPS[normalizeType(type)];
}

function offset(type, ui) {
    return getMap(type).step * (Number(ui || 1) - 1);
}

/** Adresse d'un registre (null si non disponible pour ce type). */
function fieldAddress(type, ui, field) {
    const t = normalizeType(type);
    const f = normalizeField(field);
    const m = MAPS[t];

    if (f === 'outsideTemp') {
        return m.outsideTempRegister;
    }
    const base = m.registers[f];
    if (base === undefined || base === null) {
        return null;
    }
    return base + offset(t, ui);
}

function scaleOf(type, field) {
    return SCALE[normalizeType(type)][normalizeField(field)] || 1;
}

function isTemperatureField(field) {
    return TEMPERATURE_FIELDS.indexOf(normalizeField(field)) !== -1;
}

function toSigned16(v) {
    return v > 32767 ? v - 65536 : v;
}

/** Valeur Modbus brute -> valeur physique (°C pour les températures). */
function decodeValue(type, field, raw) {
    if (raw === null || raw === undefined) {
        return null;
    }
    if (!isTemperatureField(field)) {
        return raw;
    }
    const scale = scaleOf(type, field);
    const v = toSigned16(raw) / scale;
    return Math.round(v * 10) / 10;
}

/** Valeur physique -> valeur Modbus brute. */
function encodeValue(type, field, value) {
    const scale = scaleOf(type, field);
    let v = Math.round(Number(value) * scale);
    if (v < 0) {
        v += 65536;
    }
    return v;
}

function shieldAddress(type, ui, key) {
    const m = getMap(type);
    if (m.shieldCoils) {
        return m.shieldCoils[key];
    }
    const index = m.shieldOrder.indexOf(key);
    if (index === -1) {
        return undefined;
    }
    return m.shieldBase + m.shieldStep * (Number(ui || 1) - 1) + index;
}

/** [{address, code, label}] des coils de défaut pour une unité. */
function faultAddresses(type, ui) {
    const t = normalizeType(type);
    const m = MAPS[t];
    const codes = Object.keys(m.faults).map(Number).sort((a, b) => a - b);

    if (m.faultBase !== undefined) {
        const base = m.faultBase + m.faultStep * (Number(ui || 1) - 1);
        return codes.map((code, i) => ({ address: base + i, code: code, label: m.faults[code] }));
    }
    return codes.map((code) => ({ address: code, code: code, label: m.faults[code] }));
}

function modeLabel(type, value) {
    return MODES[normalizeType(type)][value] || null;
}

function fanLabel(type, value, forWrite) {
    const t = normalizeType(type);
    const table = forWrite ? FAN_SPEEDS[t] : FAN_SPEEDS_READ[t];
    return table[value] || null;
}

// ---------------------------------------------------------------------------
// Parsing des valeurs de commande
// ---------------------------------------------------------------------------

function parsePower(v) {
    if (v === true || v === 1 || v === POWER_ON) {
        return true;
    }
    if (v === false || v === 0 || v === POWER_OFF) {
        return false;
    }
    const s = normalizeText(v);
    if (['on', 'true', '1', 'marche', 'allume', 'allumer', '170', 'start'].indexOf(s) !== -1) {
        return true;
    }
    if (['off', 'false', '0', 'arret', 'eteint', 'eteindre', '85', 'stop'].indexOf(s) !== -1) {
        return false;
    }
    throw new Error('Valeur marche/arrêt invalide : "' + v + '"');
}

function parseMode(type, v) {
    const t = normalizeType(type);
    const table = MODES[t];
    const n = Number(v);
    if (v !== '' && !isNaN(n)) {
        if (table[n]) {
            return n;
        }
        throw new Error('Mode ' + v + ' invalide pour ' + t + ' (valeurs : ' + Object.keys(table).join(', ') + ')');
    }
    const wanted = MODE_ALIASES[normalizeText(v)];
    for (const code of Object.keys(table)) {
        if (table[code] === wanted) {
            return Number(code);
        }
    }
    throw new Error('Mode "' + v + '" inconnu (froid, chauffage, deshumidification, ventilation, auto)');
}

function parseFan(type, v) {
    const t = normalizeType(type);
    const table = FAN_SPEEDS[t];
    const n = Number(v);
    if (v !== '' && !isNaN(n)) {
        if (table[n] !== undefined) {
            return n;
        }
        throw new Error('Vitesse ' + v + ' invalide pour ' + t + ' (valeurs : ' + Object.keys(table).join(', ') + ')');
    }
    const s = normalizeText(v);
    if (FAN_ALIASES[t][s] !== undefined) {
        return FAN_ALIASES[t][s];
    }
    for (const code of Object.keys(table)) {
        if (normalizeText(table[code]) === s) {
            return Number(code);
        }
    }
    throw new Error('Vitesse de ventilation "' + v + '" inconnue');
}

function parseTemperature(v, label) {
    const n = Number(v);
    if (v === '' || v === null || isNaN(n)) {
        throw new Error(label + ' invalide : "' + v + '"');
    }
    if (n < SETPOINT_MIN || n > SETPOINT_MAX) {
        throw new Error(label + ' hors plage (' + SETPOINT_MIN + '-' + SETPOINT_MAX + ' °C) : ' + n);
    }
    return n;
}

module.exports = {
    GMV, SPLIT, EQUIPMENT_TYPES,
    POWER_ON, POWER_OFF, SETPOINT_MIN, SETPOINT_MAX,
    MODES, FAN_SPEEDS, FAN_SPEEDS_READ, MAPS, SCALE, LOCK_KEYS,
    GMV_FAULTS, SPLIT_FAULTS,
    normalizeType, typeOrDefault, normalizeField, getMap,
    fieldAddress, shieldAddress, faultAddresses,
    scaleOf, isTemperatureField, decodeValue, encodeValue,
    modeLabel, fanLabel,
    parsePower, parseMode, parseFan, parseTemperature
};
