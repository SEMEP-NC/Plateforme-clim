module.exports = function (RED) {
    'use strict';

    const U = require('./lib/unit.js');

    /**
     * Surveille les défauts d'une ou plusieurs unités et n'émet que les
     * changements : apparition / disparition d'un défaut, perte / retour
     * de communication.
     */
    function GreeFaultsNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.unitNode = config.unit ? RED.nodes.getNode(config.unit) : null;
        node.interval = Number(config.interval) || 0;
        node.emitInitial = config.emitInitial !== false;
        node.commEvents = config.commEvents !== false;

        // clé unité -> { online: bool, codes: Set }
        const known = {};
        const busy = {};
        let timer = null;

        function event(unit, type, fault, active, base) {
            const out = base ? RED.util.cloneMessage(base) : {};
            delete out._fromPolling;
            out.topic = 'gree/' + (fault ? 'fault' : 'comm') + '/' + type;
            out.payload = {
                event: type,
                fault: fault || null,
                equipment: unit,
                equipmentLabel: U.unitLabel(unit),
                active: active,
                timestamp: new Date().toISOString()
            };
            return out;
        }

        async function check(msg, send) {
            const unit = U.resolveUnit(node.unitNode, msg);
            const key = U.unitKey(unit);
            if (busy[key]) {
                return;
            }
            busy[key] = true;

            try {
                const first = !known[key];
                const prev = known[key] || { online: true, codes: new Map() };
                const out = [];

                let faults;
                try {
                    faults = await U.readFaults(unit);
                } catch (err) {
                    if (node.commEvents && (prev.online || first)) {
                        const e = event(unit, 'offline', null, null, msg);
                        e.payload.error = err.message;
                        out.push(e);
                    }
                    known[key] = { online: false, codes: prev.codes };
                    node.status({ fill: 'red', shape: 'ring', text: U.unitLabel(unit) + ' : hors ligne' });
                    out.forEach((m) => send(m));
                    return;
                }

                if (node.commEvents && !prev.online && !first) {
                    out.push(event(unit, 'online', null, faults, msg));
                }

                const current = new Map(faults.map((f) => [f.code, f]));
                if (!first || node.emitInitial) {
                    current.forEach((f, code) => {
                        if (!prev.codes.has(code)) {
                            out.push(event(unit, 'raised', f, faults, msg));
                        }
                    });
                }
                prev.codes.forEach((f, code) => {
                    if (!current.has(code)) {
                        out.push(event(unit, 'cleared', f, faults, msg));
                    }
                });

                known[key] = { online: true, codes: current };

                node.status(faults.length
                    ? { fill: 'yellow', shape: 'dot', text: (node.unitNode ? '' : U.unitLabel(unit) + ' : ') + faults.map((f) => f.label).join(', ') }
                    : { fill: 'green', shape: 'dot', text: (node.unitNode ? '' : U.unitLabel(unit) + ' : ') + 'aucun défaut' });

                out.forEach((m) => send(m));
            } finally {
                busy[key] = false;
            }
        }

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };
            if (msg.reset === true) {
                Object.keys(known).forEach((k) => delete known[k]);
                node.status({ fill: 'grey', shape: 'ring', text: 'réinitialisé' });
                return done();
            }
            check(msg, send).then(() => done()).catch((err) => {
                node.status({ fill: 'red', shape: 'ring', text: err.message });
                done(err);
            });
        });

        if (node.interval > 0) {
            if (!node.unitNode) {
                node.warn('Interrogation périodique ignorée : aucune unité sélectionnée dans le nœud');
            } else {
                const tick = () => {
                    check({ _fromPolling: true }, (m) => node.send(m)).catch((err) => node.error(err.message));
                };
                setTimeout(tick, 1500);
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

    RED.nodes.registerType('gree-faults', GreeFaultsNode);
};
