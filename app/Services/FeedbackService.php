<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FeedbackService
{
    protected DatasetChangeService $datasetChangeService;
    protected DatasetService $datasetService;
    // Tambahkan properti untuk service log baru
    protected FeedbackLogService $feedbackLogService;

    // Injeksi FeedbackLogService melalui constructor
    public function __construct(
        DatasetChangeService $datasetChangeService,
        DatasetService $datasetService,
        FeedbackLogService $feedbackLogService
    ) {
        $this->datasetChangeService = $datasetChangeService;
        $this->datasetService = $datasetService;
        $this->feedbackLogService = $feedbackLogService; // Inisialisasi properti
    }

    /**
     * Memproses feedback.
     * Jika 'correction_needed', pindahkan gambar ke set 'train'.
     * Jika 'ripe' atau 'unripe', catat feedback dan hapus file temp.
     * @return array{success: bool, status: string, message?: string, new_s3_path?: string|null}
     */
    public function processAndSaveFeedback(string $s3TempPath, string $originalFilename, string $correctLabel): array
    {
        // 1. Periksa duplikasi feedback, ini tidak berubah.
        if ($this->feedbackLogService->hasBeenLogged($originalFilename)) {
            return ['success' => true, 'status' => 'duplicate_feedback', 'new_s3_path' => null];
        }

        try {
            // Pengecekan penting untuk memastikan file temporer ada sebelum aksi apapun.
            if (!Storage::disk('s3')->exists($s3TempPath)) {
                Log::error("[FeedbackService] Gagal memulai: File sumber sementara tidak ditemukan di S3.", ['path' => $s3TempPath]);
                // Error ini akan ditangkap oleh frontend jika terjadi masalah tak terduga
                throw new \RuntimeException("File sumber sementara tidak ditemukan di S3: {$s3TempPath}");
            }
            Log::info("[FeedbackService] File sumber ditemukan.", ['path' => $s3TempPath]);

            if ($correctLabel === 'correction_needed') {
                Log::info("[FeedbackService] Memulai alur 'correction_needed' untuk: {$originalFilename}");
                // --- KASUS: "TIDAK, PERLU KOREKSI" ---
                $targetSet = 'train';
                $s3DestImagePath = rtrim(DatasetService::S3_DATASET_BASE_DIR, '/') . '/' . $targetSet . '/' . $originalFilename;
                $s3DestImagePath = preg_replace('#/+#', '/', $s3DestImagePath);

                Log::info("[FeedbackService] Mencoba menyalin file...", ['from' => $s3TempPath, 'to' => $s3DestImagePath]);

                // [PERUBAHAN KUNCI] Kita bungkus operasi copy dengan if untuk logging yang lebih baik
                $copySuccess = Storage::disk('s3')->copy($s3TempPath, $s3DestImagePath);

                if ($copySuccess) {
                    Log::info("[FeedbackService] SUKSES menyalin file ke direktori dataset.");

                    // !!! ===== PERBAIKAN UTAMA DI SINI ===== !!!
                    // Bersihkan cache daftar file agar gambar baru ini segera terdeteksi di antrian anotasi.
                    Cache::forget('annotation_service_all_image_files_s3_v4');
                    Log::info("[FeedbackService] Cache 'annotation_service_all_image_files_s3_v4' dibersihkan setelah file baru ditambahkan ke dataset.");
                    // !!! ===== AKHIR PERBAIKAN ===== !!!
                } else {
                    Log::error("[FeedbackService] GAGAL menyalin file ke direktori dataset.", ['from' => $s3TempPath, 'to' => $s3DestImagePath]);
                    throw new \RuntimeException("Gagal MENYALIN file ke direktori dataset.");
                }

                // Sisa logika untuk membuat thumbnail dan mencatat log tetap sama.
                $this->datasetService->generateAndStoreThumbnail($s3DestImagePath, $targetSet, $originalFilename);
                Log::info("[FeedbackService] Thumbnail berhasil diproses.");
                $imagePathIdentifierInCsv = $targetSet . '/' . $originalFilename;
                app(DatasetService::class)->removeImageAnnotationsFromAllSets($imagePathIdentifierInCsv);
                Log::info("[FeedbackService] Anotasi lama (jika ada) berhasil dihapus.");
                $this->datasetChangeService->recordChange('feedback_to_annotation_queue', $originalFilename, 1, ['suggestion' => $correctLabel]);
                $this->feedbackLogService->log($originalFilename, 'correction_needed');
                Log::info("[FeedbackService] Log feedback dan perubahan dataset berhasil dicatat.");

                return ['success' => true, 'status' => 'processed_for_correction', 'new_s3_path' => $s3DestImagePath];
            } else {
                // --- KASUS: "YA, SESUAI" ---
                // [PERUBAHAN KUNCI] TIDAK ADA LAGI AKSI PADA FILE. HANYA MENCATAT.
                // Storage::disk('s3')->delete($s3TempPath); // <-- BARIS INI DIHAPUS SEPERTI PERMINTAAN ANDA.

                $this->datasetChangeService->recordChange('feedback_prediction_confirmed', $originalFilename, 1, ['confirmed_label' => $correctLabel]);
                $this->feedbackLogService->log($originalFilename, 'confirmed');

                return ['success' => true, 'status' => 'processed_as_correct', 'new_s3_path' => null];
            }
        } catch (Throwable $e) {
            Log::error("Gagal total memproses feedback untuk '{$originalFilename}'", ['error' => $e->getMessage(), 's3_temp_path' => $s3TempPath, 'trace' => $e->getTraceAsString()]);
            return ['success' => false, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
