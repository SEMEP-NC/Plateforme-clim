from flask import Flask, jsonify, request
from db import get_connection
from discover import cleanup_offline_devices, discover, save

app = Flask(__name__)

@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "service": "api"})

@app.route("/run-discovery", methods=["POST", "GET"])
def run_discovery():
    try:
        print("[API] Discovery triggered", flush=True)

        devices = discover()
        save(devices)
        cleanup_offline_devices()

        return jsonify({
            "status": "success",
            "devices_found": len(devices),
            "devices": devices
        })

    except Exception as e:
        return jsonify({
            "status": "error",
            "message": str(e)
        }), 500


@app.route("/status", methods=["GET"])
def status():
    return jsonify({
        "service": "api",
        "running": True
    })


# ---------------------------------------------------------------------------
# Lecture du parc (utilisé par Node-RED : nœuds gree-unit / gree-equipments).
# Lecture seule, réseau Docker interne uniquement (service non exposé).
# ---------------------------------------------------------------------------

def _decimal(v):
    return float(v) if v is not None else None


@app.route("/equipments", methods=["GET"])
def list_equipments():
    """
    Filtres optionnels :
      enabled=1        -> équipements activés uniquement
      type=GMV|SPLIT
      group=<id|nom>   -> appartenance à un groupe
    """
    where = []
    params = []

    if request.args.get("enabled") in ("1", "true"):
        where.append("e.enabled = 1")

    eq_type = (request.args.get("type") or "").strip().upper()
    if eq_type in ("GMV", "SPLIT"):
        where.append("e.type = %s")
        params.append(eq_type)

    group = (request.args.get("group") or "").strip()
    if group:
        where.append("""
            e.id IN (
                SELECT eg2.equipment_id
                FROM equipment_groups eg2
                JOIN groups_hvac g2 ON g2.id = eg2.group_id
                WHERE g2.id = %s OR g2.name = %s
            )
        """)
        params.extend([group if group.isdigit() else -1, group])

    sql = """
        SELECT
            e.id, e.name, e.localisation, e.ip, e.port, e.slave_id, e.UI,
            e.type, e.enabled, e.power,
            e.state, e.setpoint, e.return_temp, e.outside_temp, e.fault,
            GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR '||') AS group_names
        FROM equipments e
        LEFT JOIN equipment_groups eg ON eg.equipment_id = e.id
        LEFT JOIN groups_hvac g ON g.id = eg.group_id
    """
    if where:
        sql += " WHERE " + " AND ".join(where)
    sql += " GROUP BY e.id ORDER BY e.name"

    try:
        conn = get_connection()
        try:
            cur = conn.cursor()
            cur.execute(sql, params)
            rows = cur.fetchall()
        finally:
            conn.close()
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500

    equipments = []
    for r in rows:
        equipments.append({
            "id": r["id"],
            "name": r["name"],
            "localisation": r["localisation"] or "",
            "type": r["type"] or "GMV",
            "ip": r["ip"],
            "port": r["port"],
            "slave": r["slave_id"],
            "ui": r["UI"],
            "enabled": bool(r["enabled"]),
            "ratedPower": r["power"],
            "groups": r["group_names"].split("||") if r["group_names"] else [],
            "lastKnown": {
                "state": r["state"],
                "setpoint": _decimal(r["setpoint"]),
                "returnTemp": _decimal(r["return_temp"]),
                "outsideTemp": _decimal(r["outside_temp"]),
                "fault": r["fault"],
            },
        })

    return jsonify({"success": True, "count": len(equipments), "equipments": equipments})


@app.route("/groups", methods=["GET"])
def list_groups():
    try:
        conn = get_connection()
        try:
            cur = conn.cursor()
            cur.execute("""
                SELECT g.id, g.name, COUNT(eg.equipment_id) AS equipment_count
                FROM groups_hvac g
                LEFT JOIN equipment_groups eg ON eg.group_id = g.id
                GROUP BY g.id
                ORDER BY g.name
            """)
            rows = cur.fetchall()
        finally:
            conn.close()
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500

    return jsonify({"success": True, "groups": rows})


if __name__ == "__main__":
    app.run(
        host="0.0.0.0",
        port=5001,
        debug=False
    )