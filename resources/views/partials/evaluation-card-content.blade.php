@php
    // resources/views/partials/evaluation-card-content.blade.php
    $metadata = $evalData['metadata'] ?? [];
    $validationMetrics = $evalData['validation_metrics'] ?? null;
    $testResults = $evalData['test_results'] ?? null;
    $learningCurve = $evalData['learning_curve_data'] ?? null;
    $lcChartId = "learningCurve-{$chartIdSuffix}";
    $valChartId = "valMetricsChart-{$chartIdSuffix}";
    $testChartId = "testMetricsChart-{$chartIdSuffix}";
@endphp

<div class="row">
    <div class="col-xl-3 col-md-4">
        <div class="p-3">
            <h5 class="section-title"><i class="fas fa-info-circle"></i>Info Model</h5>
            <div class="metric-row small"><span>Algoritma</span> <span class="fw-bold">{{ Str::limit(class_basename($metadata['algorithm_class'] ?? 'N/A'), 25) }}</span></div>
            <div class="metric-row small"><span>Scaler</span> <span class="fw-bold">{{ Str::limit(class_basename($metadata['scaler_used_class'] ?? 'N/A'), 25) }}</span></div>
            <div class="metric-row small"><span>Dilatih pada</span> <span class="fw-bold">{{ \Carbon\Carbon::parse($metadata['trained_at'] ?? now())->isoFormat('D MMM, HH:mm') }}</span></div>

            {{-- JUMLAH SAMPEL BARU --}}
            <h6 class="mt-3 sub-section-title">Distribusi Sampel</h6>
            <div class="metric-row small"><span>Training</span> <span class="fw-bold">{{ number_format($metadata['training_samples_count'] ?? 0) }} sampel</span></div>
            <div class="metric-row small"><span>Validasi</span> <span class="fw-bold">{{ number_format($metadata['validation_samples_count'] ?? 0) }} sampel</span></div>
            <div class="metric-row small"><span>Test</span> <span class="fw-bold">{{ number_format($metadata['test_samples_count'] ?? 0) }} sampel</span></div>

            <h6 class="mt-3 sub-section-title">Hyperparameters</h6>
            <div class="hyperparameter-list small">
                @forelse($metadata['hyperparameters'] ?? [] as $param => $value)
                    <div class="metric-row">
                        <span>{{ Str::snake($param, ' ') }}</span>
                        {{-- [PERBAIKAN] Logika untuk menampilkan nilai null --}}
                        @php
                            $displayValue = 'N/A';
                            if (is_bool($value)) {
                                $displayValue = $value ? 'true' : 'false';
                            } elseif (is_null($value)) {
                                $displayValue = 'null (auto)'; // Tampilkan ini jika nilainya null
                            } elseif (is_array($value)) {
                                $displayValue = json_encode($value);
                            } else {
                                $displayValue = $value;
                            }
                        @endphp
                        <code class="fw-bold">{{ $displayValue }}</code>
                    </div>
                @empty
                    <p class="small text-muted">Tidak ada data.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-xl-6 col-md-8 border-start border-end">
        <div class="row">
            <div class="col-md-6 border-end">
                <div class="p-3">
                    <h5 class="section-title"><i class="fas fa-check-double"></i>Hasil Validasi</h5>
                    @if($validationMetrics)
                        <div class="metric-overall text-center mb-3">
                            <div class="metric-group-title text-muted">Akurasi Validasi</div>
                            <div class="metric-value">{{ number_format(($validationMetrics['metrics']['accuracy'] ?? 0) * 100, 2) }}%</div>
                        </div>
                        <div class="chart-container" style="height: 180px;"><canvas id="{{ $valChartId }}"></canvas></div>
                        @include('partials.confusion-matrix-table', [
                            'matrix' => $validationMetrics['confusion_matrix'] ?? [],
                            'labels' => $validationMetrics['classes'] ?? []
                        ])
                    @else
                        <p class="text-muted small text-center p-4">Data tidak tersedia.</p>
                    @endif
                </div>
            </div>
            <div class="col-md-6">
                 <div class="p-3">
                    <h5 class="section-title"><i class="fas fa-vial"></i>Hasil Test</h5>
                    @if($testResults)
                        <div class="metric-overall text-center mb-3">
                            <div class="metric-group-title text-muted">Akurasi Test</div>
                            <div class="metric-value text-success">{{ number_format(($testResults['metrics']['accuracy'] ?? 0) * 100, 2) }}%</div>
                        </div>
                        <div class="chart-container" style="height: 180px;"><canvas id="{{ $testChartId }}"></canvas></div>
                        @include('partials.confusion-matrix-table', [
                            'matrix' => $testResults['confusion_matrix'] ?? [],
                            'labels' => $testResults['classes'] ?? []
                        ])
                    @else
                        <p class="text-muted small text-center p-4">Data tidak tersedia.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-12">
        <div class="p-3">
            <h5 class="section-title"><i class="fas fa-chart-line"></i>Kurva Belajar</h5>
            @if($learningCurve && !empty($learningCurve['train_sizes']))
                 <div class="chart-container" style="height: 300px;">
                    <canvas id="{{ $lcChartId }}"></canvas>
                </div>
            @else
                <p class="text-muted small text-center p-5">Data kurva belajar tidak tersedia untuk model ini.</p>
            @endif
        </div>
    </div>
</div>
