from pymodbus.client import ModbusTcpClient
from db import get_connection
import ipaddress

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
        print("[DISCOVERY] ❌ No discovery configuration found")
        raise Exception("No discovery configuration found")

    return {
        "start_ip": row["start_ip"],
        "end_ip": row["end_ip"],
        "ports": [
            int(x.strip())
            for x in row["ports"].split(',')
            if x.strip()
        ],
        "slave_ids": [
            int(x.strip())
            for x in row["slave_ids"].split(',')
            if x.strip()
        ]

    }
    




BIT_START = 88
BIT_END = 247



def scan_ip_range(config):
    print(
        f"[DISCOVERY] Building IP range "
        f"{config['start_ip']} -> {config['end_ip']}"
    )

    start_ip = int(ipaddress.IPv4Address(config["start_ip"]))
    end_ip = int(ipaddress.IPv4Address(config["end_ip"]))

    if start_ip > end_ip:
        raise ValueError("IP_RANGE_START doit être <= IP_RANGE_END")

    return [
        str(ipaddress.IPv4Address(ip))
        for ip in range(start_ip, end_ip + 1)

    ]



def check_ui_bits(ip, port, slave_id):
    print(f"[DISCOVERY] ▶ Connecting {ip}:{port} slave={slave_id}")

    client = ModbusTcpClient(ip, port=port)

    try:
        if not client.connect():
            print(f"[DISCOVERY] ❌ Connection failed {ip}:{port}")
            return []

        print(f"[DISCOVERY] ✔ Connected {ip}:{port}")

        # NOTE: selon pymodbus version, unit_id peut être ignoré
        try:
            client.unit_id = slave_id
        except Exception:
            print(f"[DISCOVERY] ⚠ unit_id not supported by this pymodbus version")

        print(f"[DISCOVERY] ▶ Reading coils {BIT_START} → {BIT_END} ({BIT_END - BIT_START + 1} bits)")

        result = client.read_coils(
            BIT_START,
            (BIT_END - BIT_START + 1),
            slave=slave_id
        )

        if not result:
            print(f"[DISCOVERY] ⚠ No response from {ip}:{port}")
            return []
            
        if hasattr(result, "isError") and result.isError():
            print(f"[DISCOVERY] ❌ Modbus error response {ip}:{port} → {result}")
            return []

        if not hasattr(result, "bits"):
            print(f"[DISCOVERY] ❌ Invalid response type {type(result)} → {result}")
            return []
        bits = result.bits or []

        print(f"[DISCOVERY] ✔ Received {len(bits)} bits from {ip}:{port}")

        devices = []

        for i, bit in enumerate(bits):
            if bit:
                coil_address = BIT_START + i
                ui_number = coil_address - 87

                print(f"[DISCOVERY] 🎯 UI FOUND → UI{ui_number} @ {ip}:{port}")

                power = read_ui_power(client, ui_number)

                devices.append({
                    "ui": ui_number,
                    "ip": ip,
                    "port": port,
                    "slave": slave_id,
                    "power": power
                })

        if not devices:
            print(f"[DISCOVERY] ◻ No UI detected on {ip}:{port}")

        return devices

    except Exception as e:
        print(f"[DISCOVERY] ❌ Modbus error {ip}:{port} → {e}")
        return []

    finally:
        client.close()
        print(f"[DISCOVERY] ⛔ Closed connection {ip}:{port}")

def read_ui_power(client, ui_number, slave_id):
    register = 123 + (25 * (ui_number - 1))

    print(
        f"[DISCOVERY] ▶ Reading power register "
        f"UI{ui_number} -> register {register}"
    )

    try:
        result = client.read_holding_registers(
            address=register,
            count=1,
            slave=slave_id
        )
        

        if not result or not result.registers:
            print(
                f"[DISCOVERY] ⚠ No power value for UI{ui_number}"
            )
            return None

        power = result.registers[0]

        print(
            f"[DISCOVERY] ✔ UI{ui_number} power={power}"
        )

        return power

    except Exception as e:
        print(
            f"[DISCOVERY] ❌ Power read error UI{ui_number}: {e}"
        )
        return None

def discover():

    print("\n[DISCOVERY] ====================================")
    print("[DISCOVERY] START")
    print("[DISCOVERY] ====================================\n")

    config = load_discovery_config()

    print(
        f"[DISCOVERY] CONFIG "
        f"start={config['start_ip']} "
        f"end={config['end_ip']} "
        f"ports={config['ports']} "
        f"slaves={config['slave_ids']}"
    )

    devices = []

    for ip in scan_ip_range(config):
        for port in config["ports"]:
            for slave in config["slave_ids"]:

                print(
                    f"[DISCOVERY] Scanning "
                    f"{ip}:{port} slave={slave}"
                )

                result = check_ui_bits(ip, port, slave)

                print(
                    f"[DISCOVERY] Result "
                    f"{len(result)} device(s)"
                )

                if result:
                    devices.extend(result)
    print(
        f"[DISCOVERY] ✔ Discovery completed "
        f"{len(devices)} device(s) found"
    )

    return devices


def save(devices):
    conn = get_connection()
    cur = conn.cursor()

    for d in devices:
        cur.execute("""
            INSERT INTO discovered_units(device_id, port, slave_id, ip, name, model, last_seen, online)
            VALUES(%s,%s,%s,%s,%s,%s,NOW(),1)
            ON DUPLICATE KEY UPDATE last_seen=NOW(), online=1
        """, (
            f"UI-{d['ui']} @ {d['ip']}:{d['port']}",
            d['port'],
            d['slave'],
            d['ip'],
            d['ui'],
            d['power']
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