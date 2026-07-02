from datetime import datetime, time as datetime_time, timedelta, timezone
import os
import time

import pymysql
import requests

from db import get_connection
from discover import cleanup_offline_devices, discover, save


INTERVAL = int(os.getenv("SCHEDULER_INTERVAL", "60"))
DISCOVERY_INTERVAL = int(os.getenv("DISCOVERY_INTERVAL", "3600"))
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
            schedules.equipment_id,
            schedules.group_id,
            schedules.action,
            schedules.temperature,
            schedules.execution_time,
            schedules.repeat_days
        FROM schedules
        WHERE schedules.executed = 0
          AND schedules.execution_time <= UTC_TIMESTAMP()
          AND schedules.enabled = 1
        ORDER BY schedules.execution_time ASC
    """)

    schedules = cur.fetchall()

    if not schedules:
        print("Aucun planning à exécuter", flush=True)
        conn.close()
        return

    print(f"{len(schedules)} planning(s) à exécuter", flush=True)

    for schedule in schedules:

        schedule_id = schedule["schedule_id"]

        try:

            #
            # Construction de la liste des équipements concernés
            #
            if schedule["group_id"]:

                cur.execute("""
                    SELECT
                        e.id,
                        e.name,
                        e.ip,
                        e.port,
                        e.slave_id,
                        e.UI
                    FROM equipments e
                    JOIN equipment_groups eg
                        ON eg.equipment_id = e.id
                    WHERE eg.group_id=%s
                      AND e.enabled=1
                    ORDER BY e.UI
                """, (schedule["group_id"],))

                targets = cur.fetchall()

                print(
                    f"Planning groupe {schedule['group_id']} -> {len(targets)} UI",
                    flush=True
                )

            else:

                cur.execute("""
                    SELECT
                        id,
                        name,
                        ip,
                        port,
                        slave_id,
                        UI
                    FROM equipments
                    WHERE id=%s
                      AND enabled=1
                """, (schedule["equipment_id"],))

                row = cur.fetchone()

                if not row:
                    continue

                targets = [row]

            all_ok = True

            #
            # Boucle sur chaque équipement
            #
            for eq in targets:

                equipment_id = eq["id"]

                ui = int(eq["UI"])

                cmd_register = 102 + (25 * (ui - 1))
                temp_register = 104 + (25 * (ui - 1))

                print(
                    f"UI={ui} "
                    f"IP={eq['ip']} "
                    f"CMD={cmd_register}",
                    flush=True
                )

                #
                # Marche / Arrêt
                #
                action = schedule["action"]

                if action == "ON":

                    ok = hub_write(
                        eq["ip"],
                        eq["port"],
                        eq["slave_id"],
                        "register",
                        cmd_register,
                        0xAA
                    )

                    all_ok &= ok

                    log_command(
                        cur,
                        equipment_id,
                        schedule_id,
                        "ON",
                        "success" if ok else "error",
                        f"reg={cmd_register} value=170"
                    )

                elif action == "OFF":

                    ok = hub_write(
                        eq["ip"],
                        eq["port"],
                        eq["slave_id"],
                        "register",
                        cmd_register,
                        0x55
                    )

                    all_ok &= ok

                    log_command(
                        cur,
                        equipment_id,
                        schedule_id,
                        "OFF",
                        "success" if ok else "error",
                        f"reg={cmd_register} value=85"
                    )

                elif action:

                    all_ok = False

                    log_command(
                        cur,
                        equipment_id,
                        schedule_id,
                        action,
                        "error",
                        "Action inconnue"
                    )

                #
                # Température
                #
                if schedule["temperature"] is not None:

                    temp_value = int(float(schedule["temperature"]) * 10)

                    ok = hub_write(
                        eq["ip"],
                        eq["port"],
                        eq["slave_id"],
                        "register",
                        temp_register,
                        temp_value
                    )

                    all_ok &= ok

                    log_command(
                        cur,
                        equipment_id,
                        schedule_id,
                        "TEMP",
                        "success" if ok else "error",
                        f"reg={temp_register} value={temp_value}"
                    )

            #
            # Mise à jour du planning
            #
            if all_ok:

                repeat_days = parse_repeat_days(schedule.get("repeat_days"))

                if repeat_days:

                    next_execution = next_weekly_execution_utc(
                        schedule["execution_time"],
                        repeat_days
                    )

                    cur.execute("""
                        UPDATE schedules
                        SET execution_time=%s,
                            executed=0
                        WHERE id=%s
                    """, (
                        next_execution.strftime("%Y-%m-%d %H:%M:%S"),
                        schedule_id
                    ))

                    print(
                        f"Planning répété {schedule_id}",
                        flush=True
                    )

                else:

                    cur.execute("""
                        UPDATE schedules
                        SET executed=1
                        WHERE id=%s
                    """, (schedule_id,))

                    print(
                        f"Planning terminé {schedule_id}",
                        flush=True
                    )

            else:
                print(
                    f"Planning {schedule_id} en erreur",
                    flush=True
                )

        except Exception as e:

            print(
                f"Erreur planning {schedule_id}: {e}",
                flush=True
            )

            log_command(
                cur,
                schedule.get("equipment_id"),
                schedule_id,
                "SCHEDULE",
                "error",
                str(e)
            )

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

def read_coil(ip, port, slave_id, address):
    try:
        r = requests.get(
            "http://modbus-hub:8500/read",
            params={
                "ip": ip,
                "port": port,
                "device_id": slave_id,
                "type": "coil",
                "address": address,
                "count": 1
            },
            timeout=5
        )

        data = r.json().get("registers", [])
        return 1 if data and data[0] else 0

    except Exception:
        return 0

def collect_telemetry():
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT id, ip, port, slave_id, UI
        FROM equipments
        WHERE enabled = 1
    """)

    equipments = cur.fetchall()
    outdoor_cache = {}

    def get_outdoor_temp(ip, port, slave_id):
        key = f"{ip}:{port}:{slave_id}"

        if key in outdoor_cache:
            return outdoor_cache[key]

        try:
            print(f"[OUTDOOR TEMP] READ ip={ip} port={port} slave={slave_id}", flush=True)
            r = requests.get(
                "http://modbus-hub:8500/read",
                params={
                    "ip": ip,
                    "port": port,
                    "device_id": slave_id,
                    "type": "register",
                    "address": 3307,
                    "count": 1
                },
                timeout=5
            )
            print(f"[OUTDOOR TEMP] HTTP {r.status_code} {r.text}", flush=True)

            data = r.json().get("registers", [])
            value = (data[0] / 10) if data and data[0] is not None else None

        except Exception:
            print(f"[OUTDOOR TEMP] ERROR {e}", flush=True)
            value = None

        outdoor_cache[key] = value
        return value

    for eq in equipments:

        ui = int(eq["UI"])
        base = 102 + 25 * (ui - 1)

        try:
            r = requests.get(
                "http://modbus-hub:8500/read",
                params={
                    "ip": eq["ip"],
                    "port": eq["port"],
                    "device_id": eq["slave_id"],
                    "type": "register",
                    "address": base,
                    "count": 15
                },
                timeout=5
            )

            data = r.json().get("registers", [])

            if len(data) < 15:
                continue

            state = 1 if data[0] == 170 else 0
            setpoint = data[2] / 10 if data[2] is not None else None
            return_temp = data[14] / 10 if data[14] is not None else None

            coil_addr = 319 + (64 * (ui - 1))
            fault = read_coil(eq["ip"], eq["port"], eq["slave_id"], coil_addr)
            print(f"[TELEMETRY] equipment {eq['id']} UI={ui}", flush=True)
            outside_temp = get_outdoor_temp(eq["ip"], eq["port"], eq["slave_id"])

            # UPDATE
            cur.execute("""
                UPDATE equipments
                SET state=%s,
                    setpoint=%s,
                    return_temp=%s,
                    outside_temp=%s,
                    fault=%s
                WHERE id=%s
            """, (
                state,
                setpoint,
                return_temp,
                outside_temp,
                fault,
                eq["id"]
            ))

            # HISTORY
            cur.execute("""
                INSERT INTO equipment_history
                (equipment_id, created_at, setpoint, return_temp, outside_temp, state, fault)
                VALUES (%s, NOW(), %s, %s, %s, %s, %s)
            """, (
                eq["id"],
                setpoint,
                return_temp,
                outside_temp,
                state,
                fault
            ))

        except Exception as e:
            print(f"[TELEMETRY ERROR] {eq['id']} {e}")

    conn.commit()
    conn.close()  


def main():
    wait_for_db()
    print("Scheduler HVAC démarré", flush=True)

    while True:
        run_discovery_if_needed()
        process_planning()
        collect_telemetry()
        time.sleep(INTERVAL)


if __name__ == "__main__":
    main()
