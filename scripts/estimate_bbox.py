# scripts/estimate_bbox.py (v5 - Content-Aware Cropping)
import os
import sys
import json
import traceback
import cv2
import numpy as np

def eprint(*args, **kwargs):
    print(*args, file=sys.stderr, **kwargs)

def detect_melons_with_custom_model(project_root_path, image_path_raw):
    from ultralytics import YOLO

    image_path = os.path.normpath(image_path_raw)
    project_root = os.path.normpath(project_root_path)
    # Ganti 'best_yolov8x.pt' dengan model 'm' Anda jika sudah diganti
    model_path = os.path.join(project_root, 'storage', 'app', 'models_yolo', 'best_yolov8x.pt')

    if not os.path.exists(model_path):
        return {"success": False, "message": f"Model tidak ditemukan: {model_path}", "bboxes": []}
    if not os.path.exists(image_path):
        return {"success": False, "message": f"Gambar tidak ditemukan: {image_path}", "bboxes": []}

    img = cv2.imread(image_path)
    if img is None:
        return {"success": False, "message": f"Gagal membaca gambar: {image_path}", "bboxes": []}

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    _, thresh = cv2.threshold(gray, 5, 255, cv2.THRESH_BINARY)
    contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    crop_coords = (0, 0, img.shape[1], img.shape[0])
    img_to_detect = img

    if contours:
        main_contour = max(contours, key=cv2.contourArea)
        x, y, w, h = cv2.boundingRect(main_contour)

        # Hanya crop jika area konten lebih dari 50% area gambar, untuk menghindari crop kecil karena noise
        if (w * h) > (img.shape[0] * img.shape[1] * 0.5):
            crop_coords = (x, y, w, h)
            img_to_detect = img[y:y+h, x:x+w]
            eprint(f"DEBUG: Image cropped to content area: x={x}, y={y}, w={w}, h={h}")
        else:
            eprint(f"DEBUG: Content area too small, using full image.")
    else:
        eprint("DEBUG: No contours found, using full image.")

    model = YOLO(model_path)
    results = model(img_to_detect, verbose=False)

    detected_boxes = []
    crop_x_offset, crop_y_offset = crop_coords[0], crop_coords[1]

    for result in results:
        for box in result.boxes:
            if float(box.conf[0]) > 0.20:
                xywh = box.xywh[0]
                x_center, y_center, w_box, h_box = map(int, xywh)

                x_abs = (x_center - w_box // 2) + crop_x_offset
                y_abs = (y_center - h_box // 2) + crop_y_offset

                detected_boxes.append({
                    "x": x_abs, "y": y_abs, "w": w_box, "h": h_box,
                    "confidence": float(box.conf[0])
                })

    return {"success": True, "message": f"{len(detected_boxes)} melon terdeteksi.", "bboxes": detected_boxes}

if __name__ == "__main__":
    try:
        if len(sys.argv) != 3:
            error_result = {"success": False, "message": "Kesalahan Argumen", "bboxes": []}
            print(json.dumps(error_result))
            sys.exit(1)
        root_path_arg, image_file_path_arg = sys.argv[1], sys.argv[2]
        result_json = detect_melons_with_custom_model(root_path_arg, image_file_path_arg)
        print(json.dumps(result_json))
    except Exception as e:
        eprint(f"PYTHON CRITICAL ERROR: {str(e)}\n{traceback.format_exc()}")
        critical_error_result = {"success": False, "message": f"Fatal script error: {str(e)}", "bboxes": [], "traceback": traceback.format_exc()}
        print(json.dumps(critical_error_result))
    finally:
        sys.stdout.flush()
        sys.stderr.flush()
