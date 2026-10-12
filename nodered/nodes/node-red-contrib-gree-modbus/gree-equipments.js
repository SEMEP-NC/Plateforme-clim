module.exports = function (RED) {
    'use strict';

    const hub = require('./lib/hub.js');

    /**
     * Récupère la liste des équipements déclarés dans la plateforme
     * (service api -> table equipments), avec filtres.
     */
    function GreeEquipmentsNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.enabledOnly = config.enabledOnly !== false;
        node.equipType = config.equipType || '';
        node.group = config.group || '';
        node.split = config.output === 'split';

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };

            const group = node.group || (typeof msg.group === 'string' || typeof msg.group === 'number' ? msg.group : '');
            const type = node.equipType || (typeof msg.type === 'string' ? msg.type : '');

            node.status({ fill: 'blue', shape: 'dot', text: 'chargement…' });

            hub.apiGet('/equipments', {
                enabled: node.enabledOnly ? 1 : '',
                type: type,
                group: group
            }).then((data) => {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'réponse invalide');
                }
                const list = data.equipments || [];
                node.status({ fill: 'green', shape: 'dot', text: list.length + ' équipement(s)' });

                if (!node.split) {
                    msg.payload = list;
                    send(msg);
                    return done();
                }

                list.forEach((eq, i) => {
                    const out = RED.util.cloneMessage(msg);
                    out.equipment = eq;
                    out.payload = eq;
                    out.topic = eq.name;
                    out.parts = { id: msg._msgid, index: i, count: list.length, type: 'array' };
                    send(out);
                });
                done();
            }).catch((err) => {
                node.status({ fill: 'red', shape: 'ring', text: err.message });
                done(err);
            });
        });

        node.on('close', function () {
            node.status({});
        });
    }

    RED.nodes.registerType('gree-equipments', GreeEquipmentsNode);
};
