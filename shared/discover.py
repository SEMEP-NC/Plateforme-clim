import ipaddress
import os

import requests

from db import get_connection


HUB_URL = os.getenv("HUB_URL", "http://modbus-hub:8500/read")

BIT_START = 120
BIT_END = 247
MAX_SCAN_IPS = int(os.getenv("MAX_SCAN_IPS", "256"))


def load_discovery_config():
    print("[DISCOVERY] Loading configuration from database", flush=True)

    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        SELECT start_ip, end_ip, ports, slave_ids
        FROM discovery_config
        LIMIT 1
    """)

    row = cur.fetchone()
    conn.close()

    if not row:
        raise Exception("No discovery configuration found")

    config = {
        "start_ip": row["start_ip"],
        "end_ip": row["end_ip"],
        "ports": [int(x.strip()) for x in row["ports"].split(",") if x.strip()],
        "slave_ids": [int(x.strip()) for x in row["slave_ids"].split(",") if x.strip()],
    }

    print(f"[DISCOVERY] CONFIG loaded: {config}", flush=True)
    return config


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


def check_ui_bits(ip, port, slave_id):
    print(f"[DISCOVERY] Checking {ip}:{port} slave={slave_id}", flush=True)

    result = hub_read(
        ip=ip,
        port=port,
        slave=slave_id,
        address=BIT_START,
        count=(BIT_END - BIT_START + 1),
        mode="coils",
    )

    if not result or not result.get("success"):
        print(f"[DISCOVERY] hub error {result}", flush=True)
        return []

    bits = result.get("bits", [])

    if not isinstance(bits, list):
        print("[DISCOVERY] invalid bits format", flush=True)
        return []

    devices = []

    for i, bit in enumerate(bits):
        if bit:
            coil_address = BIT_START + i
            ui_number = coil_address - 119

            print(f"[DISCOVERY] UI FOUND UI{ui_number} @ {ip}:{port}", flush=True)

            power = read_ui_power(ip, port, slave_id, ui_number)

            devices.append({
                "ui": ui_number,
                "ip": ip,
                "port": port,
                "slave": slave_id,
                "power": power,
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


def discover():
    print("\n[DISCOVERY] START\n", flush=True)

    config = load_discovery_config()

    devices = []

    for ip in scan_ip_range(config):
        for port in config["ports"]:
            for slave in config["slave_ids"]:
                print(f"[DISCOVERY] scanning {ip}:{port} slave={slave}", flush=True)
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
                (device_id, port, slave_id, ip, name, model, last_seen, online)
            VALUES
                (%s,%s,%s,%s,%s,%s,NOW(),1)
            ON DUPLICATE KEY UPDATE
                port = VALUES(port),
                slave_id = VALUES(slave_id),
                ip = VALUES(ip),
                name = VALUES(name),
                model = VALUES(model),
                last_seen = NOW(),
                online = 1
        """, (
            device_id,
            d["port"],
            d["slave"],
            d["ip"],
            f"UI-{d['ui']}",
            d["power"],
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