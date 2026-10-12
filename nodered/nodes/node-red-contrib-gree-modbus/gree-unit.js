module.exports = function (RED) {
    'use strict';

    const hub = require('./lib/hub.js');
    const R = require('./lib/registers.js');

    /**
     * Nœud de configuration : une unité intérieure GREE (GMV ou SPLIT).
     * Partagé par gree-status / gree-command / gree-faults / gree-read / gree-write.
     */
    function GreeUnitNode(config) {
        RED.nodes.createNode(this, config);
        this.name = config.name;
        this.unit = {
            name: config.name || '',
            equipmentId: config.equipmentId || '',
            type: config.equipType || R.GMV,
            ip: config.ip,
            port: config.port || 502,
            slave: config.slave || 1,
            ui: config.ui || 1
        };
    }

    RED.nodes.registerType('gree-unit', GreeUnitNode);

    // -----------------------------------------------------------------------
    // Endpoints de l'éditeur (protégés par l'authentification Node-RED)
    // -----------------------------------------------------------------------

    RED.httpAdmin.get('/gree-modbus/equipments', RED.auth.needsPermission('gree-unit.read'), function (req, res) {
        hub.apiGet('/equipments', { enabled: req.query.enabled })
            .then((data) => res.json(data))
            .catch((err) => res.status(502).json({ success: false, error: err.message }));
    });

    RED.httpAdmin.get('/gree-modbus/groups', RED.auth.needsPermission('gree-unit.read'), function (req, res) {
        hub.apiGet('/groups')
            .then((data) => res.json(data))
            .catch((err) => res.status(502).json({ success: false, error: err.message }));
    });

    RED.httpAdmin.get('/gree-modbus/health', RED.auth.needsPermission('gree-unit.read'), function (req, res) {
        hub.hubHealth()
            .then((data) => res.json({ success: true, hub: data, hubUrl: hub.hubBase() }))
            .catch((err) => res.status(502).json({ success: false, error: err.message, hubUrl: hub.hubBase() }));
    });
};
