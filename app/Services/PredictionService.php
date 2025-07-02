<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Probabilistic;
use Rubix\ML\Transformers\Transformer;
use Symfony\Component\Process\Process;
use RuntimeException;
use Throwable;

class PredictionService
{
    public const S3_UPLOAD_DIR_TEMP = 'uploads_temp';
    protected ModelService $modelService;
    protected FeatureExtractionService $featureExtractor;

    public function __construct(ModelService $modelService, FeatureExtractionService $featureExtractor)
    {
        $this->modelService = $modelService;
        $this->featureExtractor = $featureExtractor;
    }

    /**
     * Memproses gambar dari S3, mendeteksi semua melon, dan mengklasifikasikan masing-masing.
     * [PERUBAHAN] Logika diubah: deteksi otomatis selalu jalan pertama,
     * Bbox manual dari pengguna bersifat opsional untuk menimpa hasil deteksi.
     *
     * @param string $s3Path Path gambar di S3.
     * @param string $originalFilename Nama file asli.
     * @param array|null $userProvidedBboxes Array Bbox yang disediakan pengguna untuk re-klasifikasi.
     * @return array Hasil klasifikasi.
     */
    public function classifyImageFromS3(string $s3Path, string $originalFilename, ?array $userProvidedBboxes = null): array
    {
        // [DEBUGGING] Mulai timer utama
        $pipelineStartTime = microtime(true);
        Log::info("[TIMER] Pipeline dimulai.", ['s3_path' => $s3Path]);

        $response = [
            'success' => false,
            'message' => 'Memulai proses...',
            'filename' => $originalFilename,
            'all_results' => [],
        ];

        try {
            $allBboxes = [];

            if ($userProvidedBboxes !== null) {
                // ALUR 2: Pengguna memberikan Bbox secara manual untuk di-klasifikasi ulang.
                Log::info('Menggunakan BBox yang disediakan pengguna untuk klasifikasi ulang.', ['count' => count($userProvidedBboxes)]);

                // Konversi Bbox relatif (cx, cy, w, h) dari frontend ke absolut (x, y, w, h)
                $imageForDims = new Imagick();
                $imageForDims->readImageBlob(Storage::disk('s3')->get($s3Path));
                $imgW = $imageForDims->getImageWidth();
                $imgH = $imageForDims->getImageHeight();
                $imageForDims->clear();

                foreach ($userProvidedBboxes as $relativeBbox) {
                    $allBboxes[] = [
                        'x' => ($relativeBbox['cx'] - $relativeBbox['w'] / 2) * $imgW,
                        'y' => ($relativeBbox['cy'] - $relativeBbox['h'] / 2) * $imgH,
                        'w' => $relativeBbox['w'] * $imgW,
                        'h' => $relativeBbox['h'] * $imgH,
                    ];
                }
            } else {
                // ALUR 1 (DEFAULT): Jalankan deteksi otomatis menggunakan model YOLO.
                Log::info('Menjalankan deteksi BBox otomatis via Python script.');
                $allBboxes = $this->runPythonBboxEstimator($s3Path);
            }
            // --- AKHIR PERUBAHAN UTAMA ---


            if (empty($allBboxes)) {
                $response['success'] = true;
                $response['message'] = 'Tidak ada objek melon yang terdeteksi pada gambar.';
                $response['classification'] = 'non_melon'; // Kunci untuk rendering di frontend
                return $response;
            }

            $imageContent = Storage::disk('s3')->get($s3Path);
            $image = new Imagick();
            $image->readImageBlob($imageContent);
            $imageWidth = $image->getImageWidth();
            $imageHeight = $image->getImageHeight();
            $image->clear();

            if ($imageWidth === 0 || $imageHeight === 0) {
                throw new RuntimeException('Gagal mendapatkan dimensi gambar dari S3.');
            }

            $allClassificationResults = [];
            Log::info("[TIMER] Memulai loop ekstraksi fitur & klasifikasi untuk " . count($allBboxes) . " BBox...");
            $loopStartTime = microtime(true);

            foreach ($allBboxes as $i => $bbox) {
                $bboxStartTime = microtime(true);
                if (!isset($bbox['x'], $bbox['y'], $bbox['w'], $bbox['h'])) {
                    Log::warning('Format BBox tidak valid, dilewati.', ['bbox' => $bbox]);
                    continue;
                }

                $tempAnnotation = [
                    'detection_class' => 'melon',
                    'bbox_cx' => ($bbox['x'] + $bbox['w'] / 2) / $imageWidth,
                    'bbox_cy' => ($bbox['y'] + $bbox['h'] / 2) / $imageHeight,
                    'bbox_w'  => $bbox['w'] / $imageWidth,
                    'bbox_h'  => $bbox['h'] / $imageHeight,
                ];

                // [DEBUGGING] Timer untuk ekstraksi fitur
                $featureStartTime = microtime(true);
                $featuresL0 = $this->featureExtractor->extractFeaturesFromAnnotation($s3Path, $tempAnnotation);
                $featureDuration = round(microtime(true) - $featureStartTime, 4);

                if (!$featuresL0) {
                    Log::warning("Gagal ekstrak fitur untuk BBox #{$i}. Durasi coba: {$featureDuration} detik.", ['bbox' => $bbox]);
                    $allClassificationResults[] = ['bbox' => $bbox, 'classification' => 'error', 'error_message' => 'Gagal Ekstraksi Fitur', 'confidence_scores' => []];
                    continue;
                }
                Log::info("[TIMER] BBox #{$i}: Ekstraksi fitur selesai. Durasi: {$featureDuration} detik.");

                // [DEBUGGING] Timer untuk klasifikasi
                $predictStartTime = microtime(true);
                $classificationResult = $this->runEnsemblePrediction($featuresL0);
                $predictDuration = round(microtime(true) - $predictStartTime, 4);

                if ($classificationResult) {
                    $allClassificationResults[] = ['bbox' => $bbox, 'classification' => $classificationResult['prediction'], 'confidence_scores' => $classificationResult['probabilities']];
                    Log::info("[TIMER] BBox #{$i}: Klasifikasi selesai. Durasi: {$predictDuration} detik.");
                } else {
                    $allClassificationResults[] = ['bbox' => $bbox, 'classification' => 'error', 'error_message' => 'Gagal Klasifikasi', 'confidence_scores' => []];
                    Log::warning("Gagal klasifikasi untuk BBox #{$i}. Durasi coba: {$predictDuration} detik.");
                }
                $bboxTotalDuration = round(microtime(true) - $bboxStartTime, 2);
                Log::info("[TIMER] BBox #{$i}: Total proses BBox ini selesai. Durasi: {$bboxTotalDuration} detik.");
            }

            $loopEndTime = microtime(true);
            $loopDuration = round($loopEndTime - $loopStartTime, 2);
            Log::info("[TIMER] Selesai loop. Durasi total loop: {$loopDuration} detik.");

            $response['success'] = true;
            $response['message'] = count($allClassificationResults) . ' objek berhasil dideteksi dan diklasifikasi.';
            $response['all_results'] = $allClassificationResults;
        } catch (Throwable $e) {
            Log::error('Error selama pipeline klasifikasi.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $response['message'] = 'Terjadi kesalahan sistem saat klasifikasi: ' . $e->getMessage();
        } finally {
            $pipelineEndTime = microtime(true);
            $pipelineDuration = round($pipelineEndTime - $pipelineStartTime, 2);
            Log::info("[TIMER] Pipeline selesai. Durasi total request: {$pipelineDuration} detik.");
            return $response;
        }
    }

    private function runEnsemblePrediction(array $featuresL0): ?array
    {
        $baseModelKeys = ModelService::BASE_MODEL_KEYS;
        $metaFeatures = [];
        $unlabeledDatasetL0 = new Unlabeled([$featuresL0]);
        foreach ($baseModelKeys as $key) {
            $model = $this->modelService->loadModel($key);
            $scaler = $this->modelService->loadModel("{$key}_scaler");
            if (!$model instanceof Probabilistic || !$scaler instanceof Transformer) return null;
            $scaledDataset = clone $unlabeledDatasetL0;
            $scaledDataset->apply($scaler);
            $metaFeatures[] = $model->proba($scaledDataset)[0];
        }
        $firstModelMeta = $this->modelService->loadModelMetadata($baseModelKeys[0]);
        $classLabels = $firstModelMeta['classes'] ?? ['non_melon', 'ripe', 'unripe'];
        sort($classLabels);
        $finalMetaFeatureRow = [];
        foreach ($metaFeatures as $probSet) {
            foreach ($classLabels as $label) {
                $finalMetaFeatureRow[] = $probSet[$label] ?? 0.0;
            }
        }
        $unlabeledMetaDataset = new Unlabeled([$finalMetaFeatureRow]);
        $metaLearner = $this->modelService->loadModel('stacking_meta_learner');
        $metaScaler = $this->modelService->loadModel('stacking_meta_learner_scaler');
        if (!$metaLearner instanceof Probabilistic || !$metaScaler instanceof Transformer) return null;
        $unlabeledMetaDataset->apply($metaScaler);
        return [
            'prediction' => $metaLearner->predict($unlabeledMetaDataset)[0],
            'probabilities' => $metaLearner->proba($unlabeledMetaDataset)[0],
        ];
    }

    public function runPythonBboxEstimator(string $s3ImagePath): ?array
    {
        $localTempImage = null;
        try {
            if (!Storage::disk('s3')->exists($s3ImagePath)) {
                throw new RuntimeException('File gambar sumber tidak ditemukan di S3.');
            }
            $imageContent = Storage::disk('s3')->get($s3ImagePath);
            $localTempImage = tempnam(sys_get_temp_dir(), "bbox_py_") . '.jpg';
            file_put_contents($localTempImage, $imageContent);

            $pythonExecutable = config('app.python_executable_path', env('PYTHON_EXECUTABLE_PATH', 'python3'));

            $commandString = sprintf(
                '%s %s %s %s',
                escapeshellarg($pythonExecutable),
                escapeshellarg(base_path('scripts/estimate_bbox.py')),
                escapeshellarg(base_path()),
                escapeshellarg($localTempImage)
            );
            Log::debug('Executing shell command for python', ['command' => $commandString]);

            // Timeout tetap 300 detik untuk mengakomodasi model berat jika diperlukan
            $process = Process::fromShellCommandline($commandString, null, null, null, 300.0);
            $process->run();

            $outputRaw = $process->getOutput();
            $errorRaw = $process->getErrorOutput();
            $output = json_decode($outputRaw, true);

            // [PERBAIKAN] Logika pengecekan yang lebih ketat
            if ($process->isSuccessful() && is_array($output) && isset($output['success'])) {
                // Jika skrip Python sendiri melaporkan kegagalan, log sebagai warning
                if ($output['success'] === false) {
                    Log::warning('Skrip Python melaporkan kegagalan.', [
                        'message_from_script' => $output['message'] ?? 'Tidak ada pesan.',
                        's3_path' => $s3ImagePath,
                        'stderr' => $errorRaw,
                    ]);
                    return null; // Kembalikan null karena gagal
                }

                // Jika sukses, kembalikan bboxes
                if (isset($output['bboxes']) && is_array($output['bboxes'])) {
                    return $output['bboxes'];
                }
            }

            // Jika sampai di sini, berarti ada masalah serius (output bukan JSON, proses crash, dll)
            Log::error('Gagal total menjalankan atau mem-parsing output skrip Python Bbox.', [
                's3_path' => $s3ImagePath,
                'exit_code' => $process->getExitCode(),
                'raw_output' => $outputRaw,
                'stderr' => $errorRaw,
            ]);
            return null;
        } catch (Throwable $e) {
            Log::error('Exception saat menjalankan skrip Python Bbox.', ['s3_path' => $s3ImagePath, 'error' => $e->getMessage()]);
            return null;
        } finally {
            if (isset($localTempImage) && file_exists($localTempImage)) {
                @unlink($localTempImage);
            }
        }
    }
}
