import pymysql
import time


def get_connection():
    for i in range(20):
        try:
            return pymysql.connect(
                host='db',
                user='climuser',
                password='climpassword',
                database='clim_manager',
                cursorclass=pymysql.cursors.DictCursor
            )
        except Exception as e:
            print(f"DB not ready ({i}/20): {e}")
            time.sleep(3)

    raise Exception("Impossible de se connecter à MySQL")