{{-- resources/views/partials/model-stability.blade.php --}}
@php
    $lcStabilityInfo = $advancedAnalysisData['stability'] ?? null;
    $learningCurveRaw = $evalData['learning_curve_data'] ?? null;
    $hasLearningCurveData = !empty($learningCurveRaw['train_sizes']);
@endphp

<div class="model-stability-analysis-container">
    <h6 class="section-title"><i class="fas fa-chart-line text-primary"></i>Kurva Pembelajaran (Learning Curve)</h6>
    @if ($hasLearningCurveData)
        @if ($lcStabilityInfo && isset($lcStabilityInfo['recommendation']))
            <div class="alert alert-info small p-2 mb-3">
                <strong>Interpretasi:</strong> {{ $lcStabilityInfo['recommendation'] }}
            </div>
        @endif
        <div class="learning-curve-graphic-container mt-auto flex-grow-1" data-chart-height="250px">
            <canvas id="learningCurve_{{ $chartIdSuffix }}"></canvas>
        </div>
    @else
        <div class="text-center py-5 text-muted">
            <i class="far fa-chart-line fa-3x mb-3"></i>
            <p><em>Data learning curve tidak tersedia untuk model ini.</em></p>
        </div>
    @endif
</div>
