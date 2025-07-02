<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klasifikasi Kematangan Melon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/css/responsive.css'])
</head>

<body class="bg-light">
    <div class="dashboard-container container-xl my-4">
        <header class="page-header mb-4">
            <h1 class="page-title"><i class="fas fa-search-plus"></i>Klasifikasi Kematangan Melon</h1>
            <div class="page-actions d-flex align-items-center">
                <div class="form-check form-switch me-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="mode-toggle">
                    <label class="form-check-label" for="mode-toggle" id="mode-label">Mode Unggah Manual</label>
                </div>
                <div class="page-actions">
                    {{-- PERUBAHAN DI SINI --}}
                    <a href="{{ route('annotate.index') }}" class="btn btn-outline-secondary btn-sm nav-loader-link"
                        data-page-name="Anotasi Manual">
                        <i class="fas fa-pencil-alt"></i> Anotasi Manual
                    </a>
                    {{-- PERUBAHAN DI SINI --}}
                    <a href="{{ route('evaluate.index') }}" class="btn btn-outline-primary btn-sm nav-loader-link"
                        data-page-name="Dashboard Evaluasi">
                        <i class="fas fa-chart-line"></i> Dashboard Evaluasi
                    </a>
                </div>
                <form id="clear-cache-form" action="{{ route('app.clear_cache') }}" method="POST" class="ms-2">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Bersihkan Cache Server">
                        <i class="fas fa-broom"></i> Hapus Cache
                    </button>
                </form>
            </div>
        </header>

        {{-- Container notifikasi yang dikontrol JS --}}
        <div id="annotation-notification-area"
            class="alert alert-info align-items-center {{ !isset($pendingAnnotationCount) || $pendingAnnotationCount === 0 ? 'd-none' : 'd-flex' }}"
            role="alert">
            <i class="fas fa-info-circle flex-shrink-0 me-2"></i>
            <div>
                Anda memiliki <strong id="pending-annotation-count">{{ $pendingAnnotationCount ?? 0 }}</strong> gambar
                yang menunggu untuk dianotasi.
                <a href="{{ route('annotate.index') }}" class="alert-link nav-loader-link"
                    data-page-name="Anotasi">Mulai Anotasi Sekarang</a>.
            </div>
        </div>
        {{-- ▼▼▼ TAMBAHKAN BLOK INI ▼▼▼ --}}
        @if (session('status'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-times-circle me-2"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Mode Unggah Manual --}}
        <div id="manual-upload-mode">
            <div class="card shadow-sm mb-5 prediction-input-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Unggah Gambar untuk Klasifikasi</h5>
                </div>
                <div class="card-body">
                    <form id="upload-form">
                        <div class="input-group">
                            <input type="file" name="imageFile" id="image-input" class="form-control form-control-lg"
                                required accept="image/jpeg,image/png,image/jpg,image/webp">
                            <button type="submit" id="classify-btn" class="btn btn-success btn-lg"><i
                                    class="fas fa-cogs me-1"></i> Klasifikasi</button>
                        </div>
                        <small class="form-text text-muted">Maksimal 5MB. Format: JPG, PNG, WEBP.</small>
                    </form>
                </div>
            </div>
        </div>

        {{-- Mode Kamera Raspberry Pi --}}
        <div id="pi-camera-mode" class="d-none">
            <div class="card shadow-sm mb-5 text-center">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-camera-retro me-2"></i>Kontrol Kamera Raspberry Pi</h5>
                </div>
                <div class="card-body">
                    <p>Tekan "Mulai Preview" untuk memunculkan video streaming dari kamera secara real-time, setelah
                        posisi melon cocok, ambil gambar dengan menekan tombol "Ambil gambar & Klasifikasi" untuk
                        klasifikasi.</p>

                    {{-- ▼▼▼ TAMBAHKAN BLOK INI ▼▼▼ --}}
                    <div class="mb-3">
                        <button id="toggle-stream-btn" class="btn btn-lg btn-info">
                            <i class="fas fa-eye"></i> Mulai Preview
                        </button>
                    </div>

                    <div class="video-stream-container mb-3 d-none bg-dark rounded">
                        {{-- Elemen img ini akan menampilkan video stream --}}
                        <img id="pi-video-stream" src="" class="img-fluid" alt="Pi Camera Stream">
                    </div>
                    {{-- ▲▲▲ AKHIR BLOK TAMBAHAN ▲▲▲ --}}

                    {{-- Tombol ini tetap ada untuk final capture --}}
                    <button id="trigger-pi-btn" class="btn btn-lg btn-danger">
                        <i class="fas fa-camera"></i> Ambil Gambar & Klasifikasi
                    </button>
                </div>
            </div>
        </div>

        {{-- [PERUBAHAN] Container untuk semua hasil, termasuk pesan error --}}
        <div id="result-section" class="d-none">
            {{-- Pesan error sekarang akan ditampilkan di sini, BUKAN di dalam card --}}
            <div id="pipeline-error-display" class="alert alert-danger d-none text-center"></div>

            {{-- Card utama yang sekarang hanya berisi gambar dan kontrol --}}
            <div id="result-main-card" class="card shadow-lg result-summary-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-poll me-2"></i>Hasil Analisis: <span
                            id="result-filename-display" class="fw-normal"></span></h5>
                </div>
                <div class="card-body p-lg-4">
                    {{-- Baris untuk Gambar --}}
                    <div class="row g-4">
                        {{-- Kolom untuk "Gambar Asli" --}}
                        <div class="col-lg-6">
                            <h6 class="text-center text-muted mb-2">Gambar Asli</h6>
                            {{-- Terapkan kelas CSS baru di sini --}}
                            <div class="dynamic-image-card">
                                <img id="original-image-display">
                            </div>
                        </div>

                        {{-- Kolom untuk "Hasil Deteksi" --}}
                        <div class="col-lg-6">
                            <h6 class="text-center text-muted mb-2">Hasil Deteksi</h6>
                            {{-- Terapkan kelas CSS baru yang SAMA di sini --}}
                            <div class="dynamic-image-card">
                                <img id="result-image-display">
                                <div id="bbox-overlay-interactive"
                                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> {{-- Akhir dari result-main-card --}}

            {{-- [PERUBAHAN STRUKTUR] Kontrol, Hasil, dan Feedback dipindah ke luar card utama untuk layout yang lebih baik --}}
            <div class="mt-4">
                {{-- 1. Kontrol BBox (Rata Tengah) --}}
                <div id="manual-bbox-controls"
                    class="d-flex justify-content-center align-items-center flex-wrap gap-2 mb-4 d-none">
                    <div class="btn-group" role="group" aria-label="Edit BBox Tools">
                        <button type="button" id="edit-bbox-btn" class="btn btn-outline-warning"
                            title="Aktifkan Mode Edit Bbox">
                            <i class="fas fa-edit"></i> <span class="d-none d-md-inline">Ubah</span>
                        </button>
                        <button type="button" id="add-bbox-btn" class="btn btn-outline-primary"
                            title="Tambah Bbox Baru">
                            <i class="fas fa-plus-square"></i> <span class="d-none d-md-inline">Tambah</span>
                        </button>
                        {{-- TOMBOL BARU DITAMBAHKAN DI SINI --}}
                        <button type="button" id="delete-selected-bbox-btn" class="btn btn-outline-danger"
                            title="Hapus Bbox Terpilih" disabled>
                            <i class="fas fa-trash-alt"></i>
                        </button>
                        <button type="button" id="clear-all-bbox-btn" class="btn btn-outline-secondary"
                            title="Hapus Semua Bbox">
                            <i class="fas fa-times-circle"></i>
                        </button>
                        <button type="button" id="cancel-draw-btn" class="btn btn-danger d-none"
                            title="Batalkan Aksi Saat Ini">
                            <i class="fas fa-times"></i> <span class="d-none d-md-inline">Batal</span>
                        </button>
                    </div>
                    <div class="btn-group" role="group" aria-label="Classification Actions">
                        <button type="button" id="re-estimate-btn" class="btn btn-info"
                            title="Jalankan Ulang Deteksi Otomatis">
                            <i class="fas fa-magic"></i> Deteksi Ulang
                        </button>
                        <button type="button" id="reclassify-btn" class="btn btn-success"
                            title="Klasifikasi Ulang dengan Bbox Saat Ini">
                            <i class="fas fa-cogs"></i> <span class="d-none d-md-inline">Klasifikasi Ulang</span>
                        </button>
                    </div>
                </div>

                <p id="bbox-mode-instruction" class="text-muted text-center small mt-2 d-none"></p>

                {{-- 2. Area Hasil Klasifikasi (Grid Responsif) --}}
                <div id="classification-result-area" class="d-flex flex-wrap justify-content-center gap-3 mb-4">
                    {{-- Kartu hasil akan di-generate oleh JS di sini --}}
                </div>

                {{-- 3. Sesi Feedback (Sekarang dalam card sendiri) --}}
                <div id="feedback-section" class="card shadow-sm bg-light-subtle d-none col-lg-8 mx-auto">
                    <div class="card-body">
                        <div id="feedback-initial-prompt" class="text-center">
                            <p class="mb-2 small text-muted">Apakah hasil klasifikasi untuk setiap melon di atas sudah
                                sesuai?</p>
                            <button class="btn btn-outline-success btn-sm" data-feedback="correct"><i
                                    class="fas fa-check me-1"></i> Ya, Sesuai</button>
                            <button class="btn btn-outline-danger btn-sm" data-feedback="incorrect"><i
                                    class="fas fa-times me-1"></i> Tidak, Perlu Koreksi</button>
                        </div>
                        <div id="feedback-given-state" class="text-center d-none">
                            <p class="mb-2 small text-info">
                                <i class="fas fa-info-circle me-1"></i> Anda sudah memberikan feedback untuk gambar
                                ini:
                                {{-- Elemen baru untuk menampilkan feedback sebelumnya --}}
                                <strong id="previous-feedback-display" class="text-dark"></strong>.
                            </p>
                            <button class="btn btn-outline-secondary btn-sm" id="delete-feedback-btn">
                                <i class="fas fa-undo me-1"></i> Hapus Feedback
                            </button>
                        </div>
                        <div id="feedback-correction-options" class="text-center d-none"></div>
                        <div id="feedback-result-message" class="small text-center mt-2"></div>
                    </div>
                </div>
            </div>
        </div> {{-- Akhir dari result-section --}}

        {{-- [PERUBAHAN] TEMPLATE KARTU HASIL YANG BARU --}}
        <template id="multi-result-card-template">
            <div class="card result-card-item shadow-sm" style="flex: 1 1 300px; max-width: 400px;">
                <div class="card-header bg-primary text-white d-flex align-items-center">
                    <i class="fas fa-vector-square me-2"></i>
                    <h6 class="mb-0">Melon #<span class="bbox-number">1</span></h6>
                </div>
                <div class="card-body p-3">
                    <div class="score-item mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="score-label small fw-medium">Matang</span>
                            <span class="score-value-ripe small fw-bold text-success">0%</span>
                        </div>
                        <div class="progress" style="height: 1rem;" role="progressbar" aria-valuenow="0"
                            aria-valuemin="0" aria-valuemax="100">
                            <div class="score-bar-ripe progress-bar bg-success" style="width: 0%;"></div>
                        </div>
                    </div>
                    <div class="score-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="score-label small fw-medium">Belum Matang</span>
                            <span class="score-value-unripe small fw-bold text-warning-emphasis">0%</span>
                        </div>
                        <div class="progress" style="height: 1rem;" role="progressbar" aria-valuenow="0"
                            aria-valuemin="0" aria-valuemax="100">
                            <div class="score-bar-unripe progress-bar bg-warning" style="width: 0%;"></div>
                        </div>
                    </div>
                    <div class="alert alert-danger p-2 small mt-2 d-none result-error-message"></div>
                </div>
            </div>
        </template>

        <template id="overall-result-card-template">
            <div class="card result-card-item shadow-lg border-primary" style="flex-basis: 100%; max-width: none;">
                <div
                    class="card-header bg-primary-subtle text-primary-emphasis d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Hasil Rata-rata Keseluruhan</h5>
                    <span class="badge bg-primary rounded-pill fs-6" id="overall-verdict"></span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3 text-center">
                        <div class="col-md-6">
                            <div class="score-item">
                                <h6 class="score-label small fw-medium text-muted">RATA-RATA MATANG (RIPE)</h6>
                                <p class="h4 fw-bold text-success mb-0" id="overall-ripe-score">0%</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="score-item">
                                <h6 class="score-label small fw-medium text-muted">RATA-RATA BELUM MATANG (UNRIPE)</h6>
                                <p class="h4 fw-bold text-warning-emphasis mb-0" id="overall-unripe-score">0%</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        {{-- Template jika tidak ada melon terdeteksi (tetap sama) --}}
        <template id="result-template-non-melon">
            <div class="text-center p-4">
                <i class="fas fa-search-minus fa-3x text-info mb-3"></i>
                <h4 class="mb-1">Deteksi Otomatis Tidak Menemukan Objek</h4>
                <p class="text-muted">
                    Model tidak dapat menemukan melon secara otomatis. Anda dapat mencoba salah satu dari dua cara
                    berikut:
                </p>
                <div class="mt-3">
                    <strong class="d-block">1. Gambar Bounding Box manual</strong> menggunakan tombol <kbd><i
                            class="fas fa-plus-square"></i> Tambah Bbox</kbd> di atas.
                    <strong class="d-block mt-2">2. Coba lagi deteksi otomatis</strong> dengan menekan tombol <kbd><i
                            class="fas fa-magic"></i> Deteksi Ulang</kbd>.
                </div>
            </div>
        </template>

        <div id="overlay" class="overlay-container">
            <div>
                <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div>
                <span class="ms-3 fs-5 text-primary" id="overlay-text"></span>
            </div>
        </div>

        <script>
            window.uploadImageUrl = "{{ route('predict.upload_temp') }}";
            window.classifyUrl = "{{ route('predict.from_upload') }}";
            window.feedbackUrl = "{{ route('feedback.submit') }}";
            window.triggerPiUrl = "{{ route('api.trigger_pi_camera_stream') }}";
            window.piStreamProxyUrl = "{{ route('stream.pi_video_proxy') }}";
            window.csrfToken = "{{ csrf_token() }}";
            window.deleteFeedbackUrl = "{{ route('feedback.delete') }}"; // <-- TAMBAHKAN INI
        </script>
    </div>
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="feedback-toast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-success text-white">
                <i class="fas fa-check-circle rounded me-2"></i>
                <strong class="me-auto">Sukses</strong>
                <small>Baru saja</small>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"
                    aria-label="Close"></button>
            </div>
            <div class="toast-body">
            </div>
        </div>
    </div>
</body>

</html>
