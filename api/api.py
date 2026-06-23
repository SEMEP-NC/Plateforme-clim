from flask import Flask, jsonify, request
from discover import cleanup_offline_devices, discover, save

app = Flask(__name__)

@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "service": "api"})

@app.route("/run-discovery", methods=["POST", "GET"])
def run_discovery():
    try:
        print("[API] Discovery triggered", flush=True)

        devices = discover()
        save(devices)
        cleanup_offline_devices()

        return jsonify({
            "status": "success",
            "devices_found": len(devices),
            "devices": devices
        })

    except Exception as e:
        return jsonify({
            "status": "error",
            "message": str(e)
        }), 500


@app.route("/status", methods=["GET"])
def status():
    return jsonify({
        "service": "api",
        "running": True
    })


if __name__ == "__main__":
    app.run(
        host="0.0.0.0",
        port=5001,
        debug=False
    )