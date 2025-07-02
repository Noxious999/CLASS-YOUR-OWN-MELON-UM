<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Alat Anotasi Gambar Melon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/css/annotation.css', 'resources/js/app.js', 'resources/js/annotation.js', 'resources/css/responsive.css'])

    @if (isset($allUnannotatedImagesJson))
        <script id="all-unannotated-images-data" type="application/json">{!! $allUnannotatedImagesJson !!}</script>
    @endif
</head>

<body class="bg-light">
    {{-- [PERUBAHAN] Tambahkan dua data attribute di bawah ini --}}
    <div class="container-fluid" id="annotation-page-container"
        data-estimate-bbox-endpoint="{{ route('annotate.estimate_bbox') }}"
        data-upload-dataset-url="{{ route('dataset.upload_image') }}"
        data-clear-queue-cache-url="{{ route('annotate.clear_queue_caches') }}"
        data-delete-image-url="{{ route('dataset.image.delete') }}"
        data-get-urls-endpoint="{{ route('annotate.get_urls') }}"
        data-batch-delete-url="{{ route('dataset.images.batch-delete') }}"
        data-queue-status-url="{{ route('annotate.queue_status') }}"
        data-trigger-thumbnails-url="{{ route('admin.trigger_page_thumbnails') }}">

        <h1>ANOTASI MANUAL</h1>
        <div id="notification-area" class="mb-3"></div>
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
        <div id="status-indicator" class="alert alert-info">Memuat...</div>

        {{-- Wrapper untuk Tampilan Anotasi Utama --}}
        <div id="annotation-ui-wrapper" class="hidden">
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-pencil-alt me-2"></i>Area Anotasi</h5>
                    <div id="gallery-actions">
                        {{-- PERUBAHAN DI SINI --}}
                        <a href="{{ route('melon.index') }}" class="btn btn-sm btn-primary nav-loader-link"
                            data-page-name="Klasifikasi"><i class="fas fa-arrow-left me-1"></i> Lakukan Klasifikasi</a>

                        <button id="upload-new-dataset-btn-main" class="btn btn-sm btn-outline-info ms-2"><i
                                class="fas fa-upload me-1"></i> Unggah Baru</button>
                        <button id="refresh-gallery-btn-main" class="btn btn-sm btn-outline-secondary ms-2"><i
                                class="fas fa-sync-alt me-1"></i> Segarkan</button>
                        <button id="select-mode-btn" class="btn btn-sm btn-outline-primary ms-2"><i
                                class="fas fa-check-square me-1"></i> Mode Pilih</button>

                        {{-- PERUBAHAN DI SINI --}}
                        <a href="{{ route('evaluate.index') }}" class="btn btn-sm btn-primary nav-loader-link"
                            data-page-name="Evaluasi"><i class="fas fa-arrow-right me-1"></i> Lakukan Evaluasi</a>
                    </div>
                    <div id="batch-delete-controls" class="hidden">
                        <button id="delete-selected-btn" class="btn btn-sm btn-danger" disabled><i
                                class="fas fa-trash-alt me-1"></i> Hapus 0 Gambar</button>
                        <button id="cancel-select-mode-btn" class="btn btn-sm btn-secondary ms-2"><i
                                class="fas fa-times me-1"></i> Batal</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <div class="text-center fw-bold mb-2">Antrian Anotasi</div>
                        <div id="gallery-controls" class="mb-2 d-flex justify-content-between align-items-center">
                            <div id="gallery-pagination" class="d-flex gap-2">
                                <button id="prev-page-btn" class="btn btn-outline-secondary btn-sm" disabled><i
                                        class="fas fa-chevron-left"></i></button>
                                <button id="next-page-btn" class="btn btn-outline-secondary btn-sm" disabled><i
                                        class="fas fa-chevron-right"></i></button>
                            </div>
                            <div id="gallery-info" class="text-muted small">
                                Hal <span id="current-page-display">0</span> / <span id="total-pages-display">0</span>
                                (<span id="total-images-display">0</span> gambar)
                            </div>
                        </div>
                        <div id="thumbnail-container" class="bg-light border rounded p-2"></div>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div id="annotation-wrapper">
                                {{-- Overlay untuk notifikasi estimasi --}}
                                <div id="estimation-overlay" class="hidden">
                                    <div class="content">
                                        <div id="estimation-spinner" class="spinner-border text-light" role="status">
                                        </div>
                                        <span id="estimation-overlay-text">Memulai estimasi...</span>
                                    </div>
                                </div>

                                <div id="image-title-overlay"><span id="active-image-path">Memuat...</span></div>
                                <div id="annotation-container">
                                    <img id="annotation-image" src="" alt="Gambar untuk anotasi">
                                    <div id="bbox-overlay"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <form id="annotation-form" action="{{ route('annotate.save') }}" method="POST">
                                @csrf
                                <input type="hidden" name="image_path" id="input-image-path" value="">
                                <input type="hidden" name="dataset_set" id="input-dataset-set" value="">
                                <input type="hidden" name="annotations_json" id="input-annotations-json">

                                <div id="melon-annotation-area">
                                    <div class="annotation-section mb-4" id="bbox-list-section">
                                        {{-- [PERBAIKAN] Mengganti <label> menjadi <h5> --}}
                                        <h5 class="form-label section-title">1. Tandai Lokasi Melon (<span
                                                id="bbox-count">0</span>)</h5>
                                        <div class="section-content">
                                            <p class="section-instruction">Klik & seret pada gambar, atau gunakan
                                                tombol di bawah untuk deteksi otomatis.</p>

                                            {{-- Tombol pemicu estimasi BBox --}}
                                            <div class="d-grid mb-3">
                                                <button type="button" class="btn btn-outline-primary btn-sm"
                                                    id="btn-estimate-bbox">
                                                    <i class="fas fa-magic me-2"></i>Estimasi BBox Otomatis
                                                </button>
                                            </div>

                                            <div id="bbox-list-container">
                                                <ul id="bbox-list" class="list-group list-group-flush"></ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="annotation-section mb-4" id="ripeness-section">
                                        <div id="ripeness-options" class="hidden">
                                            {{-- [PERBAIKAN] Mengganti <label> menjadi <h5> --}}
                                            <h5 class="form-label section-title required">2. Tingkat Kematangan (Bbox
                                                #<span id="selected-bbox-index">?</span>)</h5>
                                            <div class="section-content">
                                                <div class="d-flex gap-3">
                                                    <div class="form-check form-check-inline flex-fill">
                                                        <input class="form-check-input ripeness-radio" type="radio"
                                                            name="ripeness_class_selector" id="ripeness-ripe"
                                                            value="ripe" disabled>
                                                        <label class="form-check-label clickable"
                                                            for="ripeness-ripe"><i
                                                                class="fas fa-sun text-warning"></i> Matang</label>
                                                    </div>
                                                    <div class="form-check form-check-inline flex-fill">
                                                        <input class="form-check-input ripeness-radio" type="radio"
                                                            name="ripeness_class_selector" id="ripeness-unripe"
                                                            value="unripe" disabled>
                                                        <label class="form-check-label clickable"
                                                            for="ripeness-unripe"><i
                                                                class="fas fa-seedling text-success"></i> Belum
                                                            Matang</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" id="save-button" class="btn btn-primary" disabled><i
                                            class="fas fa-save me-1"></i> Simpan & Lanjutkan</button>
                                </div>
                                <div class="d-grid gap-2 mt-3">
                                    <button type="button" id="delete-current-image-btn"
                                        class="btn btn-outline-danger btn-sm"><i class="fas fa-trash-alt me-1"></i>
                                        Hapus Gambar Ini</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tampilan "Anotasi Selesai" --}}
        <div id="completion-view-wrapper" class="hidden">
            <div class="card shadow">
                <div class="card-body text-center p-lg-5">
                    <div class="alert alert-success mb-4">
                        <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Anotasi Selesai!</h4>
                        <p class="mb-0">Semua gambar dalam antrian telah dianotasi.</p>
                    </div>
                    <a href="{{ route('melon.index') }}" class="btn btn-primary nav-loader-link"
                        data-page-name="Dashboard"><i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
                    <button id="upload-new-dataset-btn-complete" class="btn btn-outline-info ms-2"><i
                            class="fas fa-upload me-1"></i> Unggah Baru</button>
                    <button id="refresh-gallery-btn-complete" class="btn btn-outline-secondary ms-2"><i
                            class="fas fa-sync-alt me-1"></i> Periksa Ulang</button>
                    <a href="{{ route('evaluate.index') }}" class="btn btn-sm btn-primary nav-loader-link"
                        data-page-name="Evaluasi"><i class="fas fa-arrow-right me-1"></i> Lihat Evaluasi</a>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal upload tidak berubah --}}
    <div class="modal fade" id="uploadDatasetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Unggah Gambar Dataset Baru</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadDatasetForm" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="image_file_upload" class="form-label">Pilih File Gambar (Bisa Banyak)</label>
                            <input class="form-control" type="file" id="image_file_upload" name="image_file[]"
                                accept="image/jpeg,image/png,image/webp" required multiple>
                        </div>
                        <div class="mb-3">
                            <label for="target_set_upload" class="form-label">Simpan ke Set Dataset:</label>
                            <select class="form-select" id="target_set_upload" name="target_set" required>
                                <option value="train" selected>Train</option>
                                <option value="valid">Valid</option>
                                <option value="test">Test</option>
                            </select>
                        </div>
                        <div id="upload-feedback" class="small mt-2"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="submitUploadDatasetBtn">Unggah</button>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
