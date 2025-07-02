@php
    // ensemble-card-content.blade.php
    $metadata = $evalData['metadata'] ?? [];
    $validationMetrics = $evalData['validation_metrics'] ?? null;
    $testResults = $evalData['test_results'] ?? null;
    $valChartId = "valMetricsChart-{$chartIdSuffix}";
    $testChartId = "testMetricsChart-{$chartIdSuffix}";
    // BARU: ID untuk grafik perbandingan
    $perfCompareChartId = "perfCompareChart-{$chartIdSuffix}";
@endphp

<div class="row">
    <div class="col-xl-3 col-md-4">
        <div class="p-3">
            <h5 class="section-title"><i class="fas fa-layer-group"></i>Info Ensemble</h5>
            <div class="metric-row small"><span>Meta-Learner</span> <span class="fw-bold">{{ Str::limit(class_basename($metadata['algorithm_class'] ?? 'N/A'), 20) }}</span></div>
            <div class="metric-row small"><span>Dilatih pada</span> <span class="fw-bold">{{ \Carbon\Carbon::parse($metadata['trained_at'] ?? now())->isoFormat('D MMM, HH:mm') }}</span></div>

            {{-- JUMLAH SAMPEL BARU --}}
            <h6 class="mt-3 sub-section-title">Distribusi Sampel</h6>
            <div class="metric-row small"><span>Training</span> <span class="fw-bold">{{ number_format($metadata['training_samples_count'] ?? 0) }} sampel</span></div>
            <div class="metric-row small"><span>Validasi</span> <span class="fw-bold">{{ number_format($metadata['validation_samples_count'] ?? 0) }} sampel</span></div>
            <div class="metric-row small"><span>Test</span> <span class="fw-bold">{{ number_format($metadata['test_samples_count'] ?? 0) }} sampel</span></div>

            <div class="metric-row small mt-2"><span>Model Dasar</span>
                <span class="fw-bold text-end">
                    @foreach($metadata['base_models_used'] ?? [] as $baseModel)
                        {{ Str::title(str_replace('_', ' ', $baseModel)) }}@if(!$loop->last),@endif<br>
                    @endforeach
                </span>
            </div>
            <hr>
            {{-- BARU: Grafik Perbandingan Performa --}}
            <h6 class="sub-section-title">Perbandingan Performa</h6>
             <div class="chart-container" style="height: 220px;">
                <canvas id="{{ $perfCompareChartId }}"></canvas>
            </div>
            <p class="text-muted small mt-2">Grafik ini membandingkan akurasi pada data validasi (yang tidak dilihat saat training) dengan data test untuk mengukur generalisasi model.</p>
        </div>
    </div>

    <div class="col-xl-4 col-md-8 border-start border-end">
         <div class="p-3">
            <h5 class="section-title"><i class="fas fa-check-double"></i>Hasil Validasi Meta-Learner</h5>
            @if($validationMetrics)
                <div class="metric-overall text-center mb-3">
                    <div class="metric-group-title text-muted">Akurasi Validasi</div>
                    <div class="metric-value">{{ number_format(($validationMetrics['metrics']['accuracy'] ?? 0) * 100, 2) }}%</div>
                </div>
                <div class="chart-container" style="height: 200px;"><canvas id="{{ $valChartId }}"></canvas></div>
                {{-- PERBAIKAN: Mengirim data matriks dengan benar --}}
                @include('partials.confusion-matrix-table', [
                    'matrix' => $validationMetrics['confusion_matrix'] ?? [],
                    'labels' => $validationMetrics['classes'] ?? []
                ])
            @else
                <p class="text-muted small text-center p-5">Data validasi tidak tersedia.</p>
            @endif
        </div>
    </div>

    <div class="col-xl-5 col-md-12">
        <div class="p-3">
            <h5 class="section-title"><i class="fas fa-vial"></i>Hasil Test Final Ensemble</h5>
             @if($testResults)
                <div class="metric-overall text-center mb-3">
                    <div class="metric-group-title text-muted">Akurasi Test</div>
                    <div class="metric-value text-success">{{ number_format(($testResults['metrics']['accuracy'] ?? 0) * 100, 2) }}%</div>
                </div>
                <div class="chart-container" style="height: 200px;"><canvas id="{{ $testChartId }}"></canvas></div>
                {{-- PERBAIKAN: Mengirim data matriks dengan benar --}}
                @include('partials.confusion-matrix-table', [
                    'matrix' => $testResults['confusion_matrix'] ?? [],
                    'labels' => $testResults['classes'] ?? []
                ])
            @else
                <p class="text-muted small text-center p-5">Data test tidak tersedia.</p>
            @endif
        </div>
    </div>
</div>
