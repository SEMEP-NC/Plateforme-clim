from pymodbus.client import ModbusTcpClient
from db import get_connection
import ipaddress

# CONFIGURATION
IP_RANGE_START = "10.0.0.50"
IP_RANGE_END   = "10.0.0.50"
PORTS = [1502]
SLAVE_IDS = [1]

BIT_START = 88
BIT_END = 247

print(f"🔎 Scanning {ip}", flush=True)

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
    client = ModbusTcpClient(ip, port=port)

    try:
        if not client.connect():
            return []

        result = client.read_coils(
            BIT_START,
            BIT_END - BIT_START + 1,
            unit=slave_id
        )

        if result.isError():
            return []

        found = []

        for i, bit in enumerate(result.bits):
            if bit == 1:
                ui_number = BIT_START + i

                found.append({
                    "ui": ui_number,
                    "ip": ip,
                    "port": port,
                    "slave_id": slave_id
                })

        return found

    except Exception as e:
        print("Discovery error:", e)
        return []

    finally:
        client.close()


def discover():
    devices = []

    ips = scan_ip_range()

    for ip in ips:
        for port in PORTS:
            for slave in SLAVE_IDS:
                devices.extend(check_ui_bits(ip, port, slave))

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
            "Modbus-UI"
        ))

    conn.commit()
    conn.close()