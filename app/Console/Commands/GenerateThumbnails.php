<?php

namespace App\Console\Commands;

use App\Services\DatasetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateThumbnails extends Command
{
    protected $signature = 'dataset:generate-thumbnails {--overwrite}';
    protected $description = 'Generate missing thumbnails for all images in the dataset.';

    public function __construct(protected DatasetService $datasetService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting thumbnail generation...');
        $overwrite = $this->option('overwrite');

        $imageFiles = [];
        foreach (DatasetService::DATASET_SETS as $set) {
            $files = Storage::disk('s3')->files(DatasetService::S3_DATASET_BASE_DIR . '/' . $set);
            foreach ($files as $file) {
                if (Str::is(['*.jpg', '*.jpeg', '*.png', '*.webp'], strtolower($file))) {
                    $imageFiles[] = $file;
                }
            }
        }

        if (empty($imageFiles)) {
            $this->warn('No image files found in dataset directories.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($imageFiles));
        $bar->start();

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($imageFiles as $s3Path) {
            $bar->advance();
            $relativePath = Str::after($s3Path, DatasetService::S3_DATASET_BASE_DIR . '/');
            $parts = explode('/', $relativePath, 2);
            $set = $parts[0];
            $filename = $parts[1];

            $thumbnailS3Path = DatasetService::S3_THUMBNAILS_BASE_DIR . '/' . $set . '/' . $filename;

            if (!$overwrite && Storage::disk('s3')->exists($thumbnailS3Path)) {
                $skipped++;
                continue;
            }

            if ($this->datasetService->generateAndStoreThumbnail($s3Path, $set, $filename)) {
                $generated++;
            } else {
                $failed++;
            }
        }

        $bar->finish();
        $this->info("\nThumbnail generation complete.");
        $this->line("Generated: {$generated}, Skipped: {$skipped}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
