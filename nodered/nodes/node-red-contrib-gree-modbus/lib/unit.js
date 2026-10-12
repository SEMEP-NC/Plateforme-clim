'use strict';

const R = require('./registers.js');
const hub = require('./hub.js');

function firstDefined() {
    for (let i = 0; i < arguments.length; i++) {
        const v = arguments[i];
        if (v !== undefined && v !== null && v !== '') {
            return v;
        }
    }
    return undefined;
}

/**
 * Normalise une description d'unité, quelle que soit sa source :
 *   - nœud de config gree-unit
 *   - ligne d'équipement de la plateforme (gree-equipments : slave / ui, ou
 *     slave_id / UI bruts de la base)
 *   - objet msg.unit saisi à la main
 */
function normalizeUnit(src) {
    if (!src) {
        return null;
    }
    const type = R.normalizeType(firstDefined(src.type, src.equipType));
    const ip = firstDefined(src.ip, src.host);
    if (!ip) {
        throw new Error('Adresse IP de la passerelle manquante');
    }
    const port = Number(firstDefined(src.port, 502));
    const slave = Number(firstDefined(src.slave, src.slave_id, src.device_id, src.slaveId, 1));
    let ui = Number(firstDefined(src.ui, src.UI, 1));

    if (type === R.SPLIT) {
        ui = 1;
    }
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
        throw new Error('Port invalide : ' + src.port);
    }
    if (!Number.isInteger(slave) || slave < 0 || slave > 255) {
        throw new Error('Esclave Modbus invalide : ' + firstDefined(src.slave, src.slave_id));
    }
    if (!Number.isInteger(ui) || ui < 1 || ui > 255) {
        throw new Error('Numéro d\'UI invalide : ' + firstDefined(src.ui, src.UI));
    }

    const unit = { type: type, ip: String(ip).trim(), port: port, slave: slave, ui: ui };
    const id = firstDefined(src.equipmentId, src.id);
    if (id !== undefined) {
        unit.id = Number(id);
    }
    const name = firstDefined(src.unitName, src.name);
    if (name !== undefined) {
        unit.name = String(name);
    }
    if (src.localisation) {
        unit.localisation = src.localisation;
    }
    return unit;
}

/**
 * Unité ciblée par un message. Priorité :
 *   1. nœud de config gree-unit sélectionné dans le nœud
 *   2. msg.equipment (sortie de gree-equipments) ou msg.unit
 *   3. champs "à plat" (ancienne API : config du nœud puis msg.ip/port/...)
 */
function resolveUnit(unitConfigNode, msg, legacy) {
    if (unitConfigNode) {
        return normalizeUnit(unitConfigNode.unit);
    }
    if (msg && msg.equipment && typeof msg.equipment === 'object') {
        return normalizeUnit(msg.equipment);
    }
    if (msg && msg.unit && typeof msg.unit === 'object') {
        return normalizeUnit(msg.unit);
    }
    const l = legacy || {};
    const m = msg || {};
    const ip = firstDefined(l.ip, m.ip);
    if (!ip) {
        throw new Error('Aucune unité : sélectionner une unité GREE, ou fournir msg.equipment / msg.unit / msg.ip');
    }
    return normalizeUnit({
        type: firstDefined(l.type, m.type),
        ip: ip,
        port: firstDefined(l.port, m.port),
        slave: firstDefined(l.slave, m.slave, m.device_id),
        ui: firstDefined(l.ui, m.ui)
    });
}

function unitKey(unit) {
    return [unit.type, unit.ip, unit.port, unit.slave, unit.ui].join(':');
}

function unitLabel(unit) {
    if (unit.name) {
        return unit.name;
    }
    return unit.type + ' ' + unit.ip + ':' + unit.port + ' #' + unit.slave + (unit.type === R.GMV ? ' UI' + unit.ui : '');
}

// ---------------------------------------------------------------------------
// Lecture
// ---------------------------------------------------------------------------

/** Lit les coils de défaut, renvoie [{address, code, label}] des défauts actifs. */
async function readFaults(unit) {
    const list = R.faultAddresses(unit.type, unit.ui);
    const start = list[0].address;
    const count = list[list.length - 1].address - start + 1;
    const bits = await hub.readCoils(unit, start, count);
    return list.filter((f) => bits[f.address - start]);
}

async function readLocks(unit) {
    const addresses = R.LOCK_KEYS.map((k) => R.shieldAddress(unit.type, unit.ui, k));
    const start = Math.min.apply(null, addresses);
    const count = Math.max.apply(null, addresses) - start + 1;
    const bits = await hub.readCoils(unit, start, count);
    const locks = {};
    R.LOCK_KEYS.forEach((k, i) => { locks[k] = !!bits[addresses[i] - start]; });
    return locks;
}

/**
 * État complet décodé d'une unité.
 * Ne lève pas d'exception sur un défaut de communication : renvoie
 * { online:false, error } pour que les flows puissent le traiter.
 */
