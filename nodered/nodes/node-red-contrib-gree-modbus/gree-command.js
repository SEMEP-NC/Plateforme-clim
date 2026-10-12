module.exports = function (RED) {
    'use strict';

    const U = require('./lib/unit.js');

    function delay(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function GreeCommandNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.unitNode = config.unit ? RED.nodes.getNode(config.unit) : null;
        node.readback = config.readback === undefined || config.readback === '' ? 2000 : Number(config.readback);

        // Commande préréglée dans le nœud (champs vides = non envoyés)
        const preset = {};
        if (config.power) { preset.power = config.power; }
        if (config.mode) { preset.mode = config.mode; }
        if (config.setpoint !== undefined && config.setpoint !== '') { preset.setpoint = Number(config.setpoint); }
        if (config.fan) { preset.fan = config.fan; }
        const hasPreset = Object.keys(preset).length > 0;

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };

            (async () => {
                const unit = U.resolveUnit(node.unitNode, msg);

                // Le préréglage du nœud est complété / surchargé par un
                // msg.payload objet. Une valeur simple (ex. timestamp d'un
                // inject) est ignorée quand un préréglage existe.
                // Un payload décrivant un équipement (sortie de GREE
                // équipements : il contient une IP) n'est pas une commande.
                const payloadIsEquipment = !!(msg.payload && typeof msg.payload === 'object' && msg.payload.ip !== undefined);

                let command;
                if (payloadIsEquipment) {
                    if (!hasPreset) {
                        throw new Error('Aucune commande : configurer le nœud ou passer la commande dans msg.payload');
                    }
                    command = Object.assign({}, preset);
                } else if (msg.payload && typeof msg.payload === 'object' && !Array.isArray(msg.payload)) {
                    command = Object.assign({}, preset, msg.payload);
                } else if (hasPreset) {
                    command = Object.assign({}, preset);
                } else {
                    command = msg.payload;
                }

                node.status({ fill: 'blue', shape: 'dot', text: 'écriture…' });
                const result = await U.writeCommand(unit, command);
                result.equipment = unit;

                if (!result.success) {
                    node.status({ fill: 'red', shape: 'dot', text: result.error });
                    node.error(U.unitLabel(unit) + ' : ' + result.error, msg);
                } else {
                    const summary = result.applied.map((op) => op.field + '=' + op.display).join(' ');
                    node.status({ fill: 'green', shape: 'dot', text: summary });
                }

                if (result.success && node.readback > 0) {
                    await delay(node.readback);
                    result.status = await U.readStatus(unit, { faults: false, locks: !!result.command.locks });
                }

                msg.payload = result;
                msg.topic = msg.topic || U.unitLabel(unit);
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

    RED.nodes.registerType('gree-command', GreeCommandNode);
};
