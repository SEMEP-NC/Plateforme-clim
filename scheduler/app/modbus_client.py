from pymodbus.client import ModbusTcpClient


def send_command(ip, slave_id, action, temperature=None):

    client = ModbusTcpClient(ip, port=502)

    try:
        client.connect()

        if action == 'ON':
            client.write_register(100, 1, slave=slave_id)

        if action == 'OFF':
            client.write_register(100, 0, slave=slave_id)

        if temperature:
            client.write_register(101, int(temperature), slave=slave_id)

        print(f'Commande envoyée à {ip}')

    except Exception as e:
        print(f'Erreur Modbus : {e}')

    finally:
        client.close()