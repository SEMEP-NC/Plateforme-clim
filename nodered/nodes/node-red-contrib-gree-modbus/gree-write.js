module.exports = function (RED) {
    'use strict';

    const { hubRequest } = require('./lib/hub.js');
    const { typeOrDefault, isTemperatureField, fieldAddress, shieldAddress } = require('./lib/registers.js');

    function firstDefined() {
        for (let i = 0; i < arguments.length; i++) {
            const v = arguments[i];
            if (v !== undefined && v !== null && v !== '') {
                return v;
            }
        }
        return undefined;
    }

    function toBool(v) {
        return v === true || v === 'true' || v === 1 || v === '1';
    }

    function GreeWriteNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.equipType = config.equipType || '';
        node.field = config.field || 'cmd';
        node.ip = config.ip || '';
        node.port = config.port || '';
        node.slave = config.slave || '';
        node.ui = config.ui || '';
        node.value = config.value !== undefined ? config.value : '';

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };
            done = done || function (err) { if (err) node.error(err, msg); };

            try {
                const type = typeOrDefault(firstDefined(node.equipType, msg.type));
                const ui = Number(firstDefined(node.ui, msg.ui, 1));
                const ip = firstDefined(node.ip, msg.ip);
                const port = Number(firstDefined(node.port, msg.port, 502));
                const slave = Number(firstDefined(node.slave, msg.slave, msg.device_id, 1));
                const field = firstDefined(node.field, msg.field);
                const rawValue = firstDefined(node.value, msg.payload);

                if (!ip) {
                    throw new Error('IP manquante (config du nœud ou msg.ip)');
                }
                if (rawValue === undefined) {
                    throw new Error('Valeur manquante (config du nœud ou msg.payload)');
                }

                const isShield = field.indexOf('shield_') === 0;
                let body;

                if (isShield) {
                    const key = field.replace('shield_', '');
                    const address = shieldAddress(type, ui, key);

                    if (address === undefined) {
                        throw new Error('Verrouillage "' + key + '" non disponible pour ' + type);
                    }

                    body = {
                        ip: ip, port: port, slave: slave,
                        address: address, type: 'coil',
                        value: toBool(rawValue)
                    };

                } else {
                    const address = fieldAddress(type, ui, field);

                    if (address === null || address === undefined) {
                        throw new Error('Champ "' + field + '" non disponible pour le type ' + type);
                    }

                    let numeric = Number(rawValue);

                    if (isTemperatureField(field)) {
                        numeric = Math.round(numeric * 10);
                    }

                    body = {
                        ip: ip, port: port, slave: slave,
                        address: address, type: 'register',
                        value: numeric
                    };
                }

                node.status({ fill: 'blue', shape: 'dot', text: 'écriture...' });

                hubRequest('write', body).then((result) => {

                    msg.payload = result;
                    msg.greeType = type;
                    msg.greeField = field;

                    if (!result.success) {
                        node.status({ fill: 'red', shape: 'dot', text: result.error || 'erreur écriture' });
                    } else {
                        node.status({ fill: 'green', shape: 'dot', text: 'OK' });
                    }

                    send(msg);
                    done();

                }).catch((err) => {
                    node.status({ fill: 'red', shape: 'ring', text: err.message });
                    done(err);
                });

            } catch (err) {
                node.status({ fill: 'red', shape: 'ring', text: err.message });
                done(err);
            }
        });

        node.on('close', function () {
            node.status({});
        });
    }

    RED.nodes.registerType('gree-write', GreeWriteNode);
};
