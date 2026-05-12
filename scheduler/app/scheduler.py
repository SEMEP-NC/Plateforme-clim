import time
from db import get_connection
from modbus_client import send_command
from discovery import discover_gree_devices, save_devices

INTERVAL = 900


while True:

    print('Lecture planning...')

    conn = get_connection()

    with conn.cursor() as cursor:

        sql = """
        SELECT
            schedules.*,
            equipments.ip,
            equipments.slave_id

        FROM schedules

        JOIN equipments
            ON equipments.id = schedules.equipment_id

        WHERE executed = 0
        AND execution_time <= NOW()
        """

        cursor.execute(sql)

        schedules = cursor.fetchall()

        for schedule in schedules:

            print(schedule)

            send_command(
                schedule['ip'],
                schedule['slave_id'],
                schedule['action'],
                schedule['temperature']
            )

            update_sql = "UPDATE schedules SET executed = 1 WHERE id = %s"

            cursor.execute(update_sql, (schedule['id']))

        conn.commit()

    conn.close()

    print('Pause 15 minutes...')

    time.sleep(INTERVAL)