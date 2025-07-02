<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\Versionable;
use App\Persisters\S3ObjectPersister;
use App\Services\EvaluationService;
use App\Services\FeatureExtractionService;
use App\Services\ModelService;
use App\Services\PredictionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionClass;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\CrossValidation\Metrics\Accuracy;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Learner;
use Rubix\ML\Persistable;
use Rubix\ML\Probabilistic;
use Rubix\ML\Transformers\Transformer;
use Rubix\ML\Transformers\ZScaleStandardizer;
use SplFileObject;
use Symfony\Component\Process\Process;
use Throwable;

class TrainUnifiedModel extends Command
{
    use Versionable;

    protected $signature = 'train:melon-model {--with-test : Sertakan evaluasi pada set tes}';
    protected $description = 'Melatih, mengevaluasi, dan melakukan simulasi live pada implementasi manual Stacking Ensemble.';

    protected array $baseModels = [];
    protected array $baseScalers = [];

    /**
     * @var Learner&Probabilistic|null
     */
    protected ?Learner $metaLearner = null;
    protected ?Transformer $metaScaler = null;

    public function __construct(
        protected EvaluationService $evaluator,
        protected ModelService $modelService,
        protected FeatureExtractionService $featureExtractor,
        protected PredictionService $predictionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('memory_limit', '2048M');
        mt_srand(12345);
        $this->line("Memulai Alur Kerja Pelatihan (Implementasi Manual Stacking)...");

        $this->info("\n[INFO] Model akan dilatih menggunakan " . count(FeatureExtractionService::UNIFIED_FEATURE_NAMES) . " fitur terpilih:");
        $this->comment(implode(', ', FeatureExtractionService::UNIFIED_FEATURE_NAMES));

        $this->info("\n[TAHAP 1/7] Memuat Dataset Training, Validasi & Tes...");
        [$trainSamples, $trainLabels] = $this->loadFeaturesFromCsv(FeatureExtractionService::S3_FEATURE_DIR . '/train_features.csv');
        [$validSamples, $validLabels] = $this->loadFeaturesFromCsv(FeatureExtractionService::S3_FEATURE_DIR . '/valid_features.csv');
        [$testSamples, $testLabels] = $this->loadFeaturesFromCsv(FeatureExtractionService::S3_FEATURE_DIR . '/test_features.csv');
        if (empty($trainSamples) || count(array_unique($trainLabels)) < 2) {
            $this->error("Dataset training tidak cukup.");
            return self::FAILURE;
        }
        $trainDataset = Labeled::build($trainSamples, $trainLabels);
        $validDataset = Labeled::build($validSamples, $validLabels);
        $testDataset = Labeled::build($testSamples, $testLabels);
        $this->info("   -> Semua dataset berhasil dimuat.");

        $this->info("\n[TAHAP 2/7] Melatih Model Dasar (Level-0)...");
        $classifiers = $this->getBaseClassifiers();
        $bar = $this->output->createProgressBar(count($classifiers));
        $bar->start();
        foreach ($classifiers as $key => $classifier) {
            $bar->setMessage("Melatih " . Str::title(str_replace('_', ' ', $key)));
            $scaler = new ZScaleStandardizer();
            $scaledTrainDataset = clone $trainDataset;
            $scaledTrainDataset->apply($scaler);
            $classifier->train($scaledTrainDataset);
            $this->baseModels[$key] = $classifier;
            $this->baseScalers[$key] = $scaler;
            (new S3ObjectPersister(ModelService::MODEL_DIR_S3 . "/{$key}.model"))->save($classifier);
            (new S3ObjectPersister(ModelService::MODEL_DIR_S3 . "/{$key}_scaler.model"))->save($scaler);
            $this->saveModelMetadata($classifier, $key, $scaler, $trainDataset, $validDataset, $testDataset);
            $bar->advance();
        }
        $bar->finish();
        $this->info("\n   -> Pelatihan model dasar selesai.");

        $this->info("\n[TAHAP 3/7] Mengevaluasi Model Dasar (Set Validasi)...");
        $allValidationMetrics = [];
        foreach ($this->baseModels as $key => $model) {
            $scaler = $this->baseScalers[$key];
            $scaledValidDataset = clone $validDataset;
            $scaledValidDataset->apply($scaler);
            $predictions = $model->predict($scaledValidDataset);
            $report = $this->buildMetricsReport($validDataset->labels(), $predictions);
            $allValidationMetrics[$key] = $report;
            $this->displayMetrics("Laporan Validasi: " . Str::title(str_replace('_', ' ', $key)), $report);

            // --- [PERBAIKAN 1] TAMBAHKAN KEMBALI PEMBUATAN LEARNING CURVE ---
            $this->generateAndSaveLearningCurve($model, $trainDataset, $validDataset, $key);
            // --- AKHIR PERBAIKAN 1 ---
        }
        Storage::disk('s3')->put(ModelService::MODEL_DIR_S3 . "/all_model_metrics.json", json_encode($allValidationMetrics, JSON_PRETTY_PRINT));
        $this->info("   -> Laporan validasi model dasar disimpan.");

        $this->info("\n[TAHAP 4/7] Membuat Meta-Features...");
        $trainMetaFeatures = $this->getMetaFeatures($trainDataset);
        if ($trainMetaFeatures === null) {
            $this->error("Gagal membuat meta-features.");
            return self::FAILURE;
        }
        $trainMetaDataset = Labeled::build($trainMetaFeatures, $trainDataset->labels());
        $this->info("   -> Meta-Features berhasil dibuat.");

        $this->info("\n[TAHAP 5/7] Melatih dan Memvalidasi Meta-Model (Level-1)...");
        $this->metaLearner = new RandomForest(new ClassificationTree(5), 50, 0.2, true);
        $metaLearnerKey = 'stacking_meta_learner';
        $metaScalerKey = 'stacking_meta_learner_scaler';
        $this->metaScaler = new ZScaleStandardizer();
        $scaledTrainMetaDataset = clone $trainMetaDataset;
        $scaledTrainMetaDataset->apply($this->metaScaler);
        $this->line("   -> Meta-Scaler berhasil di-fit pada data meta training.");
        $this->metaLearner->train($scaledTrainMetaDataset);
        $this->line("   -> Meta-Model berhasil dilatih pada data meta yang sudah di-scaling.");
        assert($this->metaLearner instanceof Persistable, 'Linter hint: Meta-learner must be Persistable');
        (new S3ObjectPersister(ModelService::MODEL_DIR_S3 . "/{$metaLearnerKey}.model"))->save($this->metaLearner);

        assert($this->metaScaler instanceof Persistable, 'Linter hint: Meta-scaler must be Persistable');
        (new S3ObjectPersister(ModelService::MODEL_DIR_S3 . "/{$metaScalerKey}.model"))->save($this->metaScaler);

        $this->line("   -> Meta-Model dan Meta-Scaler berhasil disimpan.");
        $this->saveStackingMetadata($this->metaLearner, $metaLearnerKey, $trainDataset->numSamples(), $validDataset, $testDataset);

        $validMetaFeatures = $this->getMetaFeatures($validDataset);
        if ($validMetaFeatures === null) {
            $this->error("Gagal membuat meta-features validasi.");
            return self::FAILURE;
        }
        $validMetaDataset = Labeled::build($validMetaFeatures, $validDataset->labels());
        $validMetaDataset->apply($this->metaScaler);
        $predictions = $this->metaLearner->predict($validMetaDataset);
        $validationReport = $this->buildMetricsReport($validDataset->labels(), $predictions);

        // --- [PERBAIKAN 2] TAMBAHKAN KEMBALI PENYIMPANAN LAPORAN VALIDASI ENSEMBLE ---
        Storage::disk('s3')->put(
            ModelService::MODEL_DIR_S3 . "/{$metaLearnerKey}_validation_metrics.json",
            json_encode($validationReport, JSON_PRETTY_PRINT)
        );
        // --- AKHIR PERBAIKAN 2 ---

        $this->displayMetrics("Laporan Validasi: Stacking Ensemble", $validationReport);
        $this->info("   -> Meta-Model berhasil divalidasi dan laporannya disimpan.");

        if ($this->option('with-test')) {
            $this->info("\n[TAHAP 6/7] Evaluasi Final pada Set Tes...");
            $this->evaluateOnTestSet($testDataset, $validDataset);
        }

        $this->info("\n[TAHAP 7/7] Uji Coba Simulasi Pipeline Lengkap pada Sampel Data Tes...");
        $this->runLiveTestSimulation();

        $this->modelService->clearEvaluationCache();
        $this->info("\n✅ Semua proses Stacking Ensemble selesai!");
        return self::SUCCESS;
    }

    private function evaluateOnTestSet(Labeled $testDataset, Labeled $validDataset)
    {
        // (Logika fungsi ini tidak berubah, hanya panggilannya yang diperbaiki)
        $this->line("\n -> Mengevaluasi Base Models pada Test Set...");
        $allTestMetrics = [];
        foreach ($this->baseModels as $key => $model) {
            $scaler = $this->baseScalers[$key];
            $scaledTestDataset = clone $testDataset;
            $scaledTestDataset->apply($scaler);
            $predictions = $model->predict($scaledTestDataset);
            $report = $this->buildMetricsReport($testDataset->labels(), $predictions);
            $allTestMetrics[$key] = $report;
            $this->displayMetrics("Laporan Tes: " . Str::title(str_replace('_', ' ', $key)), $report);
        }
        Storage::disk('s3')->put(ModelService::MODEL_DIR_S3 . "/all_model_test_results.json", json_encode($allTestMetrics, JSON_PRETTY_PRINT));

        $this->line("\n -> Mengevaluasi Stacking Ensemble pada Test Set...");
        $testMetaFeatures = $this->getMetaFeatures($testDataset);
        $testMetaDataset = Labeled::build($testMetaFeatures, $testDataset->labels());
        $testMetaDataset->apply($this->metaScaler); // [FIX V3.0] Terapkan scaler ke data tes meta
        $predictions = $this->metaLearner->predict($testMetaDataset);
        $testReport = $this->buildMetricsReport($testDataset->labels(), $predictions);
        $this->displayMetrics("Laporan Tes: Stacking Ensemble", $testReport);
        Storage::disk('s3')->put(ModelService::MODEL_DIR_S3 . "/stacking_ensemble_test_results.json", json_encode($testReport, JSON_PRETTY_PRINT));
    }

