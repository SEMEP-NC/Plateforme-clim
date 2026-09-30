module.exports = function (RED) {
    'use strict';

    const { hubRequest } = require('./lib/hub.js');
    const { typeOrDefault, isTemperatureField, fieldAddress } = require('./lib/registers.js');

    function firstDefined() {
        for (let i = 0; i < arguments.length; i++) {
            const v = arguments[i];
            if (v !== undefined && v !== null && v !== '') {
                return v;
            }
        }
        return undefined;
    }

    function GreeReadNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.equipType = config.equipType || '';
        node.field = config.field || 'cmd';
        node.ip = config.ip || '';
        node.port = config.port || '';
        node.slave = config.slave || '';
        node.ui = config.ui || '';

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

                if (!ip) {
                    throw new Error('IP manquante (config du nœud ou msg.ip)');
                }

                const address = fieldAddress(type, ui, field);

                if (address === null || address === undefined) {
                    throw new Error('Champ "' + field + '" non disponible pour le type ' + type);
                }

                node.status({ fill: 'blue', shape: 'dot', text: 'lecture...' });

                hubRequest('read', {
                    ip: ip,
                    port: port,
                    device_id: slave,
                    address: address,
                    count: 1,
                    type: 'register'
                }).then((result) => {

                    if (!result.success) {
                        node.status({ fill: 'red', shape: 'dot', text: result.error || 'erreur lecture' });
                        msg.payload = null;
                        msg.error = result.error;
                        send(msg);
                        return done();
                    }

                    const raw = (result.registers && result.registers.length)
                        ? result.registers[0]
                        : null;

                    msg.payload = (raw !== null && isTemperatureField(field)) ? raw / 10 : raw;
                    msg.raw = raw;
                    msg.greeType = type;
                    msg.greeField = field;
                    msg.greeAddress = address;

                    node.status({ fill: 'green', shape: 'dot', text: String(msg.payload) });
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

    RED.nodes.registerType('gree-read', GreeReadNode);
};
