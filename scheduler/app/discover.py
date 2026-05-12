import socket
import json
import time
from db import get_connection

BROADCAST_PORT = 7000
DISCOVERY_MSG = b'{"t":"scan"}'


def discover_gree_devices():
    devices = []

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
    sock.settimeout(3)

    sock.sendto(DISCOVERY_MSG, ('255.255.255.255', BROADCAST_PORT))

    start = time.time()

    while time.time() - start < 3:
        try:
            data, addr = sock.recvfrom(1024)
            msg = json.loads(data.decode(errors='ignore'))

            devices.append({
                "ip": addr[0],
                "device_id": msg.get("id"),
                "mac": msg.get("mac"),
                "name": msg.get("name", "Gree AC"),
                "model": msg.get("model", "unknown")
            })

        except Exception:
            continue

    return devices


def save_devices(devices):
    conn = get_connection()
    cur = conn.cursor()

    for d in devices:
        cur.execute("""
            INSERT INTO discovered_units (device_id, mac, ip, name, model, last_seen, online)
            VALUES (%s, %s, %s, %s, %s, NOW(), 1)
            ON DUPLICATE KEY UPDATE
                ip = VALUES(ip),
                last_seen = NOW(),
                online = 1
        """, (
            d["device_id"],
            d["mac"],
            d["ip"],
            d["name"],
            d["model"]
        ))

    conn.commit()
    conn.close()