from flask import Flask, jsonify, request
from discover import discover, save

app = Flask(__name__)

@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"})

@app.route("/run-discovery", methods=["POST", "GET"])
def run_discovery():
    try:
        print("[API] Discovery triggered")

        devices = discover()

        save(devices)

        return jsonify({
            "status": "success",
            "devices_found": len(devices)
        })

    except Exception as e:
        return jsonify({
            "status": "error",
            "message": str(e)
        }), 500


@app.route("/status", methods=["GET"])
def status():
    return jsonify({
        "service": "scheduler",
        "running": True
    })


if __name__ == "__main__":
    app.run(
        host="0.0.0.0",
        port=5001,
        debug=False
    )