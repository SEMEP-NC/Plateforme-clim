import asyncio
import threading
import time
import logging
from fastapi import FastAPI
from pymodbus.client import ModbusTcpClient

# =========================
# LOGGING SETUP
# =========================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [HUB] %(levelname)s - %(message)s"
)

log = logging.getLogger("modbus-hub")

# =========================
# APP
# =========================

app = FastAPI(title="Modbus Hub V2")

# =========================
# CONFIG DEFAULTS
# =========================

DEFAULT_SLAVE = 1
CACHE_TTL = 2  # seconds

# =========================
# CACHE
# =========================

cache = {}
cache_time = {}

def cache_get(key):
    if key in cache and (time.time() - cache_time[key]) < CACHE_TTL:
        log.info(f"[CACHE] HIT {key}")
        return cache[key]

    log.info(f"[CACHE] MISS {key}")
    return None


def cache_set(key, value):
    cache[key] = value
    cache_time[key] = time.time()
    log.info(f"[CACHE] SET {key}")

# =========================
# LOCKS (PAR DEVICE)
# =========================

locks = {}

def get_lock(ip, port):
    key = f"{ip}:{port}"
    if key not in locks:
        locks[key] = threading.Lock()
    return locks[key]

# =========================
# MODBUS CORE
# =========================

def get_client(ip, port):
    return ModbusTcpClient(ip, port=port, timeout=3)


def modbus_read_coils(ip, port, address, count, slave):
    log.info(f"[MODBUS] READ COILS {ip}:{port} addr={address} count={count} slave={slave}")

    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not client.connect():
            log.error(f"[MODBUS] CONNECT FAIL {ip}:{port}")
            raise Exception(f"Cannot connect to {ip}:{port}")

        log.info(f"[MODBUS] CONNECT OK {ip}:{port}")

        result = client.read_coils(address, count, slave=slave)

        client.close()

        if result.isError():
            log.error(f"[MODBUS] COILS ERROR {ip}:{port} -> {result}")
            raise Exception(str(result))

        log.info(f"[MODBUS] COILS OK {ip}:{port}")
        return result


def modbus_read_register(ip, port, address, slave):
    log.info(f"[MODBUS] READ REG {ip}:{port} addr={address} slave={slave}")

    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not client.connect():
            log.error(f"[MODBUS] CONNECT FAIL {ip}:{port}")
            raise Exception(f"Cannot connect to {ip}:{port}")

        result = client.read_holding_registers(address, count=1, slave=slave)

        client.close()

        if result.isError():
            log.error(f"[MODBUS] REG ERROR {ip}:{port} -> {result}")
            raise Exception(str(result))

        log.info(f"[MODBUS] REG OK {ip}:{port}")
        return result

# =========================
# UNIFIED READ API
# =========================

@app.post("/read")
def read_unified(payload: dict):

    log.info(f"[API] READ REQUEST {payload}")

    ip = payload.get("ip")
    port = payload.get("port")
    slave = payload.get("slave", DEFAULT_SLAVE)
    address = payload.get("address")
    count = payload.get("count", 1)
    mode = payload.get("type", "coils")

    key = f"{mode}:{ip}:{port}:{slave}:{address}:{count}"

    cached = cache_get(key)
    if cached is not None:
        return {"success": True, "cached": True, **cached}

    try:
        if mode == "coils":
            result = modbus_read_coils(ip, port, address, count, slave)
            data = {"bits": result.bits}

        elif mode == "register":
            result = modbus_read_register(ip, port, address, slave)
            data = {"registers": result.registers}

        else:
            log.warning(f"[API] INVALID TYPE {mode}")
            return {"success": False, "error": "invalid type"}

        cache_set(key, data)

        log.info(f"[API] READ OK {ip}:{port} mode={mode}")
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
    log.info(f"[API] WRITE QUEUED {payload}")
    await write_queue.put(payload)
    return {"queued": True}


async def write_worker():
    log.info("[WRITE] worker started")

    while True:
        job = await write_queue.get()

        try:
            ip = job["ip"]
            port = job["port"]
            address = job["address"]
            value = job["value"]
            slave = job.get("slave", DEFAULT_SLAVE)

            log.info(f"[WRITE] EXEC {ip}:{port} addr={address} value={value}")

            lock = get_lock(ip, port)

            with lock:
                client = get_client(ip, port)

                if client.connect():
                    client.write_register(address, value, slave=slave)
                    client.close()

                    log.info(f"[WRITE] SUCCESS {ip}:{port} addr={address}")

        except Exception as e:
            log.error(f"[WRITE] ERROR {job} -> {e}")

        await asyncio.sleep(0.05)

# =========================
# LIFECYCLE
# =========================

@app.on_event("startup")
async def startup():
    log.info("[HUB] STARTING")
    asyncio.create_task(write_worker())
    log.info("[HUB] READY")


@app.on_event("shutdown")
def shutdown():
    log.info("[HUB] STOPPED")