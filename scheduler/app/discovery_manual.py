from discover import discover, save

print("🚀 Manual discovery started")

try:
    devices = discover()

    print(f"Detected devices: {len(devices)}")

    save(devices)
    cur.execute("""
            UPDATE discovered_units
            SET online = 0
            WHERE last_seen < (NOW() - INTERVAL 2 MINUTE)
        """)
    print("Discovery completed")

except Exception as e:
    print(f"Discovery error: {e}")
    raise