from datetime import datetime, time as datetime_time, timedelta, timezone
import os
import time

import pymysql
import requests

from db import get_connection
from discover import cleanup_offline_devices, discover, save


INTERVAL = int(os.getenv("SCHEDULER_INTERVAL", "60"))
DISCOVERY_INTERVAL = int(os.getenv("DISCOVERY_INTERVAL", "300"))
HUB_WRITE_URL = os.getenv("HUB_WRITE_URL", "http://modbus-hub:8500/write")
LOCAL_TZ = timezone(timedelta(hours=11))

last_discovery = 0


def wait_for_db():
    print("Waiting for DB...", flush=True)

    host = os.getenv("DB_HOST", "db")
    user = os.getenv("DB_USER", "climuser")
    password = os.getenv("DB_PASSWORD", "climpassword")
    database = os.getenv("DB_NAME", "clim_manager")

    for i in range(30):
        try:
            conn = pymysql.connect(
                host=host,
                user=user,
                password=password,
                database=database,
            )
            conn.close()
            print("DB ready", flush=True)
            return

        except Exception as e:
            print(f"DB not ready ({i}/30): {e}", flush=True)
            time.sleep(2)

    raise Exception("DB unreachable")


def hub_write(ip, port, slave, mode, address, value):
    try:
        payload = {
            "ip": ip,
            "port": port,
            "slave": slave,
            "type": mode,
            "address": address,
            "value": value,
        }

        r = requests.post(HUB_WRITE_URL, json=payload, timeout=5)

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


def log_command(cur, equipment_id, schedule_id, action, status, message):
    cur.execute("""
        INSERT INTO command_logs
            (equipment_id, schedule_id, action, status, message)
        VALUES
            (%s, %s, %s, %s, %s)
    """, (equipment_id, schedule_id, action, status, message))

def parse_repeat_days(value):
    if not value:
        return []

    days = []

    for part in str(value).split(","):
        part = part.strip()
        if not part:
            continue

        day = int(part)

        if day < 1 or day > 7:
            raise ValueError(f"Invalid repeat day: {day}")

        days.append(day)

    return sorted(set(days))


def as_utc_datetime(value):
    if isinstance(value, datetime):
        dt = value
    else:
        dt = datetime.strptime(str(value), "%Y-%m-%d %H:%M:%S")

    if dt.tzinfo is None:
        return dt.replace(tzinfo=timezone.utc)

    return dt.astimezone(timezone.utc)


def next_weekly_execution_utc(current_execution_time, repeat_days):
    current_utc = as_utc_datetime(current_execution_time)
    current_local = current_utc.astimezone(LOCAL_TZ)
    local_time = datetime_time(
        current_local.hour,
        current_local.minute,
        current_local.second,
    )

    now_local = datetime.now(timezone.utc).astimezone(LOCAL_TZ)

    for offset in range(1, 8):
        candidate_date = now_local.date() + timedelta(days=offset)

        if candidate_date.isoweekday() not in repeat_days:
            continue

        candidate_local = datetime.combine(candidate_date, local_time, tzinfo=LOCAL_TZ)

        if candidate_local > now_local:
            return candidate_local.astimezone(timezone.utc).replace(tzinfo=None)

    raise ValueError("No next weekly execution found")


def process_planning():
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT
            schedules.id AS schedule_id,
            schedules.action,
            schedules.temperature,
            schedules.execution_time,
            schedules.repeat_days,
            equipments.id AS equipment_id,
            equipments.name,
            equipments.ip,
            equipments.port,
            equipments.slave_id,
            equipments.UI
        FROM schedules
        JOIN equipments
            ON equipments.id = schedules.equipment_id
        WHERE schedules.executed = 0
          AND schedules.execution_time <= UTC_TIMESTAMP()
          AND equipments.enabled = 1
        ORDER BY schedules.execution_time ASC
    """)

    rows = cur.fetchall()

    if not rows:
        print("Aucun planning à exécuter", flush=True)
        conn.close()
        return

    print(f"{len(rows)} planning(s) à exécuter", flush=True)

    for row in rows:
        schedule_id = row["schedule_id"]
        equipment_id = row["equipment_id"]

        try:
            ui = int(row["UI"])
            cmd_register = 102 + (25 * (ui - 1))
            temp_register = 104 + (25 * (ui - 1))

            print(
                f"Schedule ID={schedule_id} UI={ui} IP={row['ip']} "
                f"ACTION={row['action']} TEMP={row['temperature']} "
                f"REPEAT={row.get('repeat_days')}",
                flush=True,
            )

            all_ok = True
            action = row.get("action")

            if action == "ON":
                ok = hub_write(row["ip"], row["port"], row["slave_id"], "register", cmd_register, 0xAA)
                all_ok = all_ok and ok
                log_command(cur, equipment_id, schedule_id, "ON", "success" if ok else "error", f"reg={cmd_register} value=170")

            elif action == "OFF":
                ok = hub_write(row["ip"], row["port"], row["slave_id"], "register", cmd_register, 0x55)
                all_ok = all_ok and ok
                log_command(cur, equipment_id, schedule_id, "OFF", "success" if ok else "error", f"reg={cmd_register} value=85")

            elif action:
                all_ok = False
                log_command(cur, equipment_id, schedule_id, action, "error", "Action inconnue")

            temperature = row.get("temperature")

            if temperature is not None:
                temp_value = int(float(temperature) * 10)
                ok = hub_write(row["ip"], row["port"], row["slave_id"], "register", temp_register, temp_value)
                all_ok = all_ok and ok
                log_command(cur, equipment_id, schedule_id, "TEMP", "success" if ok else "error", f"reg={temp_register} value={temp_value}")

            if all_ok:
                repeat_days = parse_repeat_days(row.get("repeat_days"))

                if repeat_days:
                    next_execution = next_weekly_execution_utc(row["execution_time"], repeat_days)

                    cur.execute("""
                        UPDATE schedules
                        SET execution_time = %s,
                            executed = 0
                        WHERE id = %s
                    """, (next_execution.strftime("%Y-%m-%d %H:%M:%S"), schedule_id))

                    print(
                        f"Repeated schedule ID={schedule_id}, next UTC={next_execution}",
                        flush=True,
                    )
                else:
                    cur.execute("""
                        UPDATE schedules
                        SET executed = 1
                        WHERE id = %s
                    """, (schedule_id,))

                    print(f"Executed one-shot schedule ID={schedule_id}", flush=True)
            else:
                print(f"Planning non marqué exécuté ID={schedule_id}", flush=True)

        except Exception as e:
            print(f"Erreur planning ID={schedule_id}: {e}", flush=True)
            log_command(cur, equipment_id, schedule_id, "SCHEDULE", "error", str(e))

    conn.commit()
    conn.close()


def run_discovery_if_needed():
    global last_discovery
    now = time.time()

    if now - last_discovery >= DISCOVERY_INTERVAL:
        print("Discovery Modbus en cours...", flush=True)

        try:
            devices = discover()
            save(devices)
            cleanup_offline_devices()
            print(f"Discovery terminé : {len(devices)} UI détectées", flush=True)

        except Exception as e:
            print(f"Discovery error: {e}", flush=True)

        last_discovery = now


def main():
    wait_for_db()
    print("Scheduler HVAC démarré", flush=True)

    while True:
        run_discovery_if_needed()
        process_planning()
        time.sleep(INTERVAL)


if __name__ == "__main__":
    main()
