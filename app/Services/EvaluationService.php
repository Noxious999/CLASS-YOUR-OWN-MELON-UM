<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

class EvaluationService
{
    public const FLASH_RESULT = 'result';
    public const FLASH_ERROR  = 'error';

    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function getAggregatedEvaluationData(): array
    {
        $evaluation = [];
        // --- [PERBAIKAN KONSISTENSI] ---
        // Ambil kunci dasar langsung dari ModelService agar selalu sinkron.
        $modelBaseKeys = ModelService::BASE_MODEL_KEYS;
        // --- AKHIR PERBAIKAN ---

        foreach ($modelBaseKeys as $baseKey) {
            // PERBAIKAN KRUSIAL:
            // Hapus penambahan '_model'. Gunakan kunci dasar apa adanya.
            $modelKey = $baseKey;

            try {
                $metadata = $this->modelService->loadModelMetadata($modelKey);
                if (!$metadata) {
                    $evaluation[$modelKey] = null;
                    continue;
                }
                $validationMetrics = $this->modelService->loadModelMetrics($modelKey);
                $learningCurveData = $this->modelService->loadLearningCurveData($modelKey);
                $cvResults = $this->modelService->loadCrossValidationScores($modelKey);
                $evaluation[$modelKey] = [
                    'metadata' => $metadata,
                    'validation_metrics' => $validationMetrics,
                    'learning_curve_data' => $learningCurveData,
                    'cv_results' => $cvResults,
                ];
            } catch (Throwable $e) {
                Log::error("EvaluationService: Error processing data for model {$modelKey}", ['error' => $e->getMessage()]);
                $evaluation[$modelKey] = null;
            }
        }

        return $evaluation;
    }

    public function calculateMetrics(array $trueLabels, array $predictedLabels, string $positiveLabel): array
    {
        if (count($trueLabels) !== count($predictedLabels) || empty($trueLabels)) {
            return [0.0, 0.0, 0.0];
        }

        $tp = 0;
        $fp = 0;
        $fn = 0;
        foreach ($trueLabels as $i => $actual) {
            $predicted = $predictedLabels[$i] ?? null;
            if ($actual === $positiveLabel) {
                if ($predicted === $positiveLabel) $tp++;
                else $fn++;
            } else {
                if ($predicted === $positiveLabel) $fp++;
            }
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0.0;

        return [round($precision, 4), round($recall, 4), round($f1, 4)];
    }

    public function confusionMatrix(array $actual, array $predicted, array $labels): array
    {
        $matrix = array_fill_keys($labels, array_fill_keys($labels, 0));
        foreach ($actual as $i => $actualLabel) {
            $predictedLabel = $predicted[$i] ?? null;
            if (isset($matrix[$actualLabel][$predictedLabel])) {
                $matrix[$actualLabel][$predictedLabel]++;
            }
        }
        return $matrix;
    }

    public function analyzeOverfitting(float $trainScore, float $validationScore): array
    {
        $difference = abs($trainScore - $validationScore) * 100;
        $isOverfitting = $trainScore > $validationScore && $difference > 5.0;
        $severity = 'low';
        if ($isOverfitting) {
            if ($difference > 15.0) $severity = 'high';
            elseif ($difference > 5.0) $severity = 'medium';
        }

        return [
            'is_overfitting' => $isOverfitting,
            'difference' => round($difference, 2),
            'severity' => $severity,
            'train_score' => round($trainScore, 4),
            'validation_score' => round($validationScore, 4),
        ];
    }

    public function calculateCrossValidationStats(array $scores): array
    {
        $n = count($scores);
        if ($n === 0) return ['mean' => 0.0, 'std' => 0.0, 'min' => 0.0, 'max' => 0.0];
        $mean = array_sum($scores) / $n;
        $std = 0.0;
        if ($n > 1) {
            $sumSquaredDiffs = array_reduce($scores, fn($carry, $item) => $carry + pow((float) $item - $mean, 2), 0.0);
            $std = sqrt($sumSquaredDiffs / ($n - 1));
        }
        return ['mean' => round($mean, 4), 'std' => round($std, 4), 'min' => round(min($scores), 4), 'max' => round(max($scores), 4)];
    }

    public function analyzeLearningCurve(array $learningCurve): array
    {
        if (empty($learningCurve['train_sizes']) || empty($learningCurve['train_scores']) || empty($learningCurve['test_scores'])) {
            return ['recommendation' => 'Data learning curve tidak lengkap.'];
        }
        $trainScores = $learningCurve['train_scores'];
        $testScores = $learningCurve['test_scores'];
        $lastTrainScore = end($trainScores);
        $lastTestScore = end($testScores);
        $finalGap = is_numeric($lastTrainScore) && is_numeric($lastTestScore) ? abs($lastTrainScore - $lastTestScore) : null;

        $recommendation = "Analisis general: ";
        if ($finalGap === null) {
            $recommendation .= "Skor tidak valid.";
        } elseif ($finalGap > 0.15) {
            $recommendation .= "Terindikasi overfitting kuat (gap > 15%).";
        } elseif ($finalGap < 0.05 && $lastTestScore < 0.75) {
            $recommendation .= "Terindikasi underfitting (skor rendah, gap kecil).";
        } else {
            $recommendation .= "Model menunjukkan keseimbangan yang baik.";
        }
        return ['final_gap' => $finalGap, 'recommendation' => $recommendation];
    }

    public function getLatestTrainingTimes(array $evaluationData): array
    {
        $latestTime = null;
        if (is_array($evaluationData)) {
            foreach ($evaluationData as $data) {
                if (empty($data) || !isset($data['metadata']['trained_at'])) continue;
                try {
                    $currentTime = Carbon::parse($data['metadata']['trained_at']);
                    if (!$latestTime || $currentTime->isAfter($latestTime)) {
                        $latestTime = $currentTime;
                    }
                } catch (Throwable $e) { /* ignore invalid dates */
                }
            }
        }
        $lastTrainingTimeFormatted = $latestTime ? $latestTime->isoFormat('D MMM, HH:mm') : 'N/A';
        return [$lastTrainingTimeFormatted];
    }

    public function loadTestResults(?string $modelKey): ?array
    {
        $metricsFile = 'all_model_test_results.json';
        $cacheKey = ModelService::CACHE_PREFIX . 'all_model_test_results';
        $s3MetricsPath = rtrim(ModelService::MODEL_DIR_S3, '/') . '/' . $metricsFile;

        $allTestResults = Cache::remember($cacheKey, now()->addHours(6), function () use ($s3MetricsPath) {
            if (!Storage::disk('s3')->exists($s3MetricsPath)) {
                return null;
            }
            try {
                $content = Storage::disk('s3')->get($s3MetricsPath);
                $decoded = json_decode($content, true);
                return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
            } catch (Throwable $e) {
                Log::error("Exception reading/decoding combined test results file", ['path' => $s3MetricsPath, 'error' => $e->getMessage()]);
                return null;
            }
        });

        if ($allTestResults === null) {
            return null;
        }
        if ($modelKey === null) {
            return $allTestResults;
        }

        return $allTestResults[$modelKey] ?? null;
    }
}
