import ipaddress
import requests
from db import get_connection

# =========================
# CONFIG
# =========================

HUB_URL = "http://modbus-hub:8500/read"

BIT_START = 120
BIT_END = 247


# =========================
# CONFIG DB
# =========================

def load_discovery_config():
    print("[DISCOVERY] Loading configuration from database")

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

    print(f"[DISCOVERY] CONFIG loaded: {config}")
    return config


# =========================
# IP RANGE
# =========================

def scan_ip_range(config):
    start_ip = int(ipaddress.IPv4Address(config["start_ip"]))
    end_ip = int(ipaddress.IPv4Address(config["end_ip"]))

    print(f"[DISCOVERY] Building IP range {config['start_ip']} -> {config['end_ip']}")

    if start_ip > end_ip:
        raise ValueError("start_ip must be <= end_ip")

    ips = [str(ipaddress.IPv4Address(ip)) for ip in range(start_ip, end_ip + 1)]

    print(f"[DISCOVERY] IPs generated: {len(ips)}")
    return ips


# =========================
# HUB CALL
# =========================

def hub_read(ip, port, slave, address, count, type):
    """
    Appel HTTP vers modbus-hub
    """
    try:
        payload = {
            "ip": ip,
            "port": port,
            "slave": slave,
            "address": address,
            "count": count,
            "type": type
        }

        r = requests.post(HUB_URL, json=payload, timeout=5)

        if r.status_code != 200:
            print(f"[HUB] ❌ HTTP {r.status_code} {r.text}")
            return None

        data = r.json()

        # format attendu :
        # { "success": true, "bits": [...] } ou { "registers": [...] }

        return data

    except Exception as e:
        print(f"[HUB] ❌ request error: {e}")
        return None


# =========================
# CHECK UI COILS
# =========================

def check_ui_bits(ip, port, slave_id):
    print(f"[DISCOVERY] ▶ Checking {ip}:{port} slave={slave_id}")

    result = hub_read(
        ip=ip,
        port=port,
        slave=slave_id,
        address=BIT_START,
        count=(BIT_END - BIT_START + 1),
        type="coil"
    )

    if not result:
        return []

    if not result.get("success"):
        print(f"[DISCOVERY] ❌ hub error {result}")
        return []

    bits = result.get("bits", [])

    if not isinstance(bits, list):
        print(f"[DISCOVERY] ❌ invalid bits format")
        return []

    print(f"[DISCOVERY] ✔ Received {len(bits)} bits")

    devices = []

    for i, bit in enumerate(bits):
        if bit:
            coil_address = BIT_START + i
            ui_number = coil_address - 119

            print(f"[DISCOVERY] 🎯 UI FOUND → UI{ui_number} @ {ip}:{port}")

            power = read_ui_power(ip, port, slave_id, ui_number)

            devices.append({
                "ui": ui_number,
                "ip": ip,
                "port": port,
                "slave": slave_id,
                "power": power
            })

    return devices


# =========================
# POWER READ
# =========================

def read_ui_power(ip, port, slave_id, ui_number):
    register = 123 + (25 * (ui_number - 1))

    print(f"[DISCOVERY] ▶ Power UI{ui_number} reg={register}")

    result = hub_read(
        ip=ip,
        port=port,
        slave=slave_id,
        address=register,
        count=1,
        type="register"

    )

    if not result or not result.get("success"):
        print(f"[DISCOVERY] ⚠ no power UI{ui_number}")
        return None

    regs = result.get("registers", [])

    if not regs:
        return None

    return regs[0]


# =========================
# DISCOVERY
# =========================

def discover():
    print("\n[DISCOVERY] =======================")
    print("[DISCOVERY] START")
    print("[DISCOVERY] =======================\n")

    config = load_discovery_config()

    devices = []

    for ip in scan_ip_range(config):
        for port in config["ports"]:
            for slave in config["slave_ids"]:

                print(f"[DISCOVERY] scanning {ip}:{port} slave={slave}")

                found = check_ui_bits(ip, port, slave)

                print(f"[DISCOVERY] result {len(found)} devices")

                devices.extend(found)

    print(f"[DISCOVERY] DONE → {len(devices)} devices")
    return devices


# =========================
# SAVE DB
# =========================

def save(devices):
    conn = get_connection()
    cur = conn.cursor()

    for d in devices:
        device_id = f"UI-{d['ui']} @ {d['ip']}:{d['port']}"

        cur.execute("""
            INSERT INTO discovered_units
                (device_id, port, slave_id, ip, name, model, last_seen, online)
            VALUES
                (%s,%s,%s,%s,%s,%s,NOW(),1)
            ON DUPLICATE KEY UPDATE
                last_seen = NOW(),
                online = 1
        """, (
            device_id,
            d["port"],
            d["slave"],
            d["ip"],
            d["ui"],
            d["power"]
        ))

    conn.commit()
    conn.close()


# =========================
# CLEANUP
# =========================

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