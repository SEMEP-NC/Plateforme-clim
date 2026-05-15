from discover import discover, save

print("🚀 Manual discovery started")

try:
    devices = discover()

    print(f"Detected devices: {len(devices)}")

    save(devices)
    cleanup_offline_devices()   
    print("Discovery completed")

except Exception as e:
    print(f"Discovery error: {e}")
    raise