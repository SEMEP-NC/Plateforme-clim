import asyncio
import logging
import threading
import time

from fastapi import FastAPI, Query
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
CLIENT_MAX_AGE = 3600   # 1 heure
RESET_INTERVAL = 300    # contrôle toutes les 5 minutes

cache = {}
cache_time = {}
clients_created = {}
locks = {}
clients = {}
clients_last_use = {}
write_queue = asyncio.Queue()


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
    values: list[int] | None = None
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
    now = time.time()
    if key in clients:
        age = now - clients_created.get(key, now)
        if age > CLIENT_MAX_AGE:
            log.warning(
                "[POOL] Client too old, resetting %s age=%ss",
                key,
                int(age)
            )
            reset_client(ip, port)

    if key not in clients:
        log.info(
            "[POOL] Creating client %s",
            key
        )
        clients[key] = ModbusTcpClient(
            ip,
            port=port,
            timeout=5
        )
        clients_created[key] = now

    clients_last_use[key] = now
    return clients[key]

def ensure_connected(client, ip, port):
    try:
        if client.connected:
            return True
    except Exception:
        pass
    try:
        client.close()
    except Exception:
        pass
    ok = client.connect()
    if not ok:
        log.error(
            "[MODBUS] CONNECT FAIL %s:%s",
            ip,
            port
        )
        return False
    log.info(
        "[MODBUS] CONNECT OK %s:%s",
        ip,
        port
    )
    return True

def cleanup_clients():
    now = time.time()
    for key in list(clients.keys()):
        if now - clients_last_use.get(key, now) > CLIENT_TTL:
            ip, port = key.split(":")
            reset_client(
                ip,
                int(port)
            )
            log.info(
                "[POOL] cleaned %s",
                key
            )

def modbus_read_coils(ip, port, address, count, device_id):
    with get_lock(ip, port):
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        return client.read_coils(address, count=count, device_id=device_id)


def modbus_read_registers(ip, port, address, count, device_id):
    with get_lock(ip, port):
        client = get_client(ip, port)

        if not ensure_connected(client, ip, port):
            raise Exception(f"Cannot connect to {ip}:{port}")

        return client.read_holding_registers(address, count=count, device_id=device_id)

def connection_watchdog():
    while True:
        try:
            now = time.time()
            for key in list(clients.keys()):
                age = now - clients_created.get(key, now)
                idle = now - clients_last_use.get(key, now)

                if age > CLIENT_MAX_AGE:
                    ip, port = key.split(":")
                    log.warning(
                        "[WATCHDOG] Reset old client %s age=%ss",
                        key,
                        int(age)
                    )
                    reset_client(ip, int(port))

                elif idle > CLIENT_TTL:
                    ip, port = key.split(":")
                    log.info(
                        "[WATCHDOG] Closing idle client %s",
                        key
                    )
                    reset_client(ip, int(port))

        except Exception as e:
            log.exception(
                "[WATCHDOG ERROR] %s",
                e
            )

        time.sleep(RESET_INTERVAL)

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
            values = job.get("values")
            value = job.get("value")

            with get_lock(ip, port):
                client = get_client(ip, port)

                if not ensure_connected(client, ip, port):
                    continue

                if mode == "coil":
                    result = client.write_coil(address, bool(value), device_id=device_id)
                elif mode == "coils":
                    safe_values = [bool(v) for v in values]

                    result = client.write_coils(
                        address,
                        safe_values,
                        device_id=device_id
                    )
                elif mode == "register":
                      # CAS MULTI REGISTRES
                    if values is not None:
                        safe_values = [
                            int(v) if v is not None else 0
                            for v in values
                        ]

                        result = client.write_registers(
                            address,
                            safe_values,
                            device_id=device_id
                        )

                    # CAS SINGLE REGISTER (compat legacy)
                    else:
                        safe_value = 0 if value is None else int(value)

                        result = client.write_registers(
                            address,
                            [safe_value],
                            device_id=device_id
                        )
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

def reset_client(ip, port):
    key = f"{ip}:{port}"
    with get_lock(ip, port):
        client = clients.pop(key, None)
        if client:
            try:
                client.close()
            except Exception:
                pass
        clients_last_use.pop(key, None)
        clients_created.pop(key, None)
        log.warning(
            "[POOL] RESET client %s",
            key
        )

def read_modbus(ip, port, device_id, address, count, mode):
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
            result = modbus_read_registers(ip, port, address, count, device_id)

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
        reset_client(ip, port)
        return {"success": False, "error": str(e)}


@app.post("/read")
def read_unified(payload: ReadPayload):
    data = payload.dict()

    return read_modbus(
        ip=data["ip"],
        port=data["port"],
        device_id=data["device_id"],
        address=data["address"],
        count=data["count"],
        mode=data["type"],
    )


@app.get("/read")
def read_get(
    ip: str = Query(..., description="Adresse IP de la passerelle Modbus TCP"),
    port: int = Query(502, description="Port Modbus TCP"),
    address: int = Query(..., description="Adresse coil/register"),
    type: str = Query("register", description="register ou coils"),
    count: int = Query(1, ge=1, le=128, description="Nombre de valeurs à lire"),
    device_id: int = Query(DEFAULT_DEVICE_ID, description="Slave ID Modbus"),
    ):
    response = read_modbus(
        ip=ip,
        port=port,
        device_id=device_id,
        address=address,
        count=count,
        mode=type,
    )

    if response.get("success") and count == 1:
        if "registers" in response and response["registers"]:
            response["value"] = response["registers"][0]
        elif "bits" in response and response["bits"]:
            response["value"] = response["bits"][0]

    return response

@app.get("/write")
async def write_get(
    ip: str = Query(..., description="Adresse IP de la passerelle Modbus TCP"),
    port: int = Query(502, description="Port Modbus TCP"),
    address: int = Query(..., description="Adresse coil/register"),
    value: str = Query(..., description="Valeur à écrire"),
    type: str = Query("register", description="register ou coil"),
    device_id: int = Query(DEFAULT_DEVICE_ID, description="Slave ID Modbus"),
    ):
    if type == "coil":
        normalized = value.strip().lower()
        parsed_value = normalized in ("1", "true", "on", "yes")
    elif type == "register":
        parsed_value = int(value)
    else:
        return {"success": False, "queued": False, "error": "invalid type"}

    job = {
        "ip": ip,
        "port": port,
        "slave": device_id,
        "type": type,
        "address": address,
        "value": parsed_value,
    }

    log.info("[WRITE GET] enqueue payload=%s", job)
    await write_queue.put(job)

    return {"success": True, "queued": True, "job": job}


@app.on_event("startup")
async def startup():
    asyncio.create_task(write_worker())
    threading.Thread(
        target=connection_watchdog,
        daemon=True
    ).start()

    log.info("[HUB] started")


@app.on_event("shutdown")
def shutdown():
    for c in clients.values():
        try:
            c.close()
        except Exception:
            pass
    log.info("[HUB] stopped")
