<?php

namespace App\Http\Controllers;

use App\Services\FeedbackService;
use App\Services\DatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache; // <-- PASTIKAN USE STATEMENT INI ADA DI ATAS
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use App\Services\FeedbackLogService; // <-- TAMBAHKAN INI
use App\Services\AnnotationService;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function __construct(
        protected FeedbackService $feedbackService,
        protected AnnotationService $annotationService,
        protected FeedbackLogService $feedbackLogService,
        protected DatasetService $datasetService // <-- TAMBAHKAN INI
    ) {}

    public function handleFeedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            's3_temp_path'      => 'required|string',
            'original_filename' => 'required|string',
            'correct_label'     => ['required', 'string', Rule::in(['ripe', 'unripe', 'correction_needed'])],
        ]);

        // 1. Panggil service untuk memproses feedback (memindah/menghapus file dan mencatat log)
        $feedbackResult = $this->feedbackService->processAndSaveFeedback(
            $data['s3_temp_path'],
            $data['original_filename'],
            $data['correct_label']
        );

        // Jika service gagal, kembalikan error
        if (!$feedbackResult['success']) {
            return response()->json(['success' => false, 'message' => $feedbackResult['message'] ?? 'Gagal memproses feedback.'], 500);
        }

        // 2. Siapkan pesan yang sesuai untuk ditampilkan di frontend
        $feedbackMessage = 'Terima kasih atas masukan Anda!';
        if ($feedbackResult['status'] === 'duplicate_feedback') {
            $feedbackMessage = 'Feedback untuk gambar ini sudah pernah dikirim sebelumnya. Terima kasih!';
        } elseif ($feedbackResult['status'] === 'processed_for_correction') {
            $feedbackMessage = 'Terima kasih! Gambar telah berhasil ditambahkan ke antrian anotasi.';
        } elseif ($feedbackResult['status'] === 'processed_as_correct') {
            $feedbackMessage = 'Terima kasih atas konfirmasinya!';
        }

        // 3. Kirim respons yang sederhana dan jelas. TIDAK ADA LAGI PREDIKSI ULANG.
        return response()->json([
            'success'         => true,
            'message'         => $feedbackMessage, // Hanya pesan konfirmasi feedback
            'status'          => $feedbackResult['status'],
            'new_pending_annotation_count' => count($this->annotationService->getAllUnannotatedFiles()),
        ]);
    }

    public function deleteFeedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'original_filename' => 'required|string',
        ]);

        $filename = $data['original_filename'];

        // Hapus log dari FeedbackLogService
        $logDeleted = $this->feedbackLogService->deleteLog($filename);

        // [PERUBAHAN KUNCI] Panggil method publik yang benar dari DatasetService
        // untuk menghapus file gambar terkait dari direktori dataset/train
        $s3DatasetPath = 'dataset/train/' . $filename;
        $this->datasetService->deleteImageAndAssociatedData($s3DatasetPath);
        Log::info("Aksi hapus feedback juga memicu penghapusan gambar dari dataset.", ['path' => $s3DatasetPath]);

        // ▼▼▼ PERBAIKAN KRUSIAL DI SINI ▼▼▼
        // Bersihkan cache yang relevan setelah data fisik di S3 berubah.
        // Ini akan memperbaiki masalah 'broken image' di halaman anotasi.
        Cache::forget('annotation_service_all_image_files_s3_v4');
        Cache::forget('annotation_service_annotated_files_list_v4');
        Log::info("[FeedbackController] Cache antrian anotasi dibersihkan setelah feedback dihapus.");
        // ▲▲▲ AKHIR PERBAIKAN KRUSIAL ▲▲▲

        // Ambil jumlah antrian anotasi terbaru
        $newPendingCount = count($this->annotationService->getAllUnannotatedFiles());

        if ($logDeleted) {
            return response()->json([
                'success' => true,
                'message' => 'Feedback berhasil dihapus, termasuk data terkait di dataset.',
                'new_pending_annotation_count' => $newPendingCount,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Log feedback tidak ditemukan untuk dihapus (namun pembersihan file dataset tetap dijalankan).',
            'new_pending_annotation_count' => $newPendingCount,
        ], 404);
    }
}
