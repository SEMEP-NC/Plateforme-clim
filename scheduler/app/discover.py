from pymodbus.client import ModbusTcpClient
from db import get_connection
import ipaddress

def load_discovery_config():
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

    return {
        "start_ip": row[0],
        "end_ip": row[1],
        "ports": [int(x.strip()) for x in row[2].split(',') if x.strip()],
        "slave_ids": [int(x.strip()) for x in row[3].split(',') if x.strip()]
    }


CONFIG = load_discovery_config()

IP_RANGE_START = CONFIG["start_ip"]
IP_RANGE_END = CONFIG["end_ip"]
PORTS = CONFIG["ports"]
SLAVE_IDS = CONFIG["slave_ids"]


BIT_START = 88
BIT_END = 247



def scan_ip_range():
    start_ip = int(ipaddress.IPv4Address(IP_RANGE_START))
    end_ip = int(ipaddress.IPv4Address(IP_RANGE_END))

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
            (BIT_END - BIT_START + 1)
        )

        if not result:
            print(f"[DISCOVERY] ⚠ No response from {ip}:{port}")
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

def read_ui_power(client, ui_number):
    register = 123 + (25 * (ui_number - 1))

    print(
        f"[DISCOVERY] ▶ Reading power register "
        f"UI{ui_number} -> register {register}"
    )

    try:
        result = client.read_holding_registers(
            address=register,
            count=1
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
    devices = []

    for ip in scan_ip_range():
        for port in PORTS:
            for slave in SLAVE_IDS:

                result = check_ui_bits(ip, port, slave)

                if result:
                    devices.extend(result)

    return devices


def save(devices):
    conn = get_connection()
    cur = conn.cursor()

    for d in devices:
        cur.execute("""
            INSERT INTO discovered_units(device_id, mac, ip, name, model, last_seen, online)
            VALUES(%s,%s,%s,%s,%s,NOW(),1)
            ON DUPLICATE KEY UPDATE last_seen=NOW(), online=1
        """, (
            f"UI-{d['ui']}",
            None,
            d['ip'],
            f"UI {d['ui']}",
            d['power']
        ))

    conn.commit()
    conn.close()