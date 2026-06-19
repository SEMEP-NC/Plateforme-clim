import asyncio
from fastapi import FastAPI
from pymodbus.client import ModbusTcpClient
import threading
import time

# =========================
# CONFIG
# =========================

MODBUS_IP = "10.5.0.20"
MODBUS_PORT = 502
SLAVE_DEFAULT = 1

CACHE_TTL = 2  # seconds

# =========================
# APP
# =========================

app = FastAPI(title="Modbus Hub")

client = ModbusTcpClient(MODBUS_IP, port=MODBUS_PORT, timeout=3)

lock = threading.Lock()

cache = {}
cache_time = {}

write_queue = asyncio.Queue()

# =========================
# MODBUS CORE
# =========================

def modbus_connect():
    if not client.connect():
        raise Exception("Cannot connect to Modbus device")


def modbus_read_coils(address, count, slave):
    with lock:
        if not client.connect():
            raise Exception("Modbus connection failed")

        return client.read_coils(address, count, slave=slave)


def modbus_read_register(address, slave):
    with lock:
        if not client.connect():
            raise Exception("Modbus connection failed")

        return client.read_holding_registers(address, count=1, slave=slave)

# =========================
# CACHE SYSTEM
# =========================

def cache_get(key):
    if key in cache and (time.time() - cache_time[key]) < CACHE_TTL:
        return cache[key]
    return None


def cache_set(key, value):
    cache[key] = value
    cache_time[key] = time.time()

# =========================
# API READ
# =========================

@app.get("/read/coils")
def read_coils(address: int, count: int, slave: int = SLAVE_DEFAULT):
    key = f"coils:{address}:{count}:{slave}"

    cached = cache_get(key)
    if cached is not None:
        return {"cached": True, "data": cached}

    result = modbus_read_coils(address, count, slave)

    if result.isError():
        return {"error": str(result)}

    data = result.bits

    cache_set(key, data)

    return {"cached": False, "data": data}


@app.get("/read/register")
def read_register(address: int, slave: int = SLAVE_DEFAULT):
    key = f"reg:{address}:{slave}"

    cached = cache_get(key)
    if cached is not None:
        return {"cached": True, "data": cached}

    result = modbus_read_register(address, slave)

    if result.isError():
        return {"error": str(result)}

    value = result.registers[0]

    cache_set(key, value)

    return {"cached": False, "data": value}

# =========================
# WRITE QUEUE
# =========================

@app.post("/write/register")
async def write_register(payload: dict):
    await write_queue.put(payload)
    return {"queued": True}


async def write_worker():
    while True:
        job = await write_queue.get()

        try:
            address = job["address"]
            value = job["value"]
            slave = job.get("slave", SLAVE_DEFAULT)

            with lock:
                if not client.connect():
                    print("[HUB] write connect failed")
                    continue

                client.write_register(address, value, slave=slave)

            print(f"[HUB] wrote {address}={value}")

        except Exception as e:
            print("[HUB] write error:", e)

        await asyncio.sleep(0.05)

# =========================
# LIFECYCLE
# =========================

@app.on_event("startup")
async def startup():
    modbus_connect()
    asyncio.create_task(write_worker())
    print("[HUB] started")


@app.on_event("shutdown")
def shutdown():
    client.close()
    print("[HUB] stopped")