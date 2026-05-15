import time
import pymysql

from db import get_connection
from modbus_client import send_command
from discover import discover, save

INTERVAL = 900
DISCOVERY_INTERVAL = 30

last_discovery = 0


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

            print(
                f"DB not ready ({i}/30): {e}",
                flush=True
            )

            time.sleep(2)

    raise Exception("DB unreachable")


def process_planning():

    conn = get_connection()

    cur = conn.cursor()

    cur.execute(
        """
        SELECT schedules.*, equipments.*
        FROM schedules
        JOIN equipments
            ON equipments.id = schedules.equipment_id
        WHERE executed = 0
        AND execution_time <= NOW()
        """
    )

    rows = cur.fetchall()

    for row in rows:

        try:

            if row["type"] == "modbus":

                print(
                    f"📡 Commande {row['action']} "
                    f"-> {row['ip']}",
                    flush=True
                )

                send_command(
                    row["ip"],
                    row["slave_id"],
                    row["action"],
                    row["temperature"]
                )

            cur.execute(
                """
                UPDATE schedules
                SET executed = 1
                WHERE id = %s
                """,
                (row["id"],)
            )

            print(
                f"✅ Planning exécuté ID={row['id']}",
                flush=True
            )

        except Exception as e:

            print(
                f"❌ Erreur planning ID={row['id']}: {e}",
                flush=True
            )

    conn.commit()

    conn.close()


def run_discovery_if_needed():

    global last_discovery

    now = time.time()

    if now - last_discovery >= DISCOVERY_INTERVAL:

        print(
            "🔎 Discovery Modbus en cours...",
            flush=True
        )

        try:

            devices = discover()

            save(devices)

            print(
                f"✔ Discovery terminé : "
                f"{len(devices)} UI détectées",
                flush=True
            )

        except Exception as e:

            print(
                f"❌ Discovery error: {e}",
                flush=True
            )

        last_discovery = now


def main():

    wait_for_db()

    print(
        "🚀 Scheduler HVAC démarré",
        flush=True
    )

    while True:

        print(
            "🔁 Loop tick",
            flush=True
        )

        run_discovery_if_needed()

        process_planning()

        print(
            f"😴 Sleep {INTERVAL}s",
            flush=True
        )

        time.sleep(INTERVAL)


if __name__ == "__main__":

    main()