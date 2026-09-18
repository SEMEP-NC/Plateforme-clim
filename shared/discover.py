import ipaddress
import os

import requests

from db import get_connection
from registers import GMV, UMATCH, presence_probe_register


HUB_URL = os.getenv("HUB_URL", "http://modbus-hub:8500/read")

# Plage de coils "présence unité intérieure" pour le GMV (Table GREE).
BIT_START = 120
BIT_END = 374
MAX_SCAN_IPS = int(os.getenv("MAX_SCAN_IPS", "256"))


def load_discovery_configs():
    """
    Charge toutes les passerelles configurées (une ligne = une plage IP avec
    son type de matériel GMV ou UMATCH).
    """
    print("[DISCOVERY] Loading configuration from database", flush=True)

    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT id, name, start_ip, end_ip, ports, slave_ids, type
        FROM discovery_config
    """)

    rows = cur.fetchall()
    conn.close()

    if not rows:
        raise Exception("No discovery configuration found")

    configs = []

    for row in rows:
        configs.append({
            "id": row["id"],
            "name": row.get("name") or f"Config {row['id']}",
            "start_ip": row["start_ip"],
            "end_ip": row["end_ip"],
            "ports": [int(x.strip()) for x in row["ports"].split(",") if x.strip()],
            "slave_ids": [int(x.strip()) for x in row["slave_ids"].split(",") if x.strip()],
            "type": row.get("type") or GMV,
        })

    print(f"[DISCOVERY] {len(configs)} configuration(s) loaded", flush=True)
    return configs


def scan_ip_range(config):
    start_ip = int(ipaddress.IPv4Address(config["start_ip"]))
    end_ip = int(ipaddress.IPv4Address(config["end_ip"]))

    print(f"[DISCOVERY] Building IP range {config['start_ip']} -> {config['end_ip']}", flush=True)

    if start_ip > end_ip:
        raise ValueError("start_ip must be <= end_ip")

    total = end_ip - start_ip + 1
    if total > MAX_SCAN_IPS:
        raise ValueError(f"IP range too large: {total} IPs, max {MAX_SCAN_IPS}")

    ips = [str(ipaddress.IPv4Address(ip)) for ip in range(start_ip, end_ip + 1)]

    print(f"[DISCOVERY] IPs generated: {len(ips)}", flush=True)
    return ips


def hub_read(ip, port, slave, address, count, mode):
    try:
        payload = {
            "ip": ip,
            "port": port,
            "device_id": slave,
            "address": address,
            "count": count,
            "type": mode,
        }

        r = requests.post(HUB_URL, json=payload, timeout=5)

        if r.status_code != 200:
            print(f"[HUB] HTTP {r.status_code} {r.text}", flush=True)
            return None

        return r.json()

    except Exception as e:
        print(f"[HUB] request error: {e}", flush=True)
        return None


# ---------------------------------------------------------------------------
# GMV : plusieurs UI multiplexées derrière un même esclave, détection par
# scan des coils de présence 120-374.
# ---------------------------------------------------------------------------

def check_ui_bits(ip, port, slave_id):
    print(f"[DISCOVERY] Checking {ip}:{port} slave={slave_id}", flush=True)

    devices = []

    # ---------------------------------------------------------
    # Lecture des coils par blocs de maximum 128 bits
    # ---------------------------------------------------------
    MAX_COILS_PER_REQUEST = 128

    all_bits = []

    current_address = BIT_START

    while current_address <= BIT_END:

        count = min(
            MAX_COILS_PER_REQUEST,
            BIT_END - current_address + 1
        )

        print(
            f"[DISCOVERY] Reading coils {current_address} -> "
            f"{current_address + count - 1} ({count} bits)",
            flush=True
        )

        result = hub_read(
            ip=ip,
            port=port,
            slave=slave_id,
            address=current_address,
            count=count,
            mode="coils",
        )

        if not result or not result.get("success"):
            print(
                f"[DISCOVERY] hub error on coils "
                f"{current_address}-{current_address + count - 1}: {result}",
                flush=True
            )
            return []

        bits = result.get("bits", [])

        if not isinstance(bits, list):
            print(
                f"[DISCOVERY] invalid bits format "
                f"for coils {current_address}-{current_address + count - 1}",
                flush=True
            )
            return []

        if len(bits) != count:
            print(
                f"[DISCOVERY] unexpected bit count: "
                f"expected={count}, received={len(bits)}",
                flush=True
            )
            return []

        all_bits.extend(bits)

        current_address += count

    # ---------------------------------------------------------
    # Analyse des bits
    # ---------------------------------------------------------

    for i, bit in enumerate(all_bits):

        if bit:
            coil_address = BIT_START + i

            # 120 -> UI1
            # 121 -> UI2
            # ...
            # 374 -> UI255
            ui_number = coil_address - 119

            print(
                f"[DISCOVERY] UI FOUND UI{ui_number} "
                f"@ {ip}:{port}",
                flush=True
            )

            power = read_ui_power(
                ip,
                port,
                slave_id,
                ui_number
            )

            devices.append({
                "ui": ui_number,
                "ip": ip,
                "port": port,
                "slave": slave_id,
                "power": power,
                "type": GMV,
            })

    return devices


def read_ui_power(ip, port, slave_id, ui_number):
    register = 123 + (25 * (ui_number - 1))

    result = hub_read(
        ip=ip,
        port=port,
        slave=slave_id,
        address=register,
        count=1,
        mode="register",
    )

    if not result or not result.get("success"):
        print(f"[DISCOVERY] no power UI{ui_number}", flush=True)
        return None

    regs = result.get("registers", [])
    return regs[0] if regs else None


# ---------------------------------------------------------------------------
# UMATCH : 1 esclave = 1 unité. Pas de multiplexage : on teste simplement
# la présence de chaque esclave en lisant son registre ON/OFF.
# ---------------------------------------------------------------------------

def check_umatch_slave(ip, port, slave_id):
    print(f"[DISCOVERY] Checking UMATCH {ip}:{port} slave={slave_id}", flush=True)

    register = presence_probe_register(UMATCH)

    result = hub_read(
        ip=ip,
        port=port,
        slave=slave_id,
        address=register,
        count=1,
        mode="register",
    )

    if not result or not result.get("success"):
        return []

    regs = result.get("registers", [])

    if not regs or regs[0] is None:
        return []

    print(
        f"[DISCOVERY] UMATCH FOUND @ {ip}:{port} slave={slave_id}",
        flush=True
    )

    return [{
        "ui": 1,
        "ip": ip,
        "port": port,
        "slave": slave_id,
        "power": None,
        "type": UMATCH,
    }]


def discover():
    print("\n[DISCOVERY] START\n", flush=True)

    configs = load_discovery_configs()

    devices = []

    for config in configs:

        print(
            f"[DISCOVERY] --- Config '{config['name']}' ({config['type']}) ---",
            flush=True
        )

        for ip in scan_ip_range(config):
            for port in config["ports"]:
                for slave in config["slave_ids"]:

                    print(
                        f"[DISCOVERY] scanning {ip}:{port} slave={slave} "
                        f"type={config['type']}",
                        flush=True
                    )

                    if config["type"] == UMATCH:
                        devices.extend(check_umatch_slave(ip, port, slave))
                    else:
                        devices.extend(check_ui_bits(ip, port, slave))

    print(f"[DISCOVERY] DONE -> {len(devices)} devices", flush=True)
    return devices


def save(devices):
    conn = get_connection()
    cur = conn.cursor()

    for d in devices:
        device_id = f"UI-{d['ui']} @ {d['ip']}:{d['port']} slave={d['slave']}"

        cur.execute("""
            INSERT INTO discovered_units
                (device_id, port, slave_id, ip, name, model, type, last_seen, online)
            VALUES
                (%s,%s,%s,%s,%s,%s,%s,NOW(),1)
            ON DUPLICATE KEY UPDATE
                port = VALUES(port),
                slave_id = VALUES(slave_id),
                ip = VALUES(ip),
                name = VALUES(name),
                model = VALUES(model),
                type = VALUES(type),
                last_seen = NOW(),
                online = 1
        """, (
            device_id,
            d["port"],
            d["slave"],
            d["ip"],
            f"UI-{d['ui']}",
            d["power"],
            d.get("type", GMV),
        ))

    conn.commit()
    conn.close()


def cleanup_offline_devices():
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        UPDATE discovered_units
        SET online = 0
        WHERE last_seen < (NOW() - INTERVAL 2 MINUTE)
    """)

    conn.commit()
    conn.close()
