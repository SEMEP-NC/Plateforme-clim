import pymysql


def get_connection():
    return pymysql.connect(
        host='db',
        user='climuser',
        password='climpassword',
        database='clim_manager',
        cursorclass=pymysql.cursors.DictCursor
    )