import time
from db import get_connection
from modbus_client import send_command
from discover import discover, save

INTERVAL = 900
DISCOVERY_INTERVAL = 30  # 30s

last_discovery = 0


def process_planning():
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT schedules.*, equipments.*
        FROM schedules
        JOIN equipments ON equipments.id = schedules.equipment_id
        WHERE executed = 0
        AND execution_time <= NOW()
    """)

    rows = cur.fetchall()

    for row in rows:

        try:
            # uniquement Modbus ici (discovery = inventory, pas execution)
            if row["type"] == "modbus":

                send_modbus(
                    row["ip"],
                    row["slave_id"],
                    row["action"],
                    row["temperature"]
                )

            # marquage exécuté
            cur.execute(
                "UPDATE schedules SET executed = 1 WHERE id = %s",
                (row["id"],)
            )

        except Exception as e:
            print("Erreur exécution planning:", e)

    conn.commit()
    conn.close()


def run_discovery_if_needed():
    global last_discovery

    now = time.time()

    if now - last_discovery >= DISCOVERY_INTERVAL:

        print("🔎 Discovery Modbus en cours...")

        devices = discover()   # <-- scan IP + Modbus bits 88-247
        save(devices)          # <-- enregistrement DB

        print(f"✔ Discovery terminé: {len(devices)} UI détectées")

        last_discovery = now


def main():
    print("🚀 Scheduler HVAC démarré")

    while True:

        # 1. découverte équipements (UI detection)
        run_discovery_if_needed()

        # 2. exécution planning
        process_planning()

        # 3. pause
        time.sleep(INTERVAL)
def safe_run():
    try:
        run_discovery_if_needed()
    except Exception as e:
        print("Discovery error:", e)

    try:
        process_planning()
    except Exception as e:
        print("Planning error:", e)

if __name__ == "__main__":
    main()