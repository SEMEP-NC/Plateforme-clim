import asyncio
import threading
import time
import logging
from fastapi import FastAPI
from pymodbus.client import ModbusTcpClient

# =========================
# LOGGING
# =========================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [HUB] %(levelname)s - %(message)s"
)

log = logging.getLogger("modbus_hub")

# =========================
# APP
# =========================

app = FastAPI(title="Modbus Hub V2")

# =========================
# CONFIG
# =========================

DEFAULT_DEVICE_ID = 1
CACHE_TTL = 2  # seconds

# =========================
# CACHE
# =========================

cache = {}
cache_time = {}

def cache_get(key):
    if key in cache and (time.time() - cache_time[key]) < CACHE_TTL:
        return cache[key]
    return None

def cache_set(key, value):
    cache[key] = value
    cache_time[key] = time.time()

# =========================
# LOCKS PAR DEVICE (IP:PORT)
# =========================

locks = {}

def get_lock(ip, port):
    key = f"{ip}:{port}"
    if key not in locks:
        locks[key] = threading.Lock()
    return locks[key]

# =========================
# CLIENT POOL
# =========================

clients = {}

def get_client(ip, port):
    key = f"{ip}:{port}"

    if key not in clients:
        log.info(f"[POOL] Creating client {key}")
        clients[key] = ModbusTcpClient(ip, port=port, timeout=5)

    return clients[key]

def ensure_connected(client, ip, port):
    if not client.connect():
        log.error(f"[MODBUS] CONNECT FAIL {ip}:{port}")
        return False
    return True

# =========================
# MODBUS CORE (FIXED PYMODBUS 3.x)
# =========================

def modbus_read_coils(ip, port, address, count, device_id):
    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        log.info(
            f"[MODBUS] READ COILS {ip}:{port} "
            f"addr={address} count={count} device_id={device_id}"
        )

        # ✅ pymodbus 3.x: positional ONLY
        return client.read_coils(address, count, device_id)


def modbus_read_register(ip, port, address, device_id):
    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        log.info(
            f"[MODBUS] READ REG {ip}:{port} addr={address} device_id={device_id}"
        )

        # ✅ positional ONLY
        return client.read_holding_registers(address, 1, device_id)

# =========================
# API READ
# =========================

@app.post("/read")
def read_unified(payload: dict):

    log.info(f"[API] READ REQUEST {payload}")

    ip = payload.get("ip")
    port = payload.get("port")
    device_id = payload.get("device_id", DEFAULT_DEVICE_ID)

    address = payload.get("address")
    count = payload.get("count", 1)
    mode = payload.get("type", "coils")

    key = f"{mode}:{ip}:{port}:{device_id}:{address}:{count}"

    cached = cache_get(key)
    if cached is not None:
        log.info(f"[CACHE] HIT {key}")
        return {"success": True, "cached": True, **cached}

    log.info(f"[CACHE] MISS {key}")

    try:
        if mode == "coils":
            result = modbus_read_coils(ip, port, address, count, device_id)

            if result.isError():
                log.error(f"[MODBUS] ERROR {result}")
                return {"success": False, "error": str(result)}

            data = {"bits": result.bits}

        elif mode == "register":
            result = modbus_read_register(ip, port, address, device_id)

            if result.isError():
                log.error(f"[MODBUS] ERROR {result}")
                return {"success": False, "error": str(result)}

            data = {"registers": result.registers}

        else:
            return {"success": False, "error": "invalid type"}

        cache_set(key, data)

        return {"success": True, "cached": False, **data}

    except Exception as e:
        log.error(f"[API] READ ERROR {ip}:{port} -> {e}")
        return {"success": False, "error": str(e)}

# =========================
# WRITE QUEUE
# =========================

write_queue = asyncio.Queue()

@app.post("/write/register")
async def write_register(payload: dict):
    await write_queue.put(payload)
    return {"queued": True}


async def write_worker():
    while True:
        job = await write_queue.get()

        try:
            ip = job["ip"]
            port = job["port"]
            device_id = job.get("device_id", DEFAULT_DEVICE_ID)

            address = job["address"]
            value = job["value"]

            lock = get_lock(ip, port)

            with lock:
                client = get_client(ip, port)

                if ensure_connected(client, ip, port):
                    # ✅ positional ONLY
                    client.write_register(address, value, device_id)

                    log.info(f"[WRITE] {ip}:{port} {address}={value}")

        except Exception as e:
            log.error(f"[WRITE ERROR] {e}")

        await asyncio.sleep(0.05)

# =========================
# LIFECYCLE
# =========================

@app.on_event("startup")
async def startup():
    asyncio.create_task(write_worker())
    log.info("[HUB] started")

@app.on_event("shutdown")
def shutdown():
    for c in clients.values():
        try:
            c.close()
        except:
            pass
    log.info("[HUB] stopped")