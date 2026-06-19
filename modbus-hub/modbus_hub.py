import asyncio
import threading
import time
from fastapi import FastAPI
from pymodbus.client import ModbusTcpClient

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
        return cache[key]
    return None

def cache_set(key, value):
    cache[key] = value
    cache_time[key] = time.time()

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
    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not client.connect():
            raise Exception(f"Cannot connect to {ip}:{port}")

        result = client.read_coils(address, count, slave=slave)
        client.close()

        return result


def modbus_read_register(ip, port, address, slave):
    lock = get_lock(ip, port)

    with lock:
        client = get_client(ip, port)

        if not client.connect():
            raise Exception(f"Cannot connect to {ip}:{port}")

        result = client.read_holding_registers(address, count=1, slave=slave)
        client.close()

        return result

# =========================
# UNIFIED READ API
# =========================

@app.post("/read")
def read_unified(payload: dict):
    """
    payload:
    {
        "ip": "10.x.x.x",
        "port": 502,
        "slave": 1,
        "address": 88,
        "count": 10,
        "type": "coils" | "register"
    }
    """

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

            if result.isError():
                return {"success": False, "error": str(result)}

            data = {"bits": result.bits}

        elif mode == "register":
            result = modbus_read_register(ip, port, address, slave)

            if result.isError():
                return {"success": False, "error": str(result)}

            data = {"registers": result.registers}

        else:
            return {"success": False, "error": "invalid type"}

        cache_set(key, data)

        return {"success": True, "cached": False, **data}

    except Exception as e:
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
            address = job["address"]
            value = job["value"]
            slave = job.get("slave", DEFAULT_SLAVE)

            lock = get_lock(ip, port)

            with lock:
                client = get_client(ip, port)

                if client.connect():
                    client.write_register(address, value, slave=slave)
                    client.close()

                    print(f"[HUB] wrote {ip}:{port} {address}={value}")

        except Exception as e:
            print("[HUB] write error:", e)

        await asyncio.sleep(0.05)

# =========================
# LIFECYCLE
# =========================

@app.on_event("startup")
async def startup():
    asyncio.create_task(write_worker())
    print("[HUB] started on /read + /write")


@app.on_event("shutdown")
def shutdown():
    print("[HUB] stopped")