{{-- resources/views/partials/metric-display.blade.php --}}
@php
    $accuracy = $metrics['accuracy'] ?? null;
    $metricsData = isset($metrics['metrics']) ? $metrics['metrics'] : $metrics;
    $accuracy = $metricsData['accuracy'] ?? null;
    $metricsPerClass = $metrics['metrics_per_class'] ?? [];
    $getScoreColorClass = function ($score) {
        if (!is_numeric($score)) return 'text-muted';
        if ($score >= 0.85) return 'text-success-emphasis';
        if ($score >= 0.7) return 'text-primary-emphasis';
        if ($score >= 0.5) return 'text-warning-emphasis';
        return 'text-danger-emphasis';
    };
    $_formatPercent = fn($v) => is_numeric($v) ? number_format($v * 100, 1) . '%' : 'N/A';
@endphp

<div class="metrics-display-wrapper bg-white rounded shadow-sm p-3">
    <div class="metric-overall text-center mb-3 pb-3 border-bottom">
        <h6 class="metric-title text-muted small text-uppercase fw-semibold">Akurasi Keseluruhan</h6>
        <p class="metric-value display-5 fw-bold {{ $getScoreColorClass($accuracy) }} mb-0">{{ $_formatPercent($accuracy) }}</p>
    </div>
    <div class="row gx-lg-4 gy-3 text-center">
        @foreach ($metricsPerClass as $class => $classMetrics)
            <div class="col-md-4 metric-group border-end-md">
                <h6 class="metric-group-title fw-semibold text-primary mb-2 pb-1 border-bottom border-primary d-inline-block">
                    {{ ucfirst(str_replace('_', ' ', $class)) }}
                </h6>
                <div class="metric-row">
                    <span class="metric-label">Presisi</span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($classMetrics['precision'] ?? null) }}">{{ $_formatPercent($classMetrics['precision'] ?? null) }}</span>
                </div>
                <div class="metric-row">
                    <span class="metric-label">Recall</span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($classMetrics['recall'] ?? null) }}">{{ $_formatPercent($classMetrics['recall'] ?? null) }}</span>
                </div>
                <div class="metric-row">
                    <span class="metric-label">F1-Score</span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($classMetrics['f1_score'] ?? null) }}">{{ $_formatPercent($classMetrics['f1_score'] ?? null) }}</span>
                </div>
            </div>
        @endforeach
    </div>
</div>
