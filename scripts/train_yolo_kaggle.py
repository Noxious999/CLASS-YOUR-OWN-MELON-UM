# =================================================================
# SEL 1: Instal library ultralytics
# =================================================================
# Jalankan ini sekali saja per sesi.
# Pastikan internet sudah aktif di pengaturan notebook Kaggle.
!pip install ultralytics

# =================================================================
# SEL 2: Buat File Konfigurasi .yaml Secara Dinamis
# =================================================================
# Sel ini akan MEMBUAT file melon_dataset.yaml di dalam direktori kerja Kaggle.
import yaml
import os

# --- [PERUBAHAN KAGGLE] ---
# GANTI '<nama-dataset-anda-di-kaggle>' dengan nama folder dataset Anda di Kaggle.
# Contoh: Jika dataset Anda bernama 'melon-dataset', path-nya adalah '/kaggle/input/melon-dataset/dataset'
# Pastikan Anda menyertakan '/dataset' di akhir jika struktur folder Anda adalah 'nama-dataset/dataset/images'.
# Jika struktur Anda langsung 'nama-dataset/images', hapus '/dataset' dari path.
dataset_name_kaggle = 'dataset'

# Path lengkap menuju folder yang berisi 'images' dan 'labels' adalah /kaggle/input/dataset/dataset
dataset_base_path = f'/kaggle/input/{dataset_name_kaggle}/dataset'

# Direktori kerja di Kaggle, semua file yang dibuat akan disimpan di sini.
kaggle_working_dir = '/kaggle/working'
# --- [AKHIR PERUBAHAN KAGGLE] ---

# Cek apakah folder dataset sudah ada
if not os.path.isdir(dataset_base_path):
    print(f"❌ ERROR: Folder '{dataset_base_path}' tidak ditemukan.")
    print("   Pastikan Anda sudah mengganti '<nama-dataset-anda-di-kaggle>' dengan benar.")
    print("   Dan pastikan struktur folder di dalam dataset Anda sudah sesuai.")
else:
    # Definisikan struktur data untuk file yaml menggunakan path Kaggle
    yaml_content = {
        'train': os.path.join(dataset_base_path, 'images/train'),
        'val': os.path.join(dataset_base_path, 'images/valid'),
        'nc': 1,
        'names': ['melon']
    }

    # --- [PERUBAHAN KAGGLE] ---
    # Simpan file .yaml di direktori kerja Kaggle (/kaggle/working/)
    yaml_file_path = os.path.join(kaggle_working_dir, 'melon_dataset.yaml')
    # --- [AKHIR PERUBAHAN KAGGLE] ---

    with open(yaml_file_path, 'w') as f:
        yaml.dump(yaml_content, f, sort_keys=False)

    print(f"✅ File '{yaml_file_path}' berhasil dibuat.")
    print("--- Isi File ---")
    !cat {yaml_file_path}
    print("----------------")
    print("\n✅ Anda sekarang bisa melanjutkan ke sel Verifikasi.")

# =================================================================
# SEL 3: (SANGAT PENTING) Verifikasi Dataset Anda
# =================================================================
# Jalankan sel ini untuk memastikan YOLO dapat menemukan gambar DAN label.
# Tidak ada perubahan di sel ini, karena sudah menggunakan variabel 'yaml_file_path'
# yang path-nya sudah disesuaikan untuk Kaggle di sel sebelumnya.
print("\n--- Memulai Verifikasi Dataset ---")
try:
    with open(yaml_file_path, 'r') as f:
        data = yaml.safe_load(f)
    all_ok = True
    for split in ['train', 'val']:
        image_dir = data[split]
        # Logika ini cerdas dan tetap berfungsi karena otomatis mengganti path dari .yaml
        label_dir = image_dir.replace('/images/', '/labels/')
        print(f"\n--- Memeriksa set: '{split}' ---")
        print(f"  Direktori Gambar: {image_dir}")
        print(f"  Direktori Label  : {label_dir}")

        if not os.path.isdir(image_dir) or not os.path.isdir(label_dir):
            print(f"  ❌ ERROR: Direktori gambar atau label tidak ditemukan. Pastikan struktur folder Anda benar.")
            all_ok = False
            continue

        image_files = [f for f in os.listdir(image_dir) if f.lower().endswith(('.png', '.jpg', '.jpeg'))]
        label_files = os.listdir(label_dir)

        found_labels_count = 0
        missing_labels_examples = []
        for img_file in image_files:
            expected_label_file = os.path.splitext(img_file)[0] + '.txt'
            if expected_label_file in label_files:
                found_labels_count += 1
            else:
                if len(missing_labels_examples) < 5:
                    missing_labels_examples.append(expected_label_file)

        print(f"  Total Gambar: {len(image_files)}")
        print(f"  Total Label (.txt) Ditemukan: {found_labels_count}")

        if len(image_files) != found_labels_count:
            print(f"  ❌ MASALAH: Jumlah gambar dan label tidak cocok!")
            if missing_labels_examples:
                print(f"      Contoh label .txt yang hilang: {missing_labels_examples}")
            all_ok = False
        elif len(image_files) == 0:
            print("  INFO: Tidak ada gambar ditemukan.")
        else:
            print("  ✅ SEMPURNA: Semua gambar memiliki file label yang sesuai.")

    if not all_ok:
        print("\n\n>>> PERINGATAN: Verifikasi GAGAL. Jangan lanjutkan ke training. <<<")
    else:
        print("\n\n>>> Dataset terlihat baik. Anda siap untuk training. <<<")
except Exception as e:
    print(f"Terjadi error saat memverifikasi: {e}")


# =================================================================
# SEL 4: Jalankan Training
# =================================================================
# Jalankan sel ini HANYA JIKA verifikasi di atas sudah SEMPURNA.
# Hasil training akan otomatis disimpan di '/kaggle/working/runs/'.
from ultralytics import YOLO

#model = YOLO('yolov8n.pt')
#model = YOLO('yolov8s.pt')
#model = YOLO('yolov8m.pt')
#model = YOLO('yolov8l.pt')
model = YOLO('yolov8x.pt')

# Nama folder untuk menyimpan hasil training
#name_yolov8n = 'yolov8n_melon_kaggle'
#name_yolov8s = 'yolov8s_melon_kaggle'
#name_yolov8m = 'yolov8m_melon_kaggle'
#name_yolov8l = 'yolov8l_melon_kaggle'
name_yolov8x = 'yolov8x_melon_kaggle'

results = model.train(
    data=yaml_file_path,
    epochs=50,
    imgsz=640,
    batch=16, # Sesuaikan batch size jika VRAM GPU tidak cukup
    #name=name_yolov8n
    #name=name_yolov8s
    #name=name_yolov8m
    #name=name_yolov8l
    name=name_yolov8x
)

# =================================================================
# SEL 5 (Opsional): Kompres Hasil untuk Download
# =================================================================
import shutil

# --- [PERUBAHAN KAGGLE] ---
# Menggunakan variabel dari SEL 2 dan SEL 4 untuk path yang dinamis.
# Path hasil training di Kaggle akan berada di /kaggle/working/runs/detect/...
output_folder_path = os.path.join(kaggle_working_dir, 'runs/detect', name_yolov8x)
zip_destination = os.path.join(kaggle_working_dir, name_yolov8x + '_results')
# --- [AKHIR PERUBAHAN KAGGLE] ---

shutil.make_archive(zip_destination, 'zip', output_folder_path)

print(f"\nTraining Selesai!")
print(f"Hasil training telah di-zip dan siap di-download.")
print(f"Lihat di panel 'Data' di sebelah kanan, di bawah bagian 'Output'. File Anda bernama: {os.path.basename(zip_destination)}.zip")
