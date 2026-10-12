'use strict';

const http = require('http');
const https = require('https');
const { URL } = require('url');

/**
 * Accès HTTP au modbus-hub et à l'API de la plateforme (réseau Docker interne).
 * Aucune dépendance npm : modules natifs uniquement.
 *
 * URLs configurables par variables d'environnement du conteneur node-red :
 *   MODBUS_HUB_URL   (défaut http://modbus-hub:8500)
 *   PLATFORM_API_URL (défaut http://api:5001)
 */

function baseUrl(envName, fallback) {
    return (process.env[envName] || fallback).replace(/\/+$/, '');
}

function hubBase() {
    return baseUrl('MODBUS_HUB_URL', 'http://modbus-hub:8500');
}

function apiBase() {
    return baseUrl('PLATFORM_API_URL', 'http://api:5001');
}

function httpJson(method, url, body, timeoutMs, label) {
    return new Promise((resolve, reject) => {
        const u = new URL(url);
        const lib = u.protocol === 'https:' ? https : http;
        const data = body !== undefined ? JSON.stringify(body) : null;

        const headers = { Accept: 'application/json' };
        if (data !== null) {
            headers['Content-Type'] = 'application/json';
            headers['Content-Length'] = Buffer.byteLength(data);
        }

        const req = lib.request(u, { method: method, headers: headers, timeout: timeoutMs || 5000 }, (res) => {
            let raw = '';
            res.setEncoding('utf8');
            res.on('data', (chunk) => { raw += chunk; });
            res.on('end', () => {
                let json;
                try {
                    json = JSON.parse(raw);
                } catch (err) {
                    return reject(new Error(label + ' : réponse invalide (HTTP ' + res.statusCode + ') ' + raw.slice(0, 200)));
                }
                if (res.statusCode >= 400) {
                    const detail = json && (json.error || json.message || JSON.stringify(json.detail || json));
                    return reject(new Error(label + ' : HTTP ' + res.statusCode + ' ' + detail));
                }
                resolve(json);
            });
        });

        req.on('error', (err) => reject(new Error(label + ' injoignable : ' + err.message)));
        req.on('timeout', () => req.destroy(new Error(label + ' : délai dépassé')));

        if (data !== null) {
            req.write(data);
        }
        req.end();
    });
}

// ---------------------------------------------------------------------------
// Hub Modbus
// ---------------------------------------------------------------------------

/** Appel brut (compatibilité) : POST /read ou /write, renvoie le JSON du hub. */
function hubRequest(path, body, timeoutMs) {
    const defaultTimeout = path === 'write' ? 12000 : 5000;
    return httpJson('POST', hubBase() + '/' + path, body, timeoutMs || defaultTimeout, 'Hub Modbus');
}

function hubHealth() {
    return httpJson('GET', hubBase() + '/health', undefined, 3000, 'Hub Modbus');
}

async function readRegisters(unit, address, count) {
    const r = await hubRequest('read', {
        ip: unit.ip, port: unit.port, device_id: unit.slave,
        address: address, count: count, type: 'register'
    });
    if (!r.success) {
        throw new Error('Lecture registres ' + address + '+' + count + ' : ' + (r.error || 'échec'));
    }
    if (!Array.isArray(r.registers) || r.registers.length < count) {
        throw new Error('Lecture registres ' + address + '+' + count + ' : réponse incomplète');
    }
    return r.registers;
}

async function readCoils(unit, address, count) {
    const r = await hubRequest('read', {
        ip: unit.ip, port: unit.port, device_id: unit.slave,
        address: address, count: count, type: 'coils'
    });
    if (!r.success) {
        throw new Error('Lecture coils ' + address + '+' + count + ' : ' + (r.error || 'échec'));
    }
    if (!Array.isArray(r.bits) || r.bits.length < count) {
        throw new Error('Lecture coils ' + address + '+' + count + ' : réponse incomplète');
    }
    return r.bits.map(Boolean);
}

async function writeRegister(unit, address, value) {
    const r = await hubRequest('write', {
        ip: unit.ip, port: unit.port, slave: unit.slave,
        address: address, type: 'register', value: value
    });
    if (!r.success) {
        throw new Error('Écriture registre ' + address + ' : ' + (r.error || 'échec'));
    }
    return r;
}

async function writeCoil(unit, address, value) {
    const r = await hubRequest('write', {
        ip: unit.ip, port: unit.port, slave: unit.slave,
        address: address, type: 'coil', value: !!value
    });
    if (!r.success) {
        throw new Error('Écriture coil ' + address + ' : ' + (r.error || 'échec'));
    }
    return r;
}

// ---------------------------------------------------------------------------
// API plateforme
// ---------------------------------------------------------------------------

function apiGet(path, query) {
    const u = new URL(apiBase() + path);
    Object.keys(query || {}).forEach((k) => {
        const v = query[k];
        if (v !== undefined && v !== null && v !== '') {
            u.searchParams.set(k, String(v));
        }
    });
    return httpJson('GET', u.toString(), undefined, 5000, 'API plateforme');
}

module.exports = {
    hubBase, apiBase,
    hubRequest, hubHealth,
    readRegisters, readCoils, writeRegister, writeCoil,
    apiGet
};
