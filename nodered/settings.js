/**
 * Configuration Node-RED - Plateforme Clim GREE.
 *
 * Ce service consomme le modbus-hub existant via son API HTTP interne
 * (http://modbus-hub:8500/read et /write) sans qu'aucune modification du
 * hub ne soit nécessaire : il suffit d'utiliser un nœud "http request"
 * pointant vers ces URLs depuis vos flows.
 *
 * Authentification de l'éditeur (recommandé, l'éditeur permet d'exécuter du
 * code arbitraire côté serveur via un nœud Function) :
 *
 *   1. Générer le hash du mot de passe choisi :
 *        docker compose run --rm node-red node-red admin hash-pw
 *   2. Coller la valeur obtenue dans NODERED_PASSWORD_HASH (fichier .env)
 *   3. Redémarrer le service : docker compose up -d node-red
 *
 * Tant que NODERED_PASSWORD_HASH est vide, l'éditeur reste accessible sans
 * authentification (pratique pour un premier démarrage sur un réseau de
 * confiance, mais à éviter en usage durable).
 */
module.exports = {
    flowFile: "flows.json",
    uiPort: process.env.PORT || 1880,

    adminAuth: process.env.NODERED_PASSWORD_HASH ? {
        type: "credentials",
        users: [
            {
                username: process.env.NODERED_USER || "admin",
                password: process.env.NODERED_PASSWORD_HASH,
                permissions: "*"
            }
        ]
    } : undefined,

    // Table de registres GREE accessible dans les nœuds Function :
    //   const gree = global.get('gree');
    //   gree.fieldAddress('GMV', 3, 'setpoint')  -> 154
    //   gree.modeLabel('SPLIT', 2)                -> "Chauffage"
    functionGlobalContext: (function () {
        try {
            return { gree: require('node-red-contrib-gree-modbus/lib/registers.js') };
        } catch (err) {
            return {};
        }
    })(),
    editorTheme: {
        projects: {
            enabled: false
        }
    }
};
