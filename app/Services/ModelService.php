<?php

namespace App\Services;

use App\Persisters\S3ObjectPersister;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Rubix\ML\Estimator;
use Throwable;

class ModelService
{
    public const MODEL_DIR_S3 = 'models';
    public const CACHE_PREFIX = 'ml_model_';

    public const BASE_MODEL_KEYS = [
        'k_nearest_neighbors',
        'random_forest',
    ];

    protected const CACHE_DURATION = 3600; // 1 jam

    // [PERBAIKAN] Tambahkan cache statis untuk menyimpan model selama satu request.
    // Ini mencegah pemuatan berulang dari Cache (Redis/File) dalam satu siklus hidup request.
    private static array $requestCache = [];

    /**
     * Memuat model dari S3, dengan opsi untuk memaksa pengambilan baru (mengabaikan cache).
     *
     * @param string $modelKey Kunci model yang akan dimuat.
     * @param bool $forceFresh Jika true, akan mengabaikan semua cache dan mengambil langsung dari S3.
     * @return object|null Objek Rubix ML yang dimuat.
     */
    public function loadModel(string $modelKey, bool $forceFresh = false): ?object
    {
        // [PERBAIKAN] Langkah 1: Cek cache request-scoped statis terlebih dahulu.
        if (!$forceFresh && isset(self::$requestCache[$modelKey])) {
            return self::$requestCache[$modelKey];
        }

        $cacheKey = self::CACHE_PREFIX . $modelKey;

        // [PERBAIKAN] Langkah 2: Cek cache persisten (Redis/File).
        if (!$forceFresh) {
            $cached = Cache::get($cacheKey);
            if (is_object($cached)) {
                // Simpan ke cache statis untuk pemanggilan berikutnya dalam request yang sama
                self::$requestCache[$modelKey] = $cached;
                return $cached;
            }
        }

        // [PERBAIKAN] Langkah 3: Jika tidak ada di cache, ambil dari S3.
        $s3ObjectPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '.model';
        if (!Storage::disk('s3')->exists($s3ObjectPath)) {
            Log::warning("ModelService: File model tidak ditemukan di S3 untuk kunci '{$modelKey}'.", ['path' => $s3ObjectPath]);
            return null;
        }

        try {
            $persister = new S3ObjectPersister($s3ObjectPath);
            $loadedObject = $persister->load();

            if (!is_object($loadedObject)) {
                Log::warning("ModelService: Gagal memuat atau hasil unserialize bukan objek.", [
                    'key' => $modelKey,
                    'type' => gettype($loadedObject)
                ]);
                return null;
            }

            if (str_starts_with(get_class($loadedObject), 'Rubix\\ML\\')) {
                // Simpan ke cache persisten (Redis/File)
                Cache::put($cacheKey, $loadedObject, self::CACHE_DURATION);
                // Simpan juga ke cache statis untuk request saat ini
                self::$requestCache[$modelKey] = $loadedObject;

                Log::info("ModelService: Model '{$modelKey}' berhasil dimuat dari S3 dan disimpan ke cache.", ['class' => get_class($loadedObject)]);
                return $loadedObject;
            }

            Log::error("ModelService: Objek yang dimuat BUKAN objek Rubix ML yang valid.", [
                'key' => $modelKey,
                'class' => get_class($loadedObject)
            ]);
            return null;
        } catch (Throwable $e) {
            Log::error("Failed to load model from file", ['key' => $modelKey, 'path' => $s3ObjectPath, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function loadModelMetadata(string $modelKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $modelKey . '_meta';
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($modelKey) {
            $s3MetaPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_meta.json';
            if (!Storage::disk('s3')->exists($s3MetaPath)) return null;

            try {
                $content = Storage::disk('s3')->get($s3MetaPath);
                $metadata = json_decode($content, true);
                return (json_last_error() === JSON_ERROR_NONE && is_array($metadata)) ? $metadata : null;
            } catch (Throwable $e) {
                Log::error("Error reading metadata file for {$modelKey}", ['path' => $s3MetaPath, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    public function loadModelMetrics(?string $modelKey): ?array
    {
        $metricsFile = 'all_model_metrics.json';
        $cacheKey = self::CACHE_PREFIX . 'all_model_metrics';
        $s3MetricsPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $metricsFile;

        $allMetrics = Cache::remember($cacheKey, now()->addHours(6), function () use ($s3MetricsPath) {
            if (!Storage::disk('s3')->exists($s3MetricsPath)) return null;
            try {
                $content = Storage::disk('s3')->get($s3MetricsPath);
                $decoded = json_decode($content, true);
                return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
            } catch (Throwable $e) {
                Log::error("Exception reading/decoding combined metrics file", ['path' => $s3MetricsPath, 'error' => $e->getMessage()]);
                return null;
            }
        });

        if ($allMetrics === null) return null;
        if ($modelKey === null) return $allMetrics;

        return $allMetrics[$modelKey] ?? null;
    }

    public function loadLearningCurveData(string $modelKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $modelKey . '_learning_curve';
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($modelKey) {
            $s3LcPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_learning_curve.json';
            if (!Storage::disk('s3')->exists($s3LcPath)) return null;

            try {
                $content = Storage::disk('s3')->get($s3LcPath);
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data) && isset($data['train_sizes'])) {
                    return $data;
                }
                return null;
            } catch (Throwable $e) {
                return null;
            }
        });
    }

    public function loadCrossValidationScores(string $modelKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $modelKey . '_cv_scores';
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($modelKey) {
            $s3CvPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_cv_scores.json';
            if (!Storage::disk('s3')->exists($s3CvPath)) return null;

            try {
                $content = Storage::disk('s3')->get($s3CvPath);
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data) && isset($data['k_folds'])) {
                    return $data;
                }
                return null;
            } catch (Throwable $e) {
                return null;
            }
        });
    }

    public function saveLearningCurve(string $modelKey, array $learningCurve): bool
    {
        try {
            $s3Path = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_learning_curve.json';
            $jsonContent = json_encode($learningCurve, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) return false;

            Storage::disk('s3')->put($s3Path, $jsonContent, 'private');
            Cache::forget(self::CACHE_PREFIX . $modelKey . '_learning_curve');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function saveCrossValidationScores(string $modelKey, array $cvResults): bool
    {
        if (!isset($cvResults['k_folds']) || !isset($cvResults['metrics_per_fold'])) {
            return false;
        }
        try {
            $s3Path = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_cv_scores.json';
            $jsonContent = json_encode($cvResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) return false;

            Storage::disk('s3')->put($s3Path, $jsonContent, 'private');
            Cache::forget(self::CACHE_PREFIX . $modelKey . '_cv_scores');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Membersihkan cache terkait evaluasi (metrik gabungan & hasil tes).
     */
    public function clearEvaluationCache(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'all_model_metrics');
        Cache::forget(self::CACHE_PREFIX . 'all_model_test_results');

        Log::info("Unified evaluation caches (combined metrics, combined tests) cleared.");
    }
}
