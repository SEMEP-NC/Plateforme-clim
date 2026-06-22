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
CACHE_TTL = 2

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
# LOCKS PAR DEVICE
# =========================

locks = {}

def get_lock(ip, port):
    key = f"{ip}:{port}"
    if key not in locks:
        locks[key] = threading.Lock()
    return locks[key]

# =========================
# CLIENT POOL (ROBUSTE)
# =========================

clients = {}
clients_last_use = {}
CLIENT_TTL = 60  # cleanup soft

def get_client(ip, port):
    key = f"{ip}:{port}"

    if key not in clients:
        log.info(f"[POOL] Creating client {key}")
        clients[key] = ModbusTcpClient(ip, port=port, timeout=5)

    clients_last_use[key] = time.time()
    return clients[key]


def ensure_connected(client, ip, port):
    try:
        if client.connected:
            return True
    except:
        pass

    ok = client.connect()

    if not ok:
        log.error(f"[MODBUS] CONNECT FAIL {ip}:{port}")
        return False

    return True


def cleanup_clients():
    """évite fuite mémoire sur devices dynamiques"""
    now = time.time()

    for key in list(clients.keys()):
        if now - clients_last_use.get(key, now) > CLIENT_TTL:
            try:
                clients[key].close()
            except:
                pass
            del clients[key]
            clients_last_use.pop(key, None)
            log.info(f"[POOL] cleaned {key}")

# =========================
# MODBUS READ (INCHANGÉ LOGIQUE)
# =========================

def modbus_read_coils(ip, port, address, count, device_id):
    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        log.info(
            f"[MODBUS] READ COILS {ip}:{port} addr={address} "
            f"count={count} device_id={device_id}"
        )

        return client.read_coils(address, count=count, device_id=device_id)


def modbus_read_register(ip, port, address, device_id):
    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        log.info(
            f"[MODBUS] READ REG {ip}:{port} addr={address} device_id={device_id}"
        )

        return client.read_holding_registers(address, count=1, device_id=device_id)

# =========================
# WRITE QUEUE (ROBUSTE + ASYNC SAFE)
# =========================

write_queue = asyncio.Queue()

@app.post("/write")
async def write_unified(payload: dict):

    await write_queue.put(payload)
    return {"success": True, "queued": True}


async def write_worker():

    while True:
        job = await write_queue.get()

        try:
            ip = job.get("ip")
            port = job.get("port")
            device_id = job.get("slave", DEFAULT_DEVICE_ID)
            mode = job.get("type")
            address = job.get("address")
            value = job.get("value")

            if not all([ip, port, mode, address is not None]):
                log.error(f"[WRITE] invalid payload {job}")
                continue

            lock = get_lock(ip, port)

            with lock:
                client = get_client(ip, port)

                if not ensure_connected(client, ip, port):
                    continue

                log.info(
                    f"[MODBUS WRITE] {ip}:{port} "
                    f"type={mode} addr={address} value={value} device_id={device_id}"
                )

                if mode == "coil":
                    result = client.write_coil(address, value, device_id=device_id)

                elif mode == "register":
                    result = client.write_register(address, value, device_id=device_id)

                else:
                    log.error(f"[WRITE] invalid type {mode}")
                    continue

                if result.isError():
                    log.error(f"[WRITE ERROR] {result}")

        except Exception as e:
            log.error(f"[WRITE WORKER ERROR] {e}")

        await asyncio.sleep(0.02)

# =========================
# READ API (INCHANGÉ COMPORTEMENT)
# =========================

@app.post("/read")
def read_unified(payload: dict):

    log.info(f"[API] READ REQUEST {payload}")

    ip = payload.get("ip")
    port = payload.get("port")
    device_id = payload.get("device_id", DEFAULT_DEVICE_ID)

    address = payload.get("address")
    count = payload.get("count", 1)
    mode = payload.get("type")

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