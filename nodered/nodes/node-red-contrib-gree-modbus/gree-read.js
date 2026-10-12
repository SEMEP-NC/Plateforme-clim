module.exports = function (RED) {
    'use strict';

    const hub = require('./lib/hub.js');
    const R = require('./lib/registers.js');
    const U = require('./lib/unit.js');

    /** Lecture d'un seul champ (nœud bas niveau, conservé pour compatibilité). */
    function GreeReadNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.unitNode = config.unit ? RED.nodes.getNode(config.unit) : null;
        node.field = config.field || 'cmd';
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
                const address = R.fieldAddress(unit.type, unit.ui, field);

                if (address === null || address === undefined) {
                    throw new Error('Champ "' + field + '" non disponible pour le type ' + unit.type);
                }

                node.status({ fill: 'blue', shape: 'dot', text: 'lecture…' });

                let raw;
                try {
                    raw = (await hub.readRegisters(unit, address, 1))[0];
                } catch (err) {
                    node.status({ fill: 'red', shape: 'dot', text: err.message });
                    msg.payload = null;
                    msg.error = err.message;
                    send(msg);
                    return;
                }

                msg.payload = R.decodeValue(unit.type, field, raw);
                msg.raw = raw;
                msg.greeType = unit.type;
                msg.greeField = field;
                msg.greeAddress = address;
                msg.equipment = msg.equipment || unit;

                if (field === 'cmd') {
                    msg.label = raw === R.POWER_ON ? 'ON' : 'OFF';
                } else if (field === 'mode') {
                    msg.label = R.modeLabel(unit.type, raw);
                } else if (field === 'fan') {
                    msg.label = R.fanLabel(unit.type, raw, false);
                }

                node.status({ fill: 'green', shape: 'dot', text: String(msg.label || msg.payload) });
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

    RED.nodes.registerType('gree-read', GreeReadNode);
};
