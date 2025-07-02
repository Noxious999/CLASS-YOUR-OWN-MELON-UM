<?php

namespace App\Console\Commands;

use App\Services\FeatureExtractionService;
use Illuminate\Console\Command;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use SplFileObject;
use Throwable;

class SelectFeaturesCommand extends Command
{
    protected $signature = 'train:select-features';
    protected $description = 'Train a RandomForest model to find and rank the most important features.';

    public function handle(): int
    {
        mt_srand(42); // Angka 42 bisa diganti angka lain, yang penting konsisten
        $this->line("Memulai proses seleksi fitur (dengan random seed)...");

        // 1. Muat data training
        $this->info("\n[1/3] Memuat data fitur training...");
        $s3FeatureCsvPath = FeatureExtractionService::S3_FEATURE_DIR . '/train_features.csv';
        [$samples, $labels] = $this->loadFeaturesFromCsv($s3FeatureCsvPath);

        if (empty($samples)) {
            $this->error("Gagal memuat data training atau data kosong.");
            return self::FAILURE;
        }
        $dataset = Labeled::build($samples, $labels);
        $this->info("   -> " . $dataset->numSamples() . " sampel dimuat.");

        // 2. Latih model RandomForest untuk mendapatkan feature importances
        $this->info("\n[2/3] Melatih model RandomForest sebagai 'juri' fitur...");
        // Kita gunakan konfigurasi yang cukup kuat untuk mendapatkan penilaian yang baik
        $estimator = new RandomForest(null, 100, 0.3, true);

        try {
            $estimator->train($dataset);
            $this->info("   -> Model 'juri' berhasil dilatih.");
        } catch (Throwable $e) {
            $this->error("Gagal melatih model 'juri': " . $e->getMessage());
            return self::FAILURE;
        }

        // 3. Dapatkan, urutkan, dan tampilkan feature importances
        $this->info("\n[3/3] Menganalisis dan menampilkan peringkat fitur...");
        $importances = $estimator->featureImportances();
        $featureNames = FeatureExtractionService::UNIFIED_FEATURE_NAMES;

        // Gabungkan nama fitur dengan nilainya
        $rankedFeatures = array_combine($featureNames, $importances);
        if ($rankedFeatures === false) {
            $this->error("Gagal menggabungkan nama fitur dan nilai kepentingan.");
            return self::FAILURE;
        }

        // Urutkan dari yang paling penting ke yang paling tidak penting
        arsort($rankedFeatures);

        $this->line("\n==================================================");
        $this->line("    Peringkat Kepentingan Fitur (Top 25)");
        $this->line("==================================================");
        $this->table(
            ['Peringkat', 'Nama Fitur', 'Skor Kepentingan'],
            collect($rankedFeatures)->map(function ($score, $name) {
                return ['name' => $name, 'score' => number_format($score, 5)];
            })->take(25)->values()->map(function ($item, $key) {
                return [$key + 1, $item['name'], $item['score']];
            })
        );
        $this->line("==================================================");

        $this->info("\n✅ Proses seleksi fitur selesai.");
        $this->warn("Langkah selanjutnya: Pilih fitur-fitur teratas dari daftar ini dan modifikasi skrip training untuk hanya menggunakan fitur-fitur tersebut.");

        return self::SUCCESS;
    }

    private function loadFeaturesFromCsv(string $s3FeatureCsvPath): array
    {
        // Fungsi ini disalin dari TrainUnifiedModel untuk konsistensi
        if (!\Illuminate\Support\Facades\Storage::disk('s3')->exists($s3FeatureCsvPath)) {
            $this->warn("      File fitur tidak ditemukan di S3: {$s3FeatureCsvPath}");
            return [[], []];
        }
        $samples = [];
        $labels = [];
        $localTempCsvPath = null;
        try {
            $csvS3Content = \Illuminate\Support\Facades\Storage::disk('s3')->get($s3FeatureCsvPath);
            if ($csvS3Content === null || empty(trim($csvS3Content))) return [[], []];
            $localTempCsvPath = tempnam(sys_get_temp_dir(), "s3_feat_csv_");
            file_put_contents($localTempCsvPath, $csvS3Content);
            $file = new SplFileObject($localTempCsvPath, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
            $header = $file->fgetcsv();
            $expectedFeatureCount = count(FeatureExtractionService::UNIFIED_FEATURE_NAMES);
            $validLabels = ['ripe', 'unripe'];
            while (!$file->eof()) {
                $row = $file->fgetcsv();
                if ($row && isset($row[1])) {
                    $label = strtolower(trim($row[1]));
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
        } finally {
            if ($localTempCsvPath && file_exists($localTempCsvPath)) @unlink($localTempCsvPath);
        }
        return [$samples, $labels];
    }
}
