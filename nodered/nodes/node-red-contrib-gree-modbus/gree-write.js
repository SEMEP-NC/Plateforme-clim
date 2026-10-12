module.exports = function (RED) {
    'use strict';

    const R = require('./lib/registers.js');
    const U = require('./lib/unit.js');

    /**
     * Écriture d'un seul champ (nœud bas niveau, conservé pour compatibilité).
     * Pour plusieurs champs à la fois, préférer "GREE commande".
     */
    function GreeWriteNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.unitNode = config.unit ? RED.nodes.getNode(config.unit) : null;
        node.field = config.field || 'cmd';
        node.value = config.value !== undefined ? config.value : '';
        node.legacy = {
            type: config.equipType || '',
            ip: config.ip || '',
            port: config.port || '',
            slave: config.slave || '',
            ui: config.ui || ''
        };

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };

            (async () => {
                const unit = U.resolveUnit(node.unitNode, msg, node.legacy);
                const field = R.normalizeField(U.firstDefined(node.field, msg.field));
                const payload = (msg.payload && typeof msg.payload === 'object') ? undefined : msg.payload;
                const rawValue = U.firstDefined(node.value, payload);

                if (rawValue === undefined) {
                    throw new Error('Valeur manquante (config du nœud ou msg.payload)');
                }

                // Traduction vers une commande "métier" : même validation et
                // mêmes conversions que GREE commande.
                let command;
                if (field.indexOf('shield_') === 0) {
                    command = { locks: {} };
                    command.locks[field.replace('shield_', '')] = rawValue;
                } else if (field === 'cmd') {
                    command = { power: rawValue };
                } else if (['mode', 'fan', 'setpoint', 'lowLimit', 'highLimit'].indexOf(field) !== -1) {
                    command = {};
                    command[field] = rawValue;
                } else {
                    throw new Error('Champ "' + field + '" non inscriptible');
                }

                node.status({ fill: 'blue', shape: 'dot', text: 'écriture…' });
                const result = await U.writeCommand(unit, command);

                msg.payload = { success: result.success, error: result.error };
                msg.greeType = unit.type;
                msg.greeField = field;
                msg.equipment = msg.equipment || unit;
                if (result.applied.length) {
                    msg.greeAddress = result.applied[0].address;
                    msg.raw = result.applied[0].value;
                }

                node.status(result.success
                    ? { fill: 'green', shape: 'dot', text: 'OK' }
                    : { fill: 'red', shape: 'dot', text: result.error });
                send(msg);
            })().then(() => done()).catch((err) => {
                node.status({ fill: 'red', shape: 'ring', text: err.message });
                done(err);
            });
        });

        node.on('close', function () {
            node.status({});
        });
    }

    RED.nodes.registerType('gree-write', GreeWriteNode);
};