async function readStatus(unit, options) {
    const opts = Object.assign({ faults: true, locks: true }, options || {});
    const t = unit.type;
    const map = R.getMap(t);
    const status = {
        equipment: unit,
        online: false,
        timestamp: new Date().toISOString()
    };

    const off = map.step * (unit.ui - 1);
    const start = map.statusBlock.start + off;
    const count = map.statusBlock.count;

    let regs;
    try {
        regs = await hub.readRegisters(unit, start, count);
    } catch (err) {
        status.error = err.message;
        return status;
    }

    const at = (field) => {
        const addr = R.fieldAddress(t, unit.ui, field);
        if (addr === null || addr < start || addr >= start + count) {
            return null;
        }
        const v = regs[addr - start];
        return v === undefined ? null : v;
    };

    const cmd = at('cmd');
    const mode = at('mode');
    const fan = at('fan');

    status.online = true;
    status.power = cmd === R.POWER_ON;
    status.powerRaw = cmd;
    status.mode = mode;
    status.modeLabel = R.modeLabel(t, mode);
    status.fan = fan;
    status.fanLabel = R.fanLabel(t, fan, false);
    status.setpoint = R.decodeValue(t, 'setpoint', at('setpoint'));
    status.lowLimit = R.decodeValue(t, 'lowLimit', at('lowLimit'));
    status.highLimit = R.decodeValue(t, 'highLimit', at('highLimit'));
    status.returnTemp = R.decodeValue(t, 'returnTemp', at('returnTemp'));

    if (t === R.SPLIT) {
        status.outsideTemp = R.decodeValue(t, 'outsideTemp', at('outsideTemp'));
    } else {
        status.gateStatus = at('gateStatus');
        status.ratedPower = at('power');
        try {
            const out = await hub.readRegisters(unit, map.outsideTempRegister, 1);
            status.outsideTemp = R.decodeValue(t, 'outsideTemp', out[0]);
        } catch (err) {
            status.outsideTemp = null;
            status.warnings = (status.warnings || []).concat(err.message);
        }
    }

    if (opts.locks) {
        try {
            status.locks = await readLocks(unit);
        } catch (err) {
            status.locks = null;
            status.warnings = (status.warnings || []).concat(err.message);
        }
    }

    if (opts.faults) {
        try {
            status.faults = await readFaults(unit);
            status.fault = status.faults.length > 0;
        } catch (err) {
            status.faults = null;
            status.fault = null;
            status.warnings = (status.warnings || []).concat(err.message);
        }
    }

    return status;
}

/** Champs comparés pour "émettre seulement sur changement". */
function statusSignature(s) {
    if (!s.online) {
        return 'offline';
    }
    return JSON.stringify([
        s.power, s.mode, s.fan, s.setpoint, s.lowLimit, s.highLimit,
        s.returnTemp, s.outsideTemp, s.gateStatus, s.locks,
        s.faults ? s.faults.map((f) => f.code) : null
    ]);
}

// ---------------------------------------------------------------------------
// Écriture
// ---------------------------------------------------------------------------

const COMMAND_KEYS = ['power', 'mode', 'fan', 'setpoint', 'lowLimit', 'highLimit', 'locks'];

const COMMAND_ALIASES = {
    cmd: 'power', on: 'power', state: 'power', onoff: 'power',
    temperature: 'setpoint', consigne: 'setpoint', temp: 'setpoint',
    low_limit: 'lowLimit', minSetpoint: 'lowLimit', min_setpoint: 'lowLimit',
    high_limit: 'highLimit', maxSetpoint: 'highLimit',
    ventilation: 'fan', vitesse: 'fan',
    shields: 'locks', verrouillages: 'locks'
};

/**
 * Convertit une commande "métier" en liste d'écritures Modbus.
 * Accepte :
 *   - un objet { power, mode, setpoint, fan, lowLimit, highLimit, locks:{...} }
 *   - "on" / "off" / true / false   -> marche/arrêt
 *   - un nombre                       -> consigne
 * Les champs inconnus provoquent une erreur (évite les fautes de frappe
 * silencieuses).
 */
