# raspberry_pi_server.py (VERSI 3.0 - ON-DEMAND STREAM & ANTI-DEADLOCK)
import os
import datetime
import time
import threading
from flask import Flask, jsonify, Response
import io

try:
    from picamera2 import Picamera2
    from picamera2.encoders import JpegEncoder
    from picamera2.outputs import FileOutput
    print("Picamera2 imported successfully.")
except ImportError:
    print("!!! CRITICAL: Picamera2 library not found. Please run 'sudo apt install -y python3-picamera2'")
    exit()

# --- Konfigurasi ---
# Tetap sama seperti sebelumnya
IMAGE_CAPTURE_DIR = "captured_images"
WASABI_ACCESS_KEY_ID = os.environ.get("WASABI_ACCESS_KEY_ID_PI", "SVKOYYR9J5IDOW6O43PH")
WASABI_SECRET_ACCESS_KEY = os.environ.get("WASABI_SECRET_ACCESS_KEY_PI", "nMdCw9rBBe6Z4RztC43ET1pIKjeh1c9fNSQFVqUn")
WASABI_BUCKET_NAME = os.environ.get("WASABI_BUCKET_NAME_PI", "predict-melon-ta-um2")
WASABI_ENDPOINT_URL = os.environ.get("WASABI_ENDPOINT_URL_PI", "https://s3.ap-southeast-1.wasabisys.com")
WASABI_REGION = os.environ.get("WASABI_REGION_PI", "ap-southeast-1")
S3_CAPTURE_PATH_PREFIX = "uploads_temp/"

# --- Inisialisasi ---
app = Flask(__name__)
picam2 = Picamera2()
camera_lock = threading.Lock() # Lock tetap penting untuk operasi atomik

# Konfigurasi TIDAK dijalankan di awal, tapi saat dibutuhkan
preview_config = picam2.create_preview_configuration(main={"size": (640, 480)})
capture_config = picam2.create_still_configuration(main={"size": (1920, 1080)})
print("Picamera2 object initialized.")


# Fungsi upload_to_s3 (tetap sama)
def upload_to_s3(local_file_path, bucket_name, s3_key):
    try:
        import boto3
        s3 = boto3.client('s3', aws_access_key_id=WASABI_ACCESS_KEY_ID, aws_secret_access_key=WASABI_SECRET_ACCESS_KEY, endpoint_url=WASABI_ENDPOINT_URL, region_name=WASABI_REGION)
        with open(local_file_path, "rb") as f:
            s3.upload_fileobj(f, bucket_name, s3_key)
        print(f"-> S3 Upload Success: {s3_key}")
        return True
    except Exception as e:
        print(f"!!! S3 Upload ERROR: {e}")
        return False

# --- Endpoint & Logika Baru ---

class StreamingOutput(io.BufferedIOBase):
    def __init__(self):
        self.frame = None
        self.condition = threading.Condition()
    def write(self, buf):
        with self.condition:
            self.frame = buf
            self.condition.notify_all()

@app.route('/video_feed')
def video_feed():
    """Endpoint yang sekarang mengontrol start/stop stream secara mandiri."""
    print("[Stream] Client connected. Acquiring camera lock...")

    # Kunci kamera agar tidak bentrok dengan permintaan capture
    camera_lock.acquire()
    print("[Stream] Camera lock acquired. Starting camera for streaming...")

    picam2.configure(preview_config)
    output = StreamingOutput()
    picam2.start_recording(JpegEncoder(), FileOutput(output))

    try:
        while True:
            with output.condition:
                output.condition.wait()
                frame = output.frame
            yield (b'--frame\r\n'
                   b'Content-Type: image/jpeg\r\n\r\n' + frame + b'\r\n')
    except GeneratorExit:
        # Ini akan terjadi saat client (browser) menutup koneksi
        print("[Stream] Client disconnected.")
    finally:
        # Apapun yang terjadi, hentikan recording dan bebaskan kamera
        picam2.stop_recording()
        camera_lock.release()
        print("[Stream] Recording stopped and camera lock released.")

@app.route('/trigger-capture-upload', methods=['POST'])
def trigger_capture_upload_route():
    print("\n" + "="*50)
    print(f"[{datetime.datetime.now()}] HIGH-RES CAPTURE requested. Acquiring lock...")

    with camera_lock:
        print("[Capture] Camera lock acquired.")
        timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S_%f")
        filename = f"rpi_capture_{timestamp}.jpg"
        filepath = os.path.join(IMAGE_CAPTURE_DIR, filename)

        try:
            print("[Capture] Configuring for high-res and capturing...")
            picam2.configure(capture_config)
            picam2.start() # Perlu start setelah configure
            time.sleep(1) # Beri waktu untuk sensor menyesuaikan diri
            picam2.capture_file(filepath)
            picam2.stop()
            print(f"[Capture] Success: {filepath}")
        except Exception as e:
            print(f"!!! CAPTURE ERROR: {e}")
            return jsonify({"success": False, "message": "Gagal mengambil gambar (internal Pi error)."}), 500

    print("[Capture] Lock released. Uploading to S3...")
    s3_file_key = S3_CAPTURE_PATH_PREFIX + filename
    upload_success = upload_to_s3(filepath, WASABI_BUCKET_NAME, s3_file_key)

    if os.path.exists(filepath):
        os.remove(filepath)

    if not upload_success:
        return jsonify({"success": False, "message": "Gagal mengunggah gambar ke S3."}), 500

    print("-> Capture & Upload complete.")
    print("="*50 + "\n")
    return jsonify({
        "success": True, "s3_path": s3_file_key, "filename": filename
    }), 200

if __name__ == '__main__':
    if not os.path.exists(IMAGE_CAPTURE_DIR):
        os.makedirs(IMAGE_CAPTURE_DIR)
    print("Starting Raspberry Pi Flask server on 0.0.0.0:5001...")
    app.run(host='0.0.0.0', port=5001, debug=False, threaded=True)
