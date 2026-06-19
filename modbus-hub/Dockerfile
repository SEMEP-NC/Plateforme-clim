FROM python:3.12-slim

WORKDIR /app

RUN pip install fastapi uvicorn pymodbus

COPY modbus_hub.py .

CMD ["uvicorn", "modbus_hub:app", "--host", "0.0.0.0", "--port", "8000"]