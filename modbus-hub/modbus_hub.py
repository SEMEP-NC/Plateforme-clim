from pymodbus.datastore import (
    ModbusSequentialDataBlock,
    ModbusSlaveContext,
    ModbusServerContext,
)

from pymodbus.server import StartAsyncTcpServer

import asyncio
import logging
import threading
import time

from fastapi import FastAPI
from pydantic import BaseModel, Field
from pymodbus.client import ModbusTcpClient


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [HUB] %(levelname)s - %(message)s",
)

log = logging.getLogger("modbus_hub")

app = FastAPI(title="Modbus Hub")

DEFAULT_DEVICE_ID = 1
CACHE_TTL = 2
CLIENT_TTL = 60

cache = {}
cache_time = {}
locks = {}
clients = {}
clients_last_use = {}
write_queue = asyncio.Queue()

store = ModbusSlaveContext(
    hr=ModbusSequentialDataBlock(0, [0] * 1000)
)

server_context = ModbusServerContext(
    slaves=store,
    single=True
)


class ReadPayload(BaseModel):
    ip: str
    port: int
    device_id: int = Field(default=DEFAULT_DEVICE_ID)
    address: int
    count: int = Field(default=1, ge=1, le=128)
    type: str


class WritePayload(BaseModel):
    ip: str
    port: int
    slave: int = Field(default=DEFAULT_DEVICE_ID)
    address: int
    value: int | bool | None = None
    type: str


def cache_get(key):
    if key in cache and (time.time() - cache_time[key]) < CACHE_TTL:
        return cache[key]
    return None

    
def cache_set(key, value):
    cache[key] = value
    cache_time[key] = time.time()


def get_lock(ip, port):
    key = f"{ip}:{port}"
    if key not in locks:
        locks[key] = threading.Lock()
    return locks[key]


def get_client(ip, port):
    key = f"{ip}:{port}"

    if key not in clients:
        log.info("[POOL] Creating client %s", key)
        clients[key] = ModbusTcpClient(ip, port=port, timeout=5)

    clients_last_use[key] = time.time()
    return clients[key]


def ensure_connected(client, ip, port):
    try:
        if client.connected:
            return True
    except Exception:
        pass

    ok = client.connect()

    if not ok:
        log.error("[MODBUS] CONNECT FAIL %s:%s", ip, port)
        return False

    return True


def cleanup_clients():
    now = time.time()

    for key in list(clients.keys()):
        if now - clients_last_use.get(key, now) > CLIENT_TTL:
            try:
                clients[key].close()
            except Exception:
                pass
            del clients[key]
            clients_last_use.pop(key, None)
            log.info("[POOL] cleaned %s", key)


def modbus_read_coils(ip, port, address, count, device_id):
    with get_lock(ip, port):
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        return client.read_coils(address, count=count, device_id=device_id)


def modbus_read_register(ip, port, address, device_id):
    with get_lock(ip, port):
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        return client.read_holding_registers(address, count=1, device_id=device_id)

async def start_modbus_server():

    log.info("Starting Modbus TCP Server on 1502")

    server_context[0].setValues(
        3,
        100,
        [1234]
    )

    await StartAsyncTcpServer(
        context=server_context,
        address=("0.0.0.0", 1502),
    )

@app.get("/health")
def health():
    return {"status": "ok", "service": "modbus-hub"}


@app.post("/write")
async def write_unified(payload: WritePayload):
    job = payload.dict()
    log.info("[WRITE API] enqueue payload=%s", job)
    await write_queue.put(job)

    return {"success": True, "queued": True}


async def write_worker():
    while True:
        job = await write_queue.get()

        try:
            ip = job["ip"]
            port = int(job["port"])
            device_id = int(job.get("slave", DEFAULT_DEVICE_ID))
            mode = job["type"]
            address = int(job["address"])
            value = job.get("value")

            with get_lock(ip, port):
                client = get_client(ip, port)

                if not ensure_connected(client, ip, port):
                    continue

                if mode == "coil":
                    result = client.write_coil(address, bool(value), device_id=device_id)
                elif mode == "register":
                    result = client.write_registers(address, [int(value)], device_id=device_id)
                else:
                    log.error("[WRITE] invalid type %s", mode)
                    continue

                if result.isError():
                    log.error("[WRITE ERROR] Modbus exception: %s", result)
                else:
                    log.info("[WRITE SUCCESS] OK")

        except Exception as e:
            log.exception("[WRITE WORKER EXCEPTION] %s", e)
        finally:
            write_queue.task_done()

        await asyncio.sleep(0.02)


@app.post("/read")
def read_unified(payload: ReadPayload):
    data = payload.dict()
    ip = data["ip"]
    port = data["port"]
    device_id = data["device_id"]
    address = data["address"]
    count = data["count"]
    mode = data["type"]

    key = f"{mode}:{ip}:{port}:{device_id}:{address}:{count}"

    cached = cache_get(key)
    if cached is not None:
        return {"success": True, "cached": True, **cached}

    try:
        if mode == "coils":
            result = modbus_read_coils(ip, port, address, count, device_id)

            if result.isError():
                return {"success": False, "error": str(result)}

            response_data = {"bits": result.bits[:count]}

        elif mode == "register":
            result = modbus_read_register(ip, port, address, device_id)

            if result.isError():
                return {"success": False, "error": str(result)}

            response_data = {"registers": result.registers}

        else:
            return {"success": False, "error": "invalid type"}

        cache_set(key, response_data)
        cleanup_clients()

        return {"success": True, "cached": False, **response_data}

    except Exception as e:
        log.error("[API] READ ERROR %s:%s -> %s", ip, port, e)
        return {"success": False, "error": str(e)}


@app.on_event("startup")
async def startup():

    asyncio.create_task(write_worker())

    asyncio.create_task(
        start_modbus_server()
    )

    log.info("[HUB] started")


@app.on_event("shutdown")
def shutdown():
    for c in clients.values():
        try:
            c.close()
        except Exception:
            pass
    log.info("[HUB] stopped")
