import os
import time

import pymysql


def get_connection():
    host = os.getenv("DB_HOST")
    user = os.getenv("DB_USER")
    password = os.getenv("DB_PASSWORD")
    database = os.getenv("DB_NAME")

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