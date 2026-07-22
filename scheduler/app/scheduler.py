from datetime import datetime, time as datetime_time, timedelta, timezone
import os
import time
import threading
import pymysql
import requests

from db import get_connection
from discover import cleanup_offline_devices, discover, save
from mail_notifier import (
    check_and_send,
    check_mail_queue,
    check_weekly_report
)


INTERVAL = int(os.getenv("SCHEDULER_INTERVAL"))
DISCOVERY_INTERVAL = int(os.getenv("DISCOVERY_INTERVAL"))
HUB_WRITE_URL = os.getenv("HUB_WRITE_URL")
LOCAL_TZ = timezone(timedelta(hours=11))
RUNTIME_INCREMENT = INTERVAL

last_discovery = 0
LAST_LOOP = time.time()
LAST_LOOP_LOCK = threading.Lock()

FAULTS = {
    408: "Défaut général",
    409: "Erreur communication",
    410: "Protection unité intérieure",
    411: "Protection ventilateur indoor",
    412: "Bac à condensats plein",
    413: "Protection surintensité",
    414: "Protection anti-gel",
    415: "Conflit mode",
    416: "Défaut carte indoor",
    417: "Défaut sonde reprise",
    418: "Défaut sonde ambiance",
    419: "Défaut sonde liquide",
    420: "Défaut sonde gaz",
    421: "Défaut sonde humidité",
    422: "Conflit adresse",
    423: "Pas de master",
    424: "Défaut quantité unités"
}

fault_cache = {}
temperature_alarm_cache = {}

def update_watchdog():
    global LAST_LOOP

    with LAST_LOOP_LOCK:
        LAST_LOOP = time.time()


def watchdog():

    global LAST_LOOP

    while True:

        with LAST_LOOP_LOCK:
            elapsed = time.time() - LAST_LOOP

        if elapsed > (INTERVAL + 120):

            print(
                f"WATCHDOG: scheduler bloqué depuis {int(elapsed)} secondes",
                flush=True
            )

            os._exit(1)

        time.sleep(60)

def wait_for_db():
    print("Waiting for DB...", flush=True)

    host = os.getenv("DB_HOST")
    user = os.getenv("DB_USER")
    password = os.getenv("DB_PASSWORD")
    database = os.getenv("DB_NAME")

    for i in range(30):
        try:
            conn = pymysql.connect(
                host=host,
                user=user,
                password=password,
                database=database,
            )
            
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
    conn = None
    try:
        conn = get_connection()
        cur = conn.cursor(pymysql.cursors.DictCursor)

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

    except Exception as e:

        print(
            f"[PLANNING ERROR] {e}",
            flush=True
        )

    finally:
        if conn:
            try:
                conn.close()
            except Exception:
                pass


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

def read_coils(ip, port, slave_id, address):

    try:
        r = requests.get(
            "http://modbus-hub:8500/read",
            params={
                "ip": ip,
                "port": port,
                "device_id": slave_id,
                "type": "coils",
                "address": address,
                "count": 17
            },
            timeout=1
        )

        return r.json().get("bits", [0] * 17)

    except Exception:
        return [0] * 17

