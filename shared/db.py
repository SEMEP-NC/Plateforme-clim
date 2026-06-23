import os
import time

import pymysql


def get_connection():
    host = os.getenv("DB_HOST", "db")
    user = os.getenv("DB_USER", "climuser")
    password = os.getenv("DB_PASSWORD", "climpassword")
    database = os.getenv("DB_NAME", "clim_manager")

    for i in range(20):
        try:
            return pymysql.connect(
                host=host,
                user=user,
                password=password,
                database=database,
                cursorclass=pymysql.cursors.DictCursor,
            )
        except Exception as e:
            print(f"DB not ready ({i}/20): {e}", flush=True)
            time.sleep(3)

    raise Exception("Impossible de se connecter à MySQL")