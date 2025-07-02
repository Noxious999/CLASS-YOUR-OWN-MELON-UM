# =================================================================
# SEL 1: Instal library ultralytics
# =================================================================
# Jalankan ini sekali saja per sesi.
!pip install ultralytics

# =================================================================
# SEL 2: Buat File Konfigurasi .yaml Secara Dinamis
# =================================================================
# Sel ini akan MEMBUAT file melon_dataset.yaml di dalam Colab.
# Ini penting untuk memastikan path-nya selalu benar.
import yaml
import os

# Cek apakah folder dataset sudah ada
dataset_base_path = '/content/dataset'
if not os.path.isdir(dataset_base_path):
    print("❌ ERROR: Folder '/content/dataset' tidak ditemukan.")
    print("   Pastikan Anda sudah mengunggah folder 'dataset' ke panel file di sebelah kiri.")
else:
    # Definisikan struktur data untuk file yaml
    yaml_content = {
        'train': os.path.join(dataset_base_path, 'images/train'),
        'val': os.path.join(dataset_base_path, 'images/valid'),
        'nc': 1,
        'names': ['melon']
    }

    # Tulis data ke file yaml di dalam lingkungan Colab
    yaml_file_path = '/content/melon_dataset.yaml'
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
# Jalankan sel ini untuk memastikan YOLO dapat menemukan gambar DAN label
# setelah Anda mengunggahnya.
print("\n--- Memulai Verifikasi Dataset ---")
try:
    with open(yaml_file_path, 'r') as f:
        data = yaml.safe_load(f)
    all_ok = True
    for split in ['train', 'val']:
        image_dir = data[split]
        label_dir = image_dir.replace('/images/', '/labels/')
        print(f"\n--- Memeriksa set: '{split}' ---")
        print(f"  Direktori Gambar: {image_dir}")
        print(f"  Direktori Label  : {label_dir}")

        if not os.path.isdir(image_dir) or not os.path.isdir(label_dir):
            print(f"  ❌ ERROR: Direktori gambar atau label tidak ditemukan. Pastikan struktur folder Anda benar (dataset/images/train, dataset/labels/train, dst.).")
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
                print(f"     Contoh label .txt yang hilang: {missing_labels_examples}")
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
from ultralytics import YOLO

#model = YOLO('yolov8n.pt')
#model = YOLO('yolov8s.pt')
#model = YOLO('yolov8m.pt')
#model = YOLO('yolov8l.pt')
model = YOLO('yolov8x.pt')

results = model.train(
   data=yaml_file_path,
   epochs=50,
   imgsz=640,
   batch=16,
   #name='yolov8n_melon_direct_upload'
   #name='yolov8s_melon_direct_upload'
   #name='yolov8m_melon_direct_upload'
   #name='yolov8l_melon_direct_upload'
   name='yolov8x_melon_direct_upload'
)

# =================================================================
# SEL 5 (Opsional): Kompres Hasil untuk Download
# =================================================================
import shutil
#output_folder_path = '/content/runs/detect/yolov8n_melon_direct_upload'
#output_folder_path = '/content/runs/detect/yolov8s_melon_direct_upload'
#output_folder_path = '/content/runs/detect/yolov8m_melon_direct_upload'
#output_folder_path = '/content/runs/detect/yolov8l_melon_direct_upload'
output_folder_path = '/content/runs/detect/yolov8x_melon_direct_upload'
#zip_destination = '/content/yolov8n_melon_direct_upload_results'
#zip_destination = '/content/yolov8s_melon_direct_upload_results'
#zip_destination = '/content/yolov8m_melon_direct_upload_results'
#zip_destination = '/content/yolov8l_melon_direct_upload_results'
zip_destination = '/content/yolov8x_melon_direct_upload_results'
shutil.make_archive(zip_destination, 'zip', output_folder_path)

print(f"\nTraining Selesai!")
print(f"Hasil training telah di-zip dan siap di-download dari panel file di: {zip_destination}.zip")
