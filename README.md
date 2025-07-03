# C.Y.O.M (Class Your Own Melon) - Sistem Klasifikasi Kematangan Melon

Selamat datang di repositori proyek **C.Y.O.M (Class Your Own Melon)**. Ini adalah sistem web *end-to-end* yang dibangun menggunakan Laravel untuk mengklasifikasikan kematangan buah melon berdasarkan analisis citra. Sistem ini mampu menerima input gambar dari unggahan manual pengguna maupun akuisisi langsung dari kamera Raspberry Pi.

Proyek ini memanfaatkan *library* Imagick untuk ekstraksi fitur citra, Rubix-ML untuk melatih model *machine learning* dengan arsitektur *Stacking Ensemble*, dan skrip Python dengan YOLOv8 untuk membantu deteksi objek.

![Contoh Tampilan Web Klasifikasi](https://i.imgur.com/Op2W5cr.png)

---

## Daftar Isi
1.  [Prasyarat](#1-prasyarat)
2.  [Instalasi & Konfigurasi Awal](#2-instalasi--konfigurasi-awal)
3.  [Persiapan Aset Eksternal (Sangat Penting)](#3-persiapan-aset-eksternal-sangat-penting)
    - [3.1. Konfigurasi Bucket Wasabi S3](#31-konfigurasi-bucket-wasabi-s3)
    - [3.2. Unduh Dataset Awal](#32-unduh-dataset-awal)
    - [3.3. Unduh Model YOLOv8](#33-unduh-model-yolov8)
4.  [Alur Kerja Machine Learning (Wajib Dijalankan Berurutan)](#4-alur-kerja-machine-learning-wajib-dijalankan-berurutan)
    - [4.1. Anotasi Dataset Manual](#41-anotasi-dataset-manual)
    - [4.2. Ekstraksi Fitur](#42-ekstraksi-fitur)
    - [4.3. Seleksi Fitur (Opsional)](#43-seleksi-fitur-opsional)
    - [4.4. Pelatihan Model Ensemble](#44-pelatihan-model-ensemble)
5.  [Pengujian Fungsionalitas Web](#5-pengujian-fungsionalitas-web)
    - [5.1. Web Klasifikasi Utama](#51-web-klasifikasi-utama)
    - [5.2. Web Evaluasi Model](#52-web-evaluasi-model)
6.  [(Opsional) Setup Klien Raspberry Pi](#6-opsional-setup-klien-raspberry-pi)

---

## 1. Prasyarat

Sebelum memulai, pastikan lingkungan pengembangan Anda telah memenuhi syarat berikut:
- **PHP 8.2** atau lebih tinggi
- **Composer**
- **Node.js & NPM**
- **Web Server Lokal** (disarankan menggunakan [Laragon](https://laragon.org/) untuk kemudahan konfigurasi Nginx & host virtual)
- **Akun Wasabi S3**: Diperlukan untuk penyimpanan semua aset (dataset, model, dll).
- **Akun Ngrok**: Diperlukan untuk membuat *tunnel* agar Raspberry Pi dapat berkomunikasi dengan server lokal Anda.

## 2. Instalasi & Konfigurasi Awal

Langkah-langkah ini untuk menyiapkan proyek Laravel di mesin lokal Anda.

1.  **Clone Repositori**
    ```bash
    git clone [https://github.com/username/repo-name.git](https://github.com/username/repo-name.git)
    cd repo-name
    ```

2.  **Instal Dependensi**
    Jalankan perintah berikut untuk menginstal semua dependensi PHP dan JavaScript.
    ```bash
    composer install
    npm install
    npm run build
    ```

3.  **Konfigurasi Lingkungan (.env)**
    - Salin file `.env.example` menjadi `.env`.
      ```bash
      cp .env.example .env
      ```
    - Buat *application key* baru.
      ```bash
      php artisan key:generate
      ```
    - Buka file `.env` dan sesuaikan variabel-variabel berikut:
      ```dotenv
      # URL Aplikasi (Ganti dengan URL Ngrok statis Anda nanti)
      APP_URL=[https://your-static-domain.ngrok-free.app](https://your-static-domain.ngrok-free.app)

      # Kredensial Wasabi S3 (Isi sesuai akun Anda)
      WASABI_ACCESS_KEY_ID=YOUR_WASABI_ACCESS_KEY
      WASABI_SECRET_ACCESS_KEY=YOUR_WASABI_SECRET_KEY
      WASABI_DEFAULT_REGION=ap-southeast-1 # atau region Anda
      WASABI_BUCKET=nama-bucket-anda
      WASABI_ENDPOINT=[https://s3.ap-southeast-1.wasabisys.com](https://s3.ap-southeast-1.wasabisys.com) # atau endpoint region Anda
      WASABI_URL=[https://s3.ap-southeast-1.wasabisys.com/nama-bucket-anda](https://s3.ap-southeast-1.wasabisys.com/nama-bucket-anda)

      # Path ke Python Executable (Sesuaikan dengan path di komputer Anda)
      # Contoh untuk Windows dengan Anaconda:
      PYTHON_EXECUTABLE_PATH="C:/Users/YourUser/anaconda3/python.exe"
      # Contoh untuk Linux/Mac:
      # PYTHON_EXECUTABLE_PATH=/usr/bin/python3

      # URL Server Flask di Raspberry Pi (Ganti dengan IP Pi di jaringan Anda)
      RASPBERRY_PI_URL_FLASK=[http://192.168.1.10:5001](http://192.168.1.10:5001)
      ```

## 3. Persiapan Aset Eksternal (Sangat Penting)

Proyek ini tidak akan berjalan tanpa aset-aset berikut.

### 3.1. Konfigurasi Bucket Wasabi S3

Anda harus membuat sebuah *bucket* di akun Wasabi Anda. Nama *bucket* ini harus sama dengan yang Anda masukkan di `WASABI_BUCKET` pada file `.env`. Setelah itu, buat struktur folder berikut di dalam *bucket* Anda:

```
nama-bucket-anda/
├── dataset/
│   ├── annotations/
│   ├── features/
│   ├── test/
│   ├── train/
│   └── valid/
├── internal_data/
├── models/
├── thumbnails/
│   ├── test/
│   ├── train/
│   └── valid/
└── uploads_temp/
```

### 3.2. Unduh Dataset Awal

*Dataset* gambar melon yang digunakan dalam penelitian ini terlalu besar untuk disertakan di GitHub.

- **[UNDUH DATASET AWAL DI SINI](<LINK_GOOGLE_DRIVE_ANDA_DI_SINI>)**

Setelah mengunduh dan mengekstrak file ZIP, unggah kontennya ke *bucket* Wasabi Anda sesuai dengan struktur berikut:
- Seluruh gambar dari folder `test` di ZIP diunggah ke `dataset/test/` di Wasabi.
- Seluruh gambar dari folder `train` di ZIP diunggah ke `dataset/train/` di Wasabi.
- Seluruh gambar dari folder `valid` di ZIP diunggah ke `dataset/valid/` di Wasabi.

### 3.3. Unduh Model YOLOv8

Sistem ini menggunakan model YOLOv8 yang telah dilatih secara kustom untuk membantu deteksi objek melon.

- **[UNDUH MODEL `best_yolov8x.pt` ATAU LAINNYA DI SINI](<https://drive.google.com/drive/folders/1ACJMSUR6U_fIA29GoPZ6zpn-Ql0nZ7DC?usp=sharing>)**

Setelah mengunduh, letakkan file `best_yolov8x.pt` atau .pt apa pun yang dipilih di dalam direktori proyek Laravel Anda pada path: `storage/app/models_yolo/`.

## 4. Alur Kerja Machine Learning (Wajib Dijalankan Berurutan)

Setelah semua persiapan selesai, Anda harus menjalankan alur kerja berikut secara berurutan melalui terminal di direktori proyek Anda.

### 4.1. Anotasi Dataset Manual

Ini adalah langkah paling krusial. Anda harus memberikan label dan *bounding box* pada setiap gambar di *dataset*.

![Contoh Tampilan Web Anotasi Manual](https://i.imgur.com/LpscTte.png)

1.  Jalankan server lokal Anda (misalnya melalui Laragon).
2.  Akses halaman anotasi melalui URL lokal Anda, contoh: `http://nama-proyek.test/annotate`.
3.  Di halaman ini, Anda akan melihat antrian gambar yang belum dianotasi.
4.  Untuk setiap gambar:
    - Klik tombol **"Estimasi BBox Otomatis"** untuk mendapatkan bantuan dari model YOLOv8.
    - Jika hasilnya kurang pas, Anda bisa **menggambar Bbox secara manual** dengan menahan dan menyeret kursor mouse pada gambar.
    - Anda dapat **memilih, memindahkan, mengubah ukuran, atau menghapus** Bbox yang ada.
    - Untuk setiap Bbox, **wajib memilih kelas kematangan** ("Matang" atau "Belum Matang").
    - Klik **"Simpan & Lanjutkan"**. Sistem akan otomatis memuat gambar berikutnya dalam antrian.
5.  Lanjutkan proses ini hingga seluruh gambar dalam antrian telah dianotasi.

### 4.2. Ekstraksi Fitur

Setelah semua anotasi selesai, jalankan perintah Artisan berikut untuk mengekstrak fitur numerik dari setiap anotasi.

```bash
php artisan dataset:extract-features --set=all
```
Perintah ini akan membaca file `_annotations.csv` yang dibuat pada langkah sebelumnya dan menghasilkan file `_features.csv` di dalam *bucket* `dataset/features/` di Wasabi.

### 4.3. Seleksi Fitur (Opsional)

Langkah ini bertujuan untuk mengidentifikasi fitur mana yang paling berpengaruh terhadap klasifikasi. Proyek ini sudah dikonfigurasi untuk menggunakan 20 fitur teratas. Namun, jika Anda ingin melihat hasilnya sendiri, jalankan:

```bash
php artisan train:select-features
```
Perintah ini akan menampilkan peringkat fitur di terminal Anda.

### 4.4. Pelatihan Model Ensemble

Ini adalah langkah terakhir dalam pipeline ML, di mana model klasifikasi utama dilatih.

```bash
php artisan train:melon-model --with-test
```
Perintah ini akan:
- Melatih *base model* (KNN dan Random Forest).
- Membuat *meta-features* dari hasil prediksi *base model*.
- Melatih *meta-learner* (Random Forest) untuk menciptakan model *ensemble* final.
- Menyimpan semua model (`.model`) dan metadatanya (`.json`) ke dalam *bucket* `models/` di Wasabi.
- Opsi `--with-test` memastikan model dievaluasi pada *set* data tes untuk mengukur performa generalisasinya.

## 5. Pengujian Fungsionalitas Web

Setelah model berhasil dilatih, Anda dapat mulai menggunakan aplikasi web.

### 5.1. Web Klasifikasi Utama

Akses halaman utama aplikasi (misalnya `http://nama-proyek.test/`).
- **Mode Unggah Manual**: Pilih gambar melon dari komputer Anda dan klik "Klasifikasi".
- **Mode Kamera Pi**: Jika Anda telah menyiapkan Raspberry Pi (lihat langkah 6), aktifkan *toggle* ke "Mode Kamera Pi". Klik "Mulai Preview" untuk melihat *stream* video, posisikan melon, lalu klik "Ambil Gambar & Klasifikasi".
- **Hasil**: Sistem akan menampilkan gambar asli, gambar hasil deteksi dengan *bounding box*, dan kartu hasil klasifikasi untuk setiap Bbox yang ditemukan.
- **Interaktivitas**: Anda dapat mengedit Bbox, menambah Bbox baru, atau menghapus Bbox, lalu klik "Klasifikasi Ulang" untuk mendapatkan prediksi baru berdasarkan perubahan Anda.
- **Feedback**: Berikan masukan apakah hasil klasifikasi sudah sesuai. Jika Anda memilih "Tidak, Perlu Koreksi", gambar tersebut akan otomatis ditambahkan ke antrian anotasi untuk perbaikan di masa depan.

### 5.2. Web Evaluasi Model

Akses halaman evaluasi (misalnya `http://nama-proyek.test/evaluate`). Di sini Anda dapat melihat:
- Statistik detail mengenai jumlah dataset, anotasi, dan fitur.
- Performa setiap model yang dilatih, termasuk *Confusion Matrix*, Akurasi, Presisi, *Recall*, dan F1-Score.
- Visualisasi *Learning Curve* untuk menganalisis potensi *overfitting* atau *underfitting*.

## 6. (Opsional) Setup Klien Raspberry Pi

Jika Anda ingin menggunakan fitur kamera jarak jauh:
1.  Salin file `raspberry_pi_server.py` dari direktori `scripts/` proyek ini ke Raspberry Pi Anda.
2.  Instal dependensi yang diperlukan di Raspberry Pi:
    ```bash
    pip3 install Flask picamera2 boto3
    ```
3.  Pastikan Raspberry Pi terhubung ke jaringan WiFi yang sama dengan komputer server Laravel Anda.
4.  Jalankan server Flask di Raspberry Pi:
    ```bash
    python3 raspberry_pi_server.py
    ```
5.  Pastikan variabel `RASPBERRY_PI_URL_FLASK` di file `.env` Laravel Anda sudah menunjuk ke alamat IP dan port yang benar dari Raspberry Pi Anda.
