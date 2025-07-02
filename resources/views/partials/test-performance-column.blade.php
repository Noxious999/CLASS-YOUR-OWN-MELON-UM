{{-- resources/views/partials/test-performance-column.blade.php --}}
<section class="model-section test-performance-section card shadow-sm flex-grow-1">
     <div class="card-header bg-light-subtle py-3">
        <h6 class="mb-0 fw-semibold text-dark-emphasis d-flex align-items-center section-title-like">
            <i class="fas fa-vial me-2 text-warning"></i>Performa Generalisasi (Set Test)
        </h6>
    </div>
    <div class="card-body p-3 p-lg-4">
        {{-- PERBAIKAN: Gunakan data $testResults langsung --}}
        @if ($testResults)
            @include('partials.metric-display', ['metrics' => $testResults])
            <div class="mt-4">
                @include('partials.confusion-matrix-table', [
                    'matrix' => $testResults['confusion_matrix'] ?? [],
                    'labels' => $testResults['classes'] ?? []
                ])
            </div>
        @else
            <div class="text-center py-4 text-muted">
                <i class="far fa-folder-open fa-2x mb-2"></i>
                <p class="mb-0">Metrik test tidak tersedia.</p>
                <small>Jalankan training dengan opsi `--with-test` atau periksa log.</small>
            </div>
        @endif
    </div>
</section>
