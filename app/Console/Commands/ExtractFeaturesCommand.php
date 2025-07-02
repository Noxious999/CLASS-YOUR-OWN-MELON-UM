<?php

namespace App\Console\Commands;

use App\Services\AnnotationService;
use App\Services\DatasetService;
use App\Services\FeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SplFileObject;
use Throwable;

class ExtractFeaturesCommand extends Command
{
    // Perbarui signature untuk menerima flag --incremental
    protected $signature = 'dataset:extract-features {--set=all} {--incremental}';
    protected $description = 'Extracts unified features (ripe, unripe, non_melon) from annotated images.';

    public function __construct(protected FeatureExtractionService $featureExtractor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $totalStartTime = microtime(true);
        $isIncremental = $this->option('incremental');
        $mode = $isIncremental ? "Incremental (Tambah)" : "Overwrite (Timpa Total)";
        $this->line("Memulai Ekstraksi Fitur Terpadu (Mode: {$mode})...");

        $setsToProcess = $this->option('set') === 'all' ? DatasetService::DATASET_SETS : [$this->option('set')];
        $totalErrors = 0;

        foreach ($setsToProcess as $set) {
            $this->info("\n--- Memproses Set: {$set} ---");
            if (!$this->processSet($set, $isIncremental)) {
                $totalErrors++;
            }
        }

        $totalDuration = round(microtime(true) - $totalStartTime, 2);
        $this->info("\n✅ Ekstraksi Fitur Terpadu Selesai! Total waktu: {$totalDuration} detik.");
        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processSet(string $set, bool $isIncremental): bool
    {
        $setStartTime = microtime(true);
        $s3AnnotationCsvPath = AnnotationService::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
        $s3FeatureCsvPath = FeatureExtractionService::S3_FEATURE_DIR . '/' . $set . '_features.csv';
        $disk = Storage::disk('s3');

        if (!$disk->exists($s3AnnotationCsvPath)) {
            $this->warn("File anotasi untuk set '{$set}' tidak ditemukan. Dilewati.");
            return true;
        }

        $allAnnotations = $this->loadAllAnnotationRows($s3AnnotationCsvPath);
        if (empty($allAnnotations)) {
            $this->warn("Tidak ada data anotasi valid di {$s3AnnotationCsvPath}. Dilewati.");
            return true;
        }

        $existingFeatureIds = [];
        $header = ['id', 'label', ...FeatureExtractionService::UNIFIED_FEATURE_NAMES];

        // Logika untuk mode incremental
        if ($isIncremental && $disk->exists($s3FeatureCsvPath)) {
            $this->info("   Mode incremental: Membaca ID fitur yang sudah ada...");
            $content = $disk->get($s3FeatureCsvPath);
            $lines = explode("\n", trim($content));
            array_shift($lines); // Skip header
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (isset($row[0])) {
                    $existingFeatureIds[$row[0]] = true;
                }
            }
            $this->info("   -> Ditemukan " . count($existingFeatureIds) . " fitur yang sudah ada.");
        }

        $tempLocalPath = tempnam(sys_get_temp_dir(), "unified_feat_");
        // Buka file dalam mode 'append' jika incremental, 'write' jika overwrite
        $fileMode = $isIncremental && !empty($existingFeatureIds) ? 'a' : 'w';
        $outputHandle = new SplFileObject($tempLocalPath, $fileMode);

        // Hanya tulis header jika file baru (mode 'w')
        if ($fileMode === 'w') {
            $outputHandle->fputcsv($header);
        }

        $annotationsToProcess = array_filter($allAnnotations, function ($annotation, $index) use ($existingFeatureIds) {
            $baseFilename = pathinfo($annotation['filename'], PATHINFO_FILENAME);
            $label = ($annotation['detection_class'] === 'non_melon') ? 'non_melon' : $annotation['ripeness_class'];
            $annotationId = ($label === 'non_melon') ? $baseFilename : "{$baseFilename}_bbox" . ($index + 1);
            return !isset($existingFeatureIds[$annotationId]);
        }, ARRAY_FILTER_USE_BOTH);

        if ($isIncremental && empty($annotationsToProcess)) {
            $this->info("   Tidak ada anotasi baru untuk diproses di set '{$set}'.");
            @unlink($tempLocalPath);
            return true;
        }

        $bar = $this->output->createProgressBar(count($annotationsToProcess));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
        $bar->start();
        $writtenCount = 0;

        foreach ($annotationsToProcess as $index => $annotation) {
            $bar->setMessage("Memproses: " . $annotation['filename']);

            try {
                $label = ($annotation['detection_class'] === 'non_melon') ? 'non_melon' : $annotation['ripeness_class'];
                if (empty($label)) continue;

                $baseFilename = pathinfo($annotation['filename'], PATHINFO_FILENAME);
                $annotationId = ($label === 'non_melon') ? $baseFilename : "{$baseFilename}_bbox" . ($index + 1);

                // Cek sekali lagi (untuk kasus file baru)
                if (isset($existingFeatureIds[$annotationId])) {
                    $bar->advance();
                    continue;
                }

                $s3ImagePath = DatasetService::S3_DATASET_BASE_DIR . '/' . $annotation['filename'];
                $features = $this->featureExtractor->extractFeaturesFromAnnotation($s3ImagePath, $annotation);

                if ($features) {
                    $row = array_merge([$annotationId, $label], $features);
                    $outputHandle->fputcsv($row);
                    $writtenCount++;
                }
            } catch (Throwable $e) {
                Log::error("Gagal proses fitur untuk {$annotation['filename']}", ['error' => $e->getMessage()]);
            }
            $bar->advance();
        }
        $bar->finish();

        // Upload/append ke S3
        if ($writtenCount > 0) {
            if ($fileMode === 'a') {
                $disk->append($s3FeatureCsvPath, file_get_contents($tempLocalPath));
            } else {
                $disk->put($s3FeatureCsvPath, file_get_contents($tempLocalPath));
            }
        }
        @unlink($tempLocalPath);

        $setDuration = round(microtime(true) - $setStartTime, 2);
        $this->info("\n ✓ Selesai. {$writtenCount} baris fitur baru untuk set '{$set}' disimpan. Waktu: {$setDuration} detik.");
        return true;
    }

    private function loadAllAnnotationRows(string $s3CsvPath): array
    {
        $content = Storage::disk('s3')->get($s3CsvPath);
        if (!$content) return [];

        $lines = explode("\n", trim($content));
        $headerLine = array_shift($lines);
        if (empty($headerLine)) return [];

        $header = str_getcsv($headerLine);
        $annotations = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $row = str_getcsv($line);
            if (count($row) !== count($header)) continue;

            $rowData = @array_combine($header, $row);
            if (!$rowData) continue;

            // [PERUBAHAN] Hanya proses baris yang merupakan 'melon' dan punya kelas kematangan
            if (($rowData['detection_class'] ?? '') === 'melon' && !empty($rowData['ripeness_class'])) {
                $annotations[] = $rowData;
            }
        }
        return $annotations;
    }
}
