import pandas as pd
import os
import sys

# --- PENJELASAN BAGIAN INI ADA DI BAWAH ---

# Menentukan path dasar dari root proyek Laravel.
# Skrip ini diasumsikan berada di dalam folder /scripts
# os.path.abspath(__file__) -> /path/ke/proyek/laravel/scripts/prepare_yolo_dataset.py
# os.path.dirname(...) -> /path/ke/proyek/laravel/scripts
# os.path.dirname(...) -> /path/ke/proyek/laravel
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Konfigurasi direktori input dan output relatif terhadap root proyek
ANNOTATIONS_DIR = os.path.join(BASE_DIR, 'storage', 'app', 'dataset', 'split', 'annotations')
YOLO_OUTPUT_DIR = os.path.join(BASE_DIR, 'storage', 'app', 'dataset', 'yolo')

# Pemetaan nama kelas ke ID integer untuk YOLO.
# Karena Anda hanya butuh 'melon', kita set sebagai kelas 0.
# Jika nanti ada kelas lain, bisa ditambahkan di sini. Contoh: 'semangka': 1
CLASS_MAP = {
    'melon': 0
}

def process_annotations(csv_filename):
    """
    Fungsi untuk membaca file CSV, memprosesnya, dan membuat file .txt
    sesuai format YOLO.
    """
    csv_path = os.path.join(ANNOTATIONS_DIR, csv_filename)

    # Cek apakah file CSV ada
    if not os.path.exists(csv_path):
        print(f"Peringatan: File {csv_filename} tidak ditemukan di {ANNOTATIONS_DIR}. Dilewati.")
        return

    print(f"Memproses file: {csv_filename}...")

    # Baca file CSV menggunakan pandas
    try:
        df = pd.read_csv(csv_path)
    except Exception as e:
        print(f"Error saat membaca {csv_path}: {e}")
        return

    # 1. Filter data: hanya ambil baris dengan detection_class 'melon'
    df_filtered = df[df['detection_class'].isin(CLASS_MAP.keys())].copy()

    if df_filtered.empty:
        print(f"Tidak ada deteksi 'melon' yang ditemukan di {csv_filename}.")
        return

    # Tambahkan kolom class_id berdasarkan CLASS_MAP
    df_filtered['class_id'] = df_filtered['detection_class'].map(CLASS_MAP)

    # 2. Kelompokkan baris berdasarkan nama file gambar
    # Ini penting agar semua deteksi (bounding box) untuk satu gambar
    # ditulis ke dalam satu file .txt yang sama.
    grouped = df_filtered.groupby('filename')

    # Counter untuk file yang berhasil dibuat
    files_created_count = 0

    for filename, group in grouped:
        # 3. Tentukan direktori output (train/valid) berdasarkan kolom 'set'
        # Ambil nilai 'set' dari baris pertama grup (semuanya sama untuk file yg sama)
        dataset_type = group['set'].iloc[0]

        # Abaikan jika set adalah 'test' atau tipe lain yang tidak diinginkan
        if dataset_type not in ['train', 'valid']:
            continue

        # Tentukan path folder output (contoh: storage/app/dataset/yolo/train)
        output_folder = os.path.join(YOLO_OUTPUT_DIR, dataset_type)

        # 4. Buat nama file .txt dari nama file gambar
        # Mengambil nama asli file dari path (misal: 'test/gambar.jpg' -> 'gambar.jpg')
        base_img_name = os.path.basename(filename)
        # Mengganti ekstensi file (misal: '.jpg') menjadi '.txt'
        txt_filename = os.path.splitext(base_img_name)[0] + '.txt'

        output_filepath = os.path.join(output_folder, txt_filename)

        # 5. Tulis data ke file .txt
        try:
            with open(output_filepath, 'w') as f:
                for _, row in group.iterrows():
                    # Ambil data yang diperlukan
                    class_id = int(row['class_id'])
                    bbox_cx = row['bbox_cx']
                    bbox_cy = row['bbox_cy']
                    bbox_w = row['bbox_w']
                    bbox_h = row['bbox_h']

                    # Tulis dalam format YOLO: class_id cx cy w h
                    f.write(f"{class_id} {bbox_cx} {bbox_cy} {bbox_w} {bbox_h}\n")
            files_created_count += 1
        except Exception as e:
            print(f"Gagal menulis file {output_filepath}. Error: {e}")

    print(f"Selesai memproses {csv_filename}. Total {files_created_count} file .txt dibuat/diperbarui.")


if __name__ == "__main__":
    # Install dependensi jika belum ada
    try:
        import pandas as pd
    except ImportError:
        print("Modul 'pandas' tidak ditemukan. Mencoba menginstall...")
        import subprocess
        subprocess.check_call([sys.executable, "-m", "pip", "install", "pandas"])
        print("'pandas' berhasil diinstall.")

    # Membuat direktori output jika belum ada
    try:
        os.makedirs(os.path.join(YOLO_OUTPUT_DIR, 'train'), exist_ok=True)
        os.makedirs(os.path.join(YOLO_OUTPUT_DIR, 'valid'), exist_ok=True)
        print("Direktori output 'train' dan 'valid' telah disiapkan.")
    except Exception as e:
        print(f"Gagal membuat direktori output. Error: {e}")
        sys.exit(1) # Keluar dari skrip jika tidak bisa buat folder

    # Daftar file CSV yang akan diproses
    files_to_process = [
        'train_annotations.csv',
        'valid_annotations.csv',
        'test_annotations.csv' # Kita tetap baca file ini, tapi logikanya akan skip set 'test'
    ]

    for file in files_to_process:
        process_annotations(file)

    print("\nKonversi Selesai! File anotasi YOLO .txt telah dibuat di:")
    print(f"- {os.path.join(YOLO_OUTPUT_DIR, 'train')}")
    print(f"- {os.path.join(YOLO_OUTPUT_DIR, 'valid')}")
