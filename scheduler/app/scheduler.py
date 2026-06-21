import time
import pymysql
import requests

from db import get_connection
from discover import discover, save

# =========================
# CONFIG
# =========================

INTERVAL = 60
DISCOVERY_INTERVAL = 30

HUB_URL = "http://modbus-hub:8500/write"

last_discovery = 0

# =========================
# DB READY CHECK
# =========================

def wait_for_db():

    print("⏳ Waiting for DB...", flush=True)

    for i in range(30):

        try:
            conn = pymysql.connect(
                host='db',
                user='climuser',
                password='climpassword',
                database='clim_manager'
            )
            conn.close()

            print("✅ DB ready", flush=True)
            return

        except Exception as e:
            print(f"DB not ready ({i}/30): {e}", flush=True)
            time.sleep(2)

    raise Exception("DB unreachable")

# =========================
# HUB WRITE (NEW ARCHITECTURE)
# =========================

def hub_write(ip, port, slave, mode, address, value):

    try:
        payload = {
            "ip": ip,
            "port": port,
            "slave": slave,
            "type": mode,          # "coil" ou "register"
            "address": address,
            "value": value
        }

        r = requests.post(HUB_URL, json=payload, timeout=5)

        if r.status_code != 200:
            print(f"[HUB] WRITE HTTP {r.status_code} {r.text}", flush=True)
            return False

        data = r.json()

        if not data.get("success"):
            print(f"[HUB] WRITE FAILED {data}", flush=True)
            return False

        return True

    except Exception as e:
        print(f"[HUB] WRITE ERROR {e}", flush=True)
        return False

# =========================
# PLANNING ENGINE
# =========================

def process_planning():

    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT schedules.*, equipments.*
        FROM schedules
        JOIN equipments
            ON equipments.id = schedules.equipment_id
        WHERE executed = 0
        AND execution_time <= NOW()
    """)

    rows = cur.fetchall()

    for row in rows:

        try:

            if row["type"] == "modbus":

                print(
                    f"📡 Commande {row['action']} -> {row['ip']}",
                    flush=True
                )

                # =========================
                # ACTION MAPPING
                # =========================

                if row["action"] == "ON":
                    value = 1
                    mode = "coil"

                elif row["action"] == "OFF":
                    value = 0
                    mode = "coil"

                elif row["action"] == "SET_TEMP":
                    value = row["temperature"]
                    mode = "register"

                else:
                    print(f"⚠ Unknown action {row['action']}", flush=True)
                    continue

                ok = hub_write(
                    row["ip"],
                    row["port"],
                    row["slave_id"],
                    mode,
                    120,  # ⚠ à adapter si mapping réel différent
                    value
                )

                if ok:
                    print(f"✅ WRITE OK ID={row['id']}", flush=True)
                else:
                    print(f"❌ WRITE FAILED ID={row['id']}", flush=True)

            # mark executed regardless (comme ton ancien comportement)
            cur.execute("""
                UPDATE schedules
                SET executed = 1
                WHERE id = %s
            """, (row["id"],))

        except Exception as e:
            print(f"❌ Erreur planning ID={row['id']}: {e}", flush=True)

    conn.commit()
    conn.close()

# =========================
# DISCOVERY TRIGGER
# =========================

def run_discovery_if_needed():

    global last_discovery
    now = time.time()

    if now - last_discovery >= DISCOVERY_INTERVAL:

        print("🔎 Discovery Modbus en cours...", flush=True)

        try:
            devices = discover()
            save(devices)

            print(
                f"✔ Discovery terminé : {len(devices)} UI détectées",
                flush=True
            )

        except Exception as e:
            print(f"❌ Discovery error: {e}", flush=True)

        last_discovery = now

# =========================
# MAIN LOOP
# =========================

def main():

    wait_for_db()

    print("🚀 Scheduler HVAC démarré", flush=True)

    while True:

        print("🔁 Loop tick", flush=True)

        run_discovery_if_needed()
        process_planning()

        print(f"😴 Sleep {INTERVAL}s", flush=True)
        time.sleep(INTERVAL)


if __name__ == "__main__":
    main()