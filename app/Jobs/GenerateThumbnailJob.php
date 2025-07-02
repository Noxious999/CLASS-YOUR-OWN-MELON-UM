<?php

namespace App\Jobs;

use App\Services\DatasetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Properti untuk menyimpan data yang dibutuhkan job
    public function __construct(
        protected string $originalS3Path,
        protected string $targetSet,
        protected string $filename
    ) {}

    // Metode handle() adalah tempat logika utama dijalankan
    public function handle(DatasetService $datasetService): void
    {
        Log::info("GenerateThumbnailJob: Starting for {$this->originalS3Path}");
        $success = $datasetService->generateAndStoreThumbnail(
            $this->originalS3Path,
            $this->targetSet,
            $this->filename
        );

        if ($success) {
            Log::info("GenerateThumbnailJob: Success for {$this->filename}");
        } else {
            Log::warning("GenerateThumbnailJob: Failed for {$this->filename}");
        }
    }
}
