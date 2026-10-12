module.exports = function (RED) {
    'use strict';

    const U = require('./lib/unit.js');

    function GreeStatusNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.unitNode = config.unit ? RED.nodes.getNode(config.unit) : null;
        node.interval = Number(config.interval) || 0;
        node.onChange = !!config.onChange;
        node.withFaults = config.faults !== false;
        node.withLocks = config.locks !== false;

        const lastSignature = {};
        const busy = {};
        let timer = null;

        async function poll(msg, send) {
            const unit = U.resolveUnit(node.unitNode, msg);
            const key = U.unitKey(unit);

            if (busy[key]) {
                return false;   // lecture précédente encore en cours
            }
            busy[key] = true;
            try {
                if (!node.unitNode || !msg._fromPolling) {
                    node.status({ fill: 'blue', shape: 'dot', text: 'lecture…' });
                }
                const status = await U.readStatus(unit, { faults: node.withFaults, locks: node.withLocks });
                const sig = U.statusSignature(status);
                const changed = lastSignature[key] !== sig;
                lastSignature[key] = sig;

                if (node.unitNode) {
                    node.status({
                        fill: !status.online ? 'red' : (status.fault ? 'yellow' : 'green'),
                        shape: status.power ? 'dot' : 'ring',
                        text: U.statusText(status)
                    });
                } else {
                    node.status({ fill: status.online ? 'green' : 'red', shape: 'dot', text: U.unitLabel(unit) + ' : ' + U.statusText(status) });
                }

                if (node.onChange && !changed) {
                    return false;
                }

                const out = RED.util.cloneMessage(msg);
                delete out._fromPolling;
                out.payload = status;
                out.topic = out.topic || U.unitLabel(unit);
                out.changed = changed;
                send(out);
                return true;
            } finally {
                busy[key] = false;
            }
        }

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };
            poll(msg, send).then(() => done()).catch((err) => {
                node.status({ fill: 'red', shape: 'ring', text: err.message });
                done(err);
            });
        });

        if (node.interval > 0) {
            if (!node.unitNode) {
                node.warn('Interrogation périodique ignorée : aucune unité sélectionnée dans le nœud');
            } else {
                const tick = () => {
                    poll({ _fromPolling: true }, (m) => node.send(m))
                        .catch((err) => {
                            node.status({ fill: 'red', shape: 'ring', text: err.message });
                            node.error(err.message);
                        });
                };
                setTimeout(tick, 1000);
                timer = setInterval(tick, node.interval * 1000);
            }
        }

        node.on('close', function () {
            if (timer) {
                clearInterval(timer);
            }
            node.status({});
        });
    }

    RED.nodes.registerType('gree-status', GreeStatusNode);
};