    // --- [BARU] Fungsi untuk simulasi live ---
    private function runLiveTestSimulation(int $sampleCount = 3): void
    {
        $testFiles = Storage::disk('s3')->files('dataset/test');
        if (empty($testFiles)) {
            $this->warn('   -> Tidak ada file di direktori tes untuk disimulasikan.');
            return;
        }

        $samples = count($testFiles) > $sampleCount ? array_rand($testFiles, $sampleCount) : array_keys($testFiles);
        if (!is_array($samples)) $samples = [$samples];

        foreach ($samples as $sampleKey) {
            $s3Path = $testFiles[$sampleKey];
            $this->line("\n----------------------------------------------------");
            $this->info("  Simulasi untuk: " . basename($s3Path));
            $this->line("----------------------------------------------------");

            $this->comment("   [1] Menjalankan deteksi BBox via estimate_bbox.py...");

            // Memanggil method yang sudah kita perbaiki. Sekarang ini akan mengembalikan array BBox atau null.
            $bboxes = $this->predictionService->runPythonBboxEstimator($s3Path);

            if (is_null($bboxes)) {
                $this->error("   -> Gagal menjalankan skrip deteksi BBox atau output tidak valid. Periksa log.");
                continue;
            }

            if (empty($bboxes)) {
                $this->warn("   -> Tidak ada BBox terdeteksi oleh YOLO.");
                continue;
            }
            $this->info("   -> Terdeteksi " . count($bboxes) . " BBox.");

            try {
                $imageContent = Storage::disk('s3')->get($s3Path);
                if (!$imageContent) {
                    throw new \RuntimeException("Gagal mengambil konten gambar dari S3: {$s3Path}");
                }
                $image = new \Imagick();
                $image->readImageBlob($imageContent);

                $imgW = $image->getImageWidth();
                $imgH = $image->getImageHeight();
                $image->clear();

                if ($imgW === 0 || $imgH === 0) {
                    throw new \RuntimeException("Dimensi gambar tidak valid (0).");
                }

                foreach ($bboxes as $i => $bbox) {
                    $this->comment("\n   [2] Memproses BBox #" . ($i + 1));

                    // Validasi sekali lagi untuk keamanan, mencegah TypeError
                    if (!is_numeric($bbox['x'] ?? null) || !is_numeric($bbox['y'] ?? null) || !is_numeric($bbox['w'] ?? null) || !is_numeric($bbox['h'] ?? null)) {
                        $this->warn("   -> Format BBox tidak valid. Dilewati.");
                        continue;
                    }

                    $tempAnnotation = [
                        'detection_class' => 'melon', // Di simulasi, kita selalu anggap 'melon'
                        'bbox_cx' => ((float)$bbox['x'] + (float)$bbox['w'] / 2) / $imgW,
                        'bbox_cy' => ((float)$bbox['y'] + (float)$bbox['h'] / 2) / $imgH,
                        'bbox_w'  => (float)$bbox['w'] / $imgW,
                        'bbox_h'  => (float)$bbox['h'] / $imgH,
                    ];

                    $features = $this->featureExtractor->extractFeaturesFromAnnotation($s3Path, $tempAnnotation);
                    if (!$features) {
                        $this->warn("   -> Gagal ekstrak fitur untuk BBox ini.");
                        continue;
                    }
                    $this->info("   -> Fitur berhasil diekstrak.");

                    $prediction = $this->runEnsemblePrediction($features);
                    if ($prediction) {
                        $this->info("   -> Prediksi Ensemble: " . strtoupper($prediction['prediction']));
                        $this->table(['Kelas', 'Skor Kepercayaan'], collect($prediction['probabilities'])->map(fn($score, $class) => [$class, number_format($score * 100, 2) . '%'])->values());
                    } else {
                        $this->warn("   -> Gagal mendapatkan prediksi ensemble.");
                    }
                }
            } catch (Throwable $e) {
                $this->error("   -> Error saat memproses simulasi: " . $e->getMessage());
            }
        }
    }

    /**
     * @param array<int, float> $features
     * @return array{prediction: string, probabilities: array<string, float>}|null
     */
    private function runEnsemblePrediction(array $features): ?array
    {
        try {
            $metaFeatures = [];
            $unlabeledL0 = new Unlabeled([$features]);
            foreach ($this->baseModels as $key => $model) {
                if (!$model instanceof Probabilistic) return null;
                $scaler = $this->baseScalers[$key];
                $scaledDataset = clone $unlabeledL0;
                $scaledDataset->apply($scaler);
                $probabilities = $model->proba($scaledDataset)[0];

                $classLabels = array_keys($probabilities);
                sort($classLabels);
                foreach ($classLabels as $label) {
                    $metaFeatures[] = $probabilities[$label];
                }
            }

            // Definisikan tipe variabel secara eksplisit untuk linter
            /** @var Learner&Probabilistic $metaLearner */
            $metaLearner = $this->metaLearner;

            if (!$metaLearner) {
                Log::error("Meta-learner belum di-train.");
                return null;
            }

            $unlabeledMeta = new Unlabeled([$metaFeatures]);
            $unlabeledMeta->apply($this->metaScaler);

            return [
                'prediction' => $metaLearner->predict($unlabeledMeta)[0],
                'probabilities' => $metaLearner->proba($unlabeledMeta)[0],
            ];
        } catch (Throwable $e) {
            Log::error("Gagal saat runEnsemblePrediction di simulasi", ['error' => $e->getMessage()]);
            return null;
        }
    }

    // --- [BARU] Fungsi untuk menampilkan metrik di terminal ---
    private function displayMetrics(string $title, array $report): void
    {
        $this->line("\n## " . $title . " ##");

        // Tampilkan metrik per kelas
        $metricsTable = [];
        foreach ($report['metrics_per_class'] as $class => $metrics) {
            $metricsTable[] = [
                'Class' => $class,
                'Precision' => number_format($metrics['precision'], 4),
                'Recall' => number_format($metrics['recall'], 4),
                'F1-Score' => number_format($metrics['f1_score'], 4),
            ];
        }
        $this->table(['Kelas', 'Precision', 'Recall', 'F1-Score'], $metricsTable);

        // Tampilkan confusion matrix
        $this->line("Confusion Matrix:");
        $matrix = $report['confusion_matrix'];
        $labels = $report['classes'];
        $headers = array_merge(['Actual ↓ / Predicted →'], $labels);
        $matrixTable = [];
        foreach ($labels as $actualLabel) {
            $row = [$actualLabel];
            foreach ($labels as $predictedLabel) {
                $row[] = $matrix[$actualLabel][$predictedLabel] ?? 0;
            }
            $matrixTable[] = $row;
        }
        $this->table($headers, $matrixTable);
        $this->info("Akurasi Keseluruhan: " . number_format($report['metrics']['accuracy'] * 100, 2) . "%");
    }

    private function generateAndSaveLearningCurve(Learner $model, Labeled $originalTrainDataset, Labeled $originalValidDataset, string $modelKey): void
    {
        $this->line("      -> Menghasilkan Learning Curve untuk {$modelKey}...");
        $numSamples = $originalTrainDataset->numSamples();
        if ($numSamples < 10) {
            $this->warn("         -> Sampel kurang ({$numSamples}) untuk Learning Curve. Dilewati.");
            return;
        }

        // Tentukan persentase ukuran data training yang akan digunakan
        $trainSizesRatios = [0.2, 0.4, 0.6, 0.8, 1.0];
        $trainScores = [];
        $validationScores = [];
        $actualTrainSizes = [];

        foreach ($trainSizesRatios as $ratio) {
            try {
                $subsetSize = (int) round($numSamples * $ratio);
                // Pastikan subset memiliki cukup data dan lebih dari 1 kelas
                if ($subsetSize < 5) continue;

                $trainSubset = $originalTrainDataset->randomize()->head($subsetSize);
                if (count(array_unique($trainSubset->labels())) < 2) continue;

                // Clone model agar tidak mengganggu model utama
                $tempModel = clone $model;

                // Buat dan latih scaler HANYA pada data subset training
                $subsetScaler = new ZScaleStandardizer();
                $subsetScaler->fit($trainSubset);

                // Lakukan scaling pada data
                $scaledTrainSubset = clone $trainSubset;
                $scaledTrainSubset->apply($subsetScaler);
                $scaledValidDataset = clone $originalValidDataset;
                $scaledValidDataset->apply($subsetScaler);

                // Latih model pada subset
                $tempModel->train($scaledTrainSubset);

                // Hitung skor pada data training (subset)
                $trainPreds = $tempModel->predict($scaledTrainSubset);
                $trainScores[] = (new Accuracy())->score($trainPreds, $scaledTrainSubset->labels());
                $actualTrainSizes[] = $trainSubset->numSamples();

                // Hitung skor pada data validasi
                $validPreds = $tempModel->predict($scaledValidDataset);
                $validationScores[] = (new Accuracy())->score($validPreds, $scaledValidDataset->labels());
            } catch (Throwable $e) {
                // Lewati titik data ini jika ada error (misal, data terlalu sedikit)
                Log::warning("Skipping a learning curve point for {$modelKey}", ['ratio' => $ratio, 'error' => $e->getMessage()]);
            }
        }

        // Simpan hasilnya menggunakan ModelService
        $this->modelService->saveLearningCurve($modelKey, [
            'train_sizes' => $actualTrainSizes,
            'train_scores' => $trainScores,
            'test_scores' => $validationScores, // Di JS, ini akan menjadi validation score
        ]);
    }

    /**
     * Mendefinisikan model-model dasar (Level-0).
     */
    private function getBaseClassifiers(): array
    {
        return [
            'k_nearest_neighbors' => new KNearestNeighbors(5, true),
            'random_forest' => new RandomForest(new ClassificationTree(12), 150, 0.1, true),
        ];
    }

    /**
     * Menghasilkan fitur baru (meta-features) dari prediksi model-model dasar.
     */
    private function getMetaFeatures(Labeled $dataset): ?array
    {
        $metaFeatures = [];
        $numSamples = $dataset->numSamples();

        foreach ($this->baseModels as $key => $model) {
            if (!$model instanceof Probabilistic) {
                $this->warn("Model {$key} bukan Probabilistic dan tidak bisa menghasilkan probabilitas. Dilewati.");
                continue;
            }
            $scaler = $this->baseScalers[$key];
            $scaledDataset = clone $dataset;
            $scaledDataset->apply($scaler);
            $probabilities = $model->proba($scaledDataset);
            $predictionsByModel[$key] = $probabilities;
        }
        if (empty($predictionsByModel)) return null;

        $classLabels = $dataset->possibleOutcomes();
        sort($classLabels);
        for ($i = 0; $i < $numSamples; $i++) {
            $sampleMetaFeatures = [];
            foreach (array_keys($this->baseModels) as $key) {
                if (!isset($predictionsByModel[$key])) continue;
                $orderedProbas = [];
                foreach ($classLabels as $label) {
                    $orderedProbas[] = $predictionsByModel[$key][$i][$label] ?? 0.0;
                }
                $sampleMetaFeatures = array_merge($sampleMetaFeatures, $orderedProbas);
            }
            $metaFeatures[] = $sampleMetaFeatures;
        }
        return $metaFeatures;
    }

    /**
     * Mengevaluasi seluruh pipeline Stacking pada data tes.
     */
    private function evaluateStackingOnTestSet(): ?array
    {
        $this->info("   -> Memuat data tes...");
        [$testSamples, $testLabels] = $this->loadFeaturesFromCsv(FeatureExtractionService::S3_FEATURE_DIR . '/test_features.csv');
        if (empty($testSamples)) {
            $this->warn("   -> File fitur tes tidak ditemukan/kosong. Evaluasi tes dilewati.");
            return null;
        }
        $testDataset = Labeled::build($testSamples, $testLabels);

        // Muat kembali semua model, TAPI LANGSUNG MENGGUNAKAN S3ObjectPersister
        $this->info("   -> Memuat semua model yang diperlukan dari S3 (Direct Load)...");
        $this->baseModels = []; // Kosongkan dulu
        $this->baseScalers = [];
        $baseModelKeys = array_keys($this->getBaseClassifiers());

        try {
            foreach ($baseModelKeys as $key) {
                $modelPath = ModelService::MODEL_DIR_S3 . "/{$key}.model";
                $scalerPath = ModelService::MODEL_DIR_S3 . "/{$key}_scaler.model";

                // Memuat model secara langsung
                $model = (new S3ObjectPersister($modelPath))->load();
                // Memuat scaler secara langsung
                $scaler = (new S3ObjectPersister($scalerPath))->load();

                // PENGECEKAN TIPE DATA (FIX)
                if (!$model instanceof Learner || !$scaler instanceof Transformer) {
                    $this->error("   -> Gagal memuat atau tipe data tidak valid untuk model/scaler '{$key}'.");
                    Log::error("Loaded object is not a valid Learner/Transformer", [
                        'key' => $key,
                        'model_class' => is_object($model) ? get_class($model) : 'N/A',
                        'scaler_class' => is_object($scaler) ? get_class($scaler) : 'N/A'
                    ]);
                    return null;
                }

                $this->baseModels[$key] = $model;
                $this->baseScalers[$key] = $scaler;
                $this->line("      -> Model '{$key}' dan scalernya berhasil di-load dan diverifikasi.");
            }

            // Memuat meta-learner secara langsung
            $metaLearnerKey = 'stacking_meta_learner';
            $metaLearnerPath = ModelService::MODEL_DIR_S3 . "/{$metaLearnerKey}.model";
            $metaLearner = (new S3ObjectPersister($metaLearnerPath))->load();

            // PENGECEKAN TIPE DATA (FIX)
            if (!$metaLearner instanceof Learner) {
                $this->error("   -> Meta-learner yang dimuat bukanlah instance dari Learner.");
                Log::error("Loaded meta-learner is not an instance of Learner.", ['class' => is_object($metaLearner) ? get_class($metaLearner) : 'N/A']);
                return null;
            }
            $this->line("      -> Meta-learner '{$metaLearnerKey}' berhasil di-load dan diverifikasi.");
        } catch (Throwable $e) {
            $this->error("   -> Terjadi exception saat direct load: " . $e->getMessage());
            Log::critical("Exception during direct model loading in evaluation", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }

        $this->info("   -> Menghasilkan meta-features untuk data tes...");
        $testMetaFeatures = $this->getMetaFeatures($testDataset);
        $testMetaDataset = Unlabeled::build($testMetaFeatures);

        $this->info("   -> Membuat prediksi akhir menggunakan meta-learner...");
        $predictions = $metaLearner->predict($testMetaDataset); // <-- Error ini sekarang seharusnya hilang

        return $this->buildMetricsReport($testDataset->labels(), $predictions);
    }

    private function saveModelMetadata(Learner $classifier, string $modelKey, ZScaleStandardizer $scaler, Labeled $trainDataset, Labeled $validDataset, Labeled $testDataset): void
    {
        $s3MetaPath = ModelService::MODEL_DIR_S3 . "/{$modelKey}_meta.json";

        $metadata = [
            'model_key' => $modelKey,
            'scaler_key' => "{$modelKey}_scaler",
            'scaler_used_class' => get_class($scaler),
            'task_type' => 'base_model_for_stacking',
            'version' => $this->getNextModelVersion($s3MetaPath),
            'trained_at' => now()->toIso8601String(),
            'training_samples_count' => $trainDataset->numSamples(),
            'validation_samples_count' => $validDataset->numSamples(),
            'test_samples_count' => $testDataset->numSamples(), // BARU
            'num_features_expected' => $trainDataset->numFeatures(),
            'feature_names' => FeatureExtractionService::UNIFIED_FEATURE_NAMES,
            'classes' => $trainDataset->possibleOutcomes(),
            'algorithm_class' => get_class($classifier),
            'hyperparameters' => $this->hyperParametersOf($classifier),
            'rubix_ml_version' => \Rubix\ML\VERSION,
        ];

        Storage::disk('s3')->put($s3MetaPath, json_encode($metadata, JSON_PRETTY_PRINT));
        Cache::forget(ModelService::CACHE_PREFIX . $modelKey . '_meta');
    }

    private function saveStackingMetadata(Learner $metaLearner, string $modelKey, int $trainingSamples, Labeled $validDataset, Labeled $testDataset): void
    {
        $baseModelKeys = array_keys($this->getBaseClassifiers());
        // [PERUBAHAN] Daftar kelas sekarang hanya dua
        $classLabels = ['ripe', 'unripe'];
        sort($classLabels);

        $metaFeatureNames = [];
        foreach ($baseModelKeys as $baseKey) {
            foreach ($classLabels as $label) {
                $metaFeatureNames[] = "{$baseKey}_proba_{$label}";
            }
        }

        $s3MetaPath = ModelService::MODEL_DIR_S3 . "/{$modelKey}_meta.json";
        $metadata = [
            'model_key' => $modelKey,
            'task_type' => 'stacking_ensemble_meta_learner',
            'version' => $this->getNextModelVersion($s3MetaPath),
            'trained_at' => now()->toIso8601String(),
            'training_samples_count' => $trainingSamples,
            'validation_samples_count' => $validDataset->numSamples(), // BARU
            'test_samples_count' => $testDataset->numSamples(),       // BARU
            'base_models_used' => array_keys($this->getBaseClassifiers()),
            'num_features_expected' => count($metaFeatureNames),
            'feature_names' => $metaFeatureNames,
            'classes' => $classLabels,
            'algorithm_class' => get_class($metaLearner),
            'hyperparameters' => $this->hyperParametersOf($metaLearner),
            'rubix_ml_version' => \Rubix\ML\VERSION,
        ];
        Storage::disk('s3')->put($s3MetaPath, json_encode($metadata, JSON_PRETTY_PRINT));
        Cache::forget(ModelService::CACHE_PREFIX . $modelKey . '_meta');
    }

    private function buildMetricsReport(array $trueLabels, array $predictions): array
    {
        $accuracy = (new Accuracy())->score($predictions, $trueLabels);
        $this->line("      -> Akurasi Akhir Ensemble: " . number_format($accuracy * 100, 2) . "%");

        $classes = array_unique(array_merge($trueLabels, $predictions));
        sort($classes);
        $metricsByClass = [];
        foreach ($classes as $class) {
            if (empty($class)) continue;
            [$precision, $recall, $f1] = $this->evaluator->calculateMetrics($trueLabels, $predictions, $class);
            $metricsByClass[$class] = ['precision' => $precision, 'recall' => $recall, 'f1_score' => $f1];
        }

        return [
            'metrics' => ['accuracy' => round($accuracy, 4)],
            'metrics_per_class' => $metricsByClass,
            'confusion_matrix' => $this->evaluator->confusionMatrix($trueLabels, $predictions, $classes),
            'classes' => $classes,
        ];
    }

    private function loadFeaturesFromCsv(string $s3FeatureCsvPath): array
    {
        if (!Storage::disk('s3')->exists($s3FeatureCsvPath)) {
            $this->warn("      File fitur tidak ditemukan di S3: {$s3FeatureCsvPath}");
            return [[], []];
        }

        $samples = [];
        $labels = [];
        $localTempCsvPath = null;

        try {
            $csvS3Content = Storage::disk('s3')->get($s3FeatureCsvPath);
            if ($csvS3Content === null || empty(trim($csvS3Content))) {
                return [[], []];
            }

            $localTempCsvPath = tempnam(sys_get_temp_dir(), "s3_feat_csv_");
            file_put_contents($localTempCsvPath, $csvS3Content);

            $file = new SplFileObject($localTempCsvPath, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

            $header = $file->fgetcsv();
            $expectedFeatureCount = count(FeatureExtractionService::UNIFIED_FEATURE_NAMES);

            // [PERUBAHAN] Definisikan label yang valid hanya 'ripe' dan 'unripe'
            $validLabels = ['ripe', 'unripe'];

            while (!$file->eof()) {
                $row = $file->fgetcsv();
                if ($row && isset($row[1])) {
                    $label = strtolower(trim($row[1]));
                    // Filter hanya untuk label yang valid
                    if (in_array($label, $validLabels)) {
                        $features = array_slice($row, 2);
                        if (count($features) === $expectedFeatureCount) {
                            $samples[] = array_map('floatval', $features);
                            $labels[] = $label;
                        }
                    }
                }
            }
            $file = null;
        } catch (Throwable $e) {
            $this->error("      Error membaca CSV fitur dari {$s3FeatureCsvPath}: " . $e->getMessage());
            Log::error("Error reading feature CSV", ['s3_path' => $s3FeatureCsvPath, 'error' => $e->getMessage()]);
            return [[], []];
        } finally {
            if ($localTempCsvPath && file_exists($localTempCsvPath)) {
                @unlink($localTempCsvPath);
            }
        }
        return [$samples, $labels];
    }

    private function hyperParametersOf(Learner $estimator): array
    {
        $rc = new ReflectionClass($estimator);
        $ctor = $rc->getConstructor();
        $params = [];
        if ($ctor) {
            foreach ($ctor->getParameters() as $arg) {
                $name = $arg->getName();
                if ($rc->hasProperty($name)) {
                    $prop = $rc->getProperty($name);
                    if (!$prop->isPublic()) $prop->setAccessible(true);
                    $value = $prop->getValue($estimator);
                    if (is_object($value)) {
                        $params[$name] = class_basename($value);
                        if (method_exists($value, 'maxDepth')) $params["{$name}_max_depth"] = $value->maxDepth();
                    } else {
                        $params[$name] = $value;
                    }
                }
            }
        }
        return $params;
    }
}