def collect_telemetry():
    global fault_cache

    conn = None

    try:

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
                r = requests.get(
                    "http://modbus-hub:8500/read",
                    params={
                        "ip": ip,
                        "port": port,
                        "device_id": slave_id,
                        "type": "register",
                        "address": 6507,
                        "count": 1
                    },
                    timeout=1
                )

                data = r.json().get("registers", [])
                value = data[0] / 10 if data and data[0] is not None else None

            except Exception:
                value = None

            outdoor_cache[key] = value
            return value

        for eq in equipments:

            ui = int(eq["UI"])
            base = 102 + 25 * (ui - 1)

            try:

                #
                # Lecture registres UI
                #
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
                    timeout=1
                )

                registers = r.json().get("registers", [])

                if len(registers) < 15:
                    continue

                state = 1 if registers[0] == 170 else 0
                #
                # Compteur temps de fonctionnement
                #

                if state == 1:

                    cur.execute("""
                        UPDATE equipments
                        SET runtime_seconds = runtime_seconds + %s
                        WHERE id=%s
                    """,
                    (
                        RUNTIME_INCREMENT,
                        eq["id"]
                    ))
                setpoint = registers[2] / 10 if registers[2] is not None else None
                return_temp = registers[14] / 10 if registers[14] is not None else None
                check_temperature_alarm(cur,eq,return_temp)
                
                #
                # Lecture défauts
                #
                coil_addr = 408 + (64 * (ui - 1))

                coils = read_coils(
                    eq["ip"],
                    eq["port"],
                    eq["slave_id"],
                    coil_addr
                )

                fault = 1 if any(coils) else 0

                #
                # Historique défauts
                #
                for i, value in enumerate(coils):

                    code = coil_addr + i

                    current = bool(value)

                    key = (eq["id"], code)

                    previous = fault_cache.get(key)

                    if previous is None:

                        # Initialisation du cache
                        fault_cache[key] = current

                    elif previous != current:

                        fault_cache[key] = current

                        cur.execute("""
                            INSERT INTO equipment_fault_history
                            (
                                equipment_id,
                                fault_code,
                                fault_name,
                                active,
                                created_at
                            )
                            VALUES
                            (%s,%s,%s,%s,NOW())
                        """, (
                            eq["id"],
                            code,
                            FAULTS.get(408 + i, f"Défaut {code}"),
                            current
                        ))

                        print(
                            f"[FAULT] "
                            f"UI{ui} "
                            f"{FAULTS.get(408 + i, code)} "
                            f"{'ACTIVE' if current else 'CLEAR'}",
                            flush=True
                        )

                #
                # Température extérieure
                #
                outside_temp = get_outdoor_temp(
                    eq["ip"],
                    eq["port"],
                    eq["slave_id"]
                )

                #
                # Mise à jour équipement
                #
                cur.execute("""
                    UPDATE equipments
                    SET
                        state=%s,
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

                #
                # Historique télémétrie
                #
                cur.execute("""
                    INSERT INTO equipment_history
                    (
                        equipment_id,
                        created_at,
                        setpoint,
                        return_temp,
                        outside_temp,
                        state,
                        fault
                    )
                    VALUES
                    (%s,NOW(),%s,%s,%s,%s,%s)
                """, (
                    eq["id"],
                    setpoint,
                    return_temp,
                    outside_temp,
                    state,
                    fault
                ))

            except Exception as e:

                print(
                    f"[TELEMETRY ERROR] UI{ui}: {e}",
                    flush=True
                )

            conn.commit()

    except Exception as e:

        print(
            f"[TELEMETRY FATAL] {e}",
            flush=True
        )

    finally:
        if conn:
            try:
                conn.close()
            except Exception:
                pass

def check_temperature_alarm(cur,equipment,return_temp):

    if return_temp is None:
        return


    cur.execute("""
        SELECT
            high_threshold,
            low_threshold,
            delay_seconds,
            fault_name
        FROM equipment_temperature_alarms
        WHERE equipment_id=%s
        AND enabled=1
    """, (
        equipment["id"],
    ))


    alarm = cur.fetchone()


    if not alarm:
        return


    now = datetime.now()


    state = temperature_alarm_cache.setdefault(
        equipment["id"],
        {
            "high_since": None,
            "low_since": None,
            "active_high": False,
            "active_low": False
        }
    )


    #
    # Température haute
    #

    if (
        alarm["high_threshold"]
        and return_temp >= alarm["high_threshold"]
    ):

        if state["high_since"] is None:
            state["high_since"] = now


        elapsed = (
            now - state["high_since"]
        ).total_seconds()


        if (
            elapsed >= alarm["delay_seconds"]
            and not state["active_high"]
        ):

            create_temperature_fault(
                cur,
                equipment,
                alarm["fault_name"],
                True
            )

            state["active_high"] = True


    else:

        state["high_since"] = None


        if state["active_high"]:

            create_temperature_fault(
                cur,
                equipment,
                alarm["fault_name"],
                False
            )

            state["active_high"] = False



    #
    # Température basse
    #

    if (
        alarm["low_threshold"]
        and return_temp <= alarm["low_threshold"]
    ):

        if state["low_since"] is None:
            state["low_since"] = now


        elapsed = (
            now - state["low_since"]
        ).total_seconds()


        if (
            elapsed >= alarm["delay_seconds"]
            and not state["active_low"]
        ):

            create_temperature_fault(
                cur,
                equipment,
                alarm["fault_name"],
                True
            )

            state["active_low"] = True


    else:

        state["low_since"] = None

def create_temperature_fault(cur,equipment,fault_name,active):

    cur.execute("""
        INSERT INTO equipment_fault_history
        (
            equipment_id,
            fault_code,
            fault_name,
            active,
            created_at,
            mail_sent
        )
        VALUES
        (
            %s,
            %s,
            %s,
            %s,
            NOW(),
            0
        )
    """,
    (
        equipment["id"],
        900,
        fault_name,
        active
    ))

def cleanup_history():

    conn = None

    try:

        conn = get_connection()
        cur = conn.cursor()

        cur.execute("""
            DELETE FROM equipment_history
            WHERE created_at < NOW() - INTERVAL 90 DAY
        """)

        deleted = cur.rowcount

        conn.commit()

        if deleted:
            print(
                f"[DB] Historique supprimé : {deleted} lignes",
                flush=True
            )


    except Exception as e:

        print(
            f"[DB CLEANUP ERROR] {e}",
            flush=True
        )


    finally:

        if conn:
            conn.close()

def main():
    wait_for_db()
    print(
        "Scheduler HVAC démarré",
        flush=True
    )
    threading.Thread(
        target=watchdog,
        daemon=True
    ).start()

    while True:
        try:
            print("[SCHED] Discovery",
                flush=True
            )
            run_discovery_if_needed()
            update_watchdog()
            print(
                "[SCHED] Planning",
                flush=True
            )
            process_planning()
            update_watchdog()
            print(
                "[SCHED] Telemetry",
                flush=True
            )
            collect_telemetry()
            update_watchdog()
            print(
                "[SCHED] Mail défaut",
                flush=True
            )
            check_and_send()
            update_watchdog()
            print(
                "[SCHED] Mail Hebdo",
                flush=True
            )
            check_weekly_report()
            update_watchdog()
            print(
                "[SCHED] Cycle terminé",
                flush=True
            )
            check_mail_queue()
            update_watchdog()
            print(
                "[SCHED] Clean history",
                flush=True
            )
            cleanup_history()
            update_watchdog()
        except Exception as e:
            import traceback
            print(
                f"[SCHED ERROR] {e}",
                flush=True
            )
            traceback.print_exc()
        update_watchdog()
        time.sleep(INTERVAL)
       
if __name__ == "__main__":
    main()
