{{-- resources/views/partials/quality-and-controls.blade.php --}}
@php
    $stats = $datasetStats ?? [];
    $sets = ['train', 'valid', 'test'];
    // [PERUBAHAN] Hapus 'non_melon' dari daftar kelas
    $annotationClasses = ['ripe_annotations' => 'Matang', 'unripe_annotations' => 'Belum Matang'];
    $featureClasses = ['ripe' => 'Matang', 'unripe' => 'Belum Matang'];
@endphp

<div class="row g-4">
    {{-- GRUP 1: DATASET FISIK & ANOTASI --}}
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="row g-0">
                    {{-- Kolom Kiri: File Gambar Fisik --}}
                    <div class="col-lg-6 border-end">
                        <div class="p-3">
                            <h6 class="section-title"><i class="fas fa-hdd me-2 text-secondary"></i>File Gambar Fisik (S3)</h6>
                            <ul class="list-group list-group-flush">
                                @php $totalPhysical = 0; @endphp
                                @foreach ($sets as $set)
                                    @php $count = $stats[$set]['physical_files'] ?? 0; $totalPhysical += $count; @endphp
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        Set {{ ucfirst($set) }} <span class="badge bg-secondary rounded-pill">{{ $count }}</span>
                                    </li>
                                @endforeach
                                <li class="list-group-item d-flex justify-content-between align-items-center fw-bold bg-light mt-2 px-2">
                                    Total Gambar<span class="badge bg-primary rounded-pill fs-6">{{ $totalPhysical }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    {{-- Kolom Kanan: Data Anotasi --}}
                    <div class="col-lg-6">
                        <div class="p-3">
                            <h6 class="section-title"><i class="fas fa-tags me-2 text-success"></i>Data Anotasi (CSV)</h6>
                             <ul class="list-group list-group-flush">
                                @php $totalAnnotations = 0; @endphp
                                @foreach ($sets as $set)
                                    @php
                                        $setAnnotations = $stats[$set]['annotations'] ?? [];
                                        // [PERUBAHAN] Penjumlahan total tidak lagi menyertakan non_melon
                                        $count = ($setAnnotations['ripe_annotations'] ?? 0) + ($setAnnotations['unripe_annotations'] ?? 0);
                                        $totalAnnotations += $count;
                                    @endphp
                                    <li class="list-group-item px-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Anotasi di Set {{ ucfirst($set) }}</span>
                                            <span class="badge bg-success rounded-pill">{{ $count }}</span>
                                        </div>
                                        <div class="mt-1 small text-muted">
                                            {{-- [PERUBAHAN] Looping hanya untuk kelas yang relevan --}}
                                            @foreach ($annotationClasses as $key => $displayName)
                                                <div class="d-flex justify-content-between ps-3">
                                                    <span>- {{ $displayName }}</span>
                                                    <span>{{ $setAnnotations[$key] ?? 0 }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                                <li class="list-group-item d-flex justify-content-between align-items-center fw-bold bg-light mt-2 px-2">
                                    Total Anotasi <span class="badge bg-primary rounded-pill fs-6">{{ $totalAnnotations }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ====================================================================== --}}
    {{-- GRUP 2: FITUR DIEKSTRAK & RINGKASAN FITUR                            --}}
    {{-- ====================================================================== --}}
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="row g-0">
                    {{-- Kolom Kiri: Fitur Diekstrak --}}
                     <div class="col-lg-6 border-end">
                        <div class="p-3">
                             <h6 class="section-title"><i class="fas fa-drafting-compass me-2 text-info"></i>Fitur Diekstrak (CSV)</h6>
                            <ul class="list-group list-group-flush">
                                @php $totalFeatures = 0; @endphp
                                @foreach ($sets as $set)
                                    @php
                                        $setFeatures = $stats[$set]['features']['unified'] ?? [];
                                        $count = $setFeatures['total'] ?? 0;
                                        $totalFeatures += $count;
                                    @endphp
                                    <li class="list-group-item px-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                           <span>Fitur di Set {{ ucfirst($set) }}</span>
                                           <span class="badge bg-info rounded-pill">{{ $count }}</span>
                                        </div>
                                        <div class="mt-1 small text-muted">
                                            {{-- [PERUBAHAN] Looping hanya untuk kelas yang relevan --}}
                                            @foreach ($featureClasses as $key => $displayName)
                                                <div class="d-flex justify-content-between ps-3">
                                                    <span>- {{ $displayName }}</span>
                                                    <span>{{ $setFeatures[$key] ?? 0 }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                                <li class="list-group-item d-flex justify-content-between align-items-center fw-bold bg-light mt-2 px-2">
                                    Total Fitur <span class="badge bg-primary rounded-pill fs-6">{{ $totalFeatures }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    {{-- Kolom Kanan: Ringkasan Fitur --}}
                    <div class="col-lg-6">
                    <div class="p-3">
                        <h6 class="section-title"><i class="fas fa-cogs me-2"></i>Rincian Fitur yang Digunakan</h6>

                        {{-- --- [PERBAIKAN UTAMA DI SINI] --- --}}
                        <p class="text-muted small">Total ada <strong>{{ $featureInfo['count'] ?? 0 }} fitur terpilih</strong> yang digunakan untuk melatih semua model.</p>

                        {{-- Kita tidak lagi menggunakan accordion, tapi langsung menampilkan list --}}
                        <div class="feature-list-container border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <div class="feature-list">
                                @forelse($featureInfo['names'] ?? [] as $name)
                                    <code>{{ $name }}</code>
                                @empty
                                    <p class="small text-muted mb-0">Tidak ada data fitur.</p>
                                @endforelse
                            </div>
                        </div>
                            <style>.feature-list { display: flex; flex-wrap: wrap; gap: 0.5rem; } .feature-list code { font-size: 0.75rem; padding: 0.2rem 0.4rem; }</style>
                            {{-- --- AKHIR PERBAIKAN --- --}}
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ====================================================================== --}}
    {{-- GRUP 3: KONTROL PROSES & TRAINING                                    --}}
    {{-- ====================================================================== --}}
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-light py-2">
                <h6 class="mb-0 text-primary-emphasis"><i class="fas fa-shield-alt me-2"></i>Kontrol Proses & Training</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <p class="small text-muted">Jalankan untuk menambah fitur dari data baru tanpa menghapus yang lama. (Lebih Cepat)</p>
                        <button id="extract-features-inc-btn" class="btn btn-secondary w-100 sse-action-btn"
                                data-stream-url="{{ route('evaluate.stream.extract_features_incremental') }}"
                                data-log-target="#sse-log-extract">
                            <i class="fas fa-plus-circle me-1"></i> Ekstraksi Fitur (Incremental)
                        </button>
                    </div>
                    <div class="col-md-6 mb-3">
                        <p class="small text-muted">Latih ulang semua model berdasarkan fitur terbaru yang tersedia.</p>
                        <button id="train-model-btn" class="btn btn-primary w-100 sse-action-btn"
                                data-stream-url="{{ route('evaluate.stream.train_model') }}"
                                data-log-target="#sse-log-train">
                            <i class="fas fa-brain me-1"></i> Latih Model
                        </button>
                    </div>
                </div>
                <div id="sse-log-extract" class="sse-log bg-dark text-white p-3 rounded small mt-2" style="display:none; max-height: 250px; overflow-y: auto; font-family: 'Courier New', Courier, monospace;"></div>
                <div id="sse-log-train" class="sse-log bg-dark text-white p-3 rounded small mt-2" style="display:none; max-height: 250px; overflow-y: auto; font-family: 'Courier New', Courier, monospace;"></div>
            </div>
        </div>
    </div>
</div>