function parseCommand(type, payload) {
    let cmd = payload;

    if (typeof cmd === 'boolean') {
        cmd = { power: cmd };
    } else if (typeof cmd === 'number') {
        cmd = { setpoint: cmd };
    } else if (typeof cmd === 'string') {
        const n = Number(cmd);
        cmd = (cmd.trim() !== '' && !isNaN(n)) ? { setpoint: n } : { power: cmd };
    }
    if (!cmd || typeof cmd !== 'object' || Array.isArray(cmd)) {
        throw new Error('Commande invalide : objet attendu, ex. {"power":true,"setpoint":24}');
    }

    const normalized = {};
    Object.keys(cmd).forEach((k) => {
        const key = COMMAND_ALIASES[k] || k;
        if (COMMAND_KEYS.indexOf(key) === -1) {
            throw new Error('Champ de commande inconnu : "' + k + '" (attendus : ' + COMMAND_KEYS.join(', ') + ')');
        }
        if (cmd[k] !== undefined && cmd[k] !== null && cmd[k] !== '') {
            normalized[key] = cmd[k];
        }
    });

    const parsed = {};
    if (normalized.power !== undefined) {
        parsed.power = R.parsePower(normalized.power);
    }
    if (normalized.mode !== undefined) {
        parsed.mode = R.parseMode(type, normalized.mode);
    }
    if (normalized.fan !== undefined) {
        parsed.fan = R.parseFan(type, normalized.fan);
    }
    if (normalized.setpoint !== undefined) {
        parsed.setpoint = R.parseTemperature(normalized.setpoint, 'Consigne');
    }
    if (normalized.lowLimit !== undefined) {
        parsed.lowLimit = R.parseTemperature(normalized.lowLimit, 'Limite basse');
    }
    if (normalized.highLimit !== undefined) {
        parsed.highLimit = R.parseTemperature(normalized.highLimit, 'Limite haute');
    }
    if (normalized.locks !== undefined) {
        if (typeof normalized.locks !== 'object') {
            throw new Error('locks doit être un objet, ex. {"lock":true,"setpoint":false}');
        }
        parsed.locks = {};
        Object.keys(normalized.locks).forEach((k) => {
            if (R.LOCK_KEYS.indexOf(k) === -1) {
                throw new Error('Verrouillage inconnu : "' + k + '" (attendus : ' + R.LOCK_KEYS.join(', ') + ')');
            }
            parsed.locks[k] = R.parsePower(normalized.locks[k]);
        });
    }
    if (parsed.lowLimit !== undefined && parsed.highLimit !== undefined && parsed.lowLimit > parsed.highLimit) {
        throw new Error('Limite basse supérieure à la limite haute');
    }
    if (Object.keys(parsed).length === 0) {
        throw new Error('Commande vide');
    }
    return parsed;
}

/**
 * Liste ordonnée des écritures. Ordre : réglages d'abord, marche/arrêt
 * ensuite (l'unité démarre directement avec le bon mode / la bonne consigne),
 * verrouillages en dernier.
 */
function planWrites(unit, parsed) {
    const t = unit.type;
    const ops = [];
    const reg = (field, value, display) => {
        const address = R.fieldAddress(t, unit.ui, field);
        if (address === null) {
            throw new Error('Champ "' + field + '" non disponible pour ' + t);
        }
        ops.push({ field: field, kind: 'register', address: address, value: value, display: display });
    };

    if (parsed.mode !== undefined) {
        reg('mode', parsed.mode, R.modeLabel(t, parsed.mode));
    }
    if (parsed.fan !== undefined) {
        reg('fan', parsed.fan, R.fanLabel(t, parsed.fan, true));
    }
    if (parsed.setpoint !== undefined) {
        reg('setpoint', R.encodeValue(t, 'setpoint', parsed.setpoint), parsed.setpoint);
    }
    if (parsed.lowLimit !== undefined) {
        reg('lowLimit', R.encodeValue(t, 'lowLimit', parsed.lowLimit), parsed.lowLimit);
    }
    if (parsed.highLimit !== undefined) {
        reg('highLimit', R.encodeValue(t, 'highLimit', parsed.highLimit), parsed.highLimit);
    }
    if (parsed.power !== undefined) {
        reg('cmd', parsed.power ? R.POWER_ON : R.POWER_OFF, parsed.power ? 'ON' : 'OFF');
    }
    if (parsed.locks) {
        Object.keys(parsed.locks).forEach((k) => {
            ops.push({
                field: 'lock.' + k,
                kind: 'coil',
                address: R.shieldAddress(t, unit.ui, k),
                value: parsed.locks[k],
                display: parsed.locks[k]
            });
        });
    }
    return ops;
}

/**
 * Exécute les écritures dans l'ordre. S'arrête à la première erreur
 * (le résultat indique ce qui a déjà été appliqué).
 */
async function executeWrites(unit, ops) {
    const done = [];
    for (const op of ops) {
        try {
            if (op.kind === 'coil') {
                await hub.writeCoil(unit, op.address, op.value);
            } else {
                await hub.writeRegister(unit, op.address, op.value);
            }
            done.push(op);
        } catch (err) {
            return { success: false, error: err.message, applied: done, failed: op };
        }
    }
    return { success: true, applied: done };
}

async function writeCommand(unit, payload) {
    const parsed = parseCommand(unit.type, payload);
    const ops = planWrites(unit, parsed);
    const result = await executeWrites(unit, ops);
    result.command = parsed;
    return result;
}

function statusText(s) {
    if (!s.online) {
        return 'hors ligne';
    }
    const parts = [s.power ? 'ON' : 'OFF'];
    if (s.power && s.modeLabel) {
        parts.push(s.modeLabel);
    }
    if (s.setpoint !== null && s.setpoint !== undefined) {
        parts.push('cons. ' + s.setpoint + '°');
    }
    if (s.returnTemp !== null && s.returnTemp !== undefined) {
        parts.push('amb. ' + s.returnTemp + '°');
    }
    if (s.fault) {
        parts.push(s.faults.length + ' défaut(s)');
    }
    return parts.join(' · ');
}

module.exports = {
    firstDefined,
    normalizeUnit, resolveUnit, unitKey, unitLabel,
    readStatus, readFaults, readLocks, statusSignature, statusText,
    parseCommand, planWrites, executeWrites, writeCommand
};
