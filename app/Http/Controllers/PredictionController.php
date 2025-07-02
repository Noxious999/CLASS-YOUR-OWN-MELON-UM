<?php

namespace App\Http\Controllers;

use App\Services\PredictionService;
use App\Services\FeedbackLogService; // <-- TAMBAHKAN INI
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse; // <-- TAMBAHKAN USE STATEMENT INI
use Throwable;

class PredictionController extends Controller
{
    // [PERUBAHAN] Tambahkan $feedbackLogService
    public function __construct(
        protected PredictionService $predictionService,
        protected FeedbackLogService $feedbackLogService
    ) {}

    public function handleImageUpload(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'imageFile' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            ]);

            $imageFile = $validatedData['imageFile'];
            $originalFilename = $imageFile->getClientOriginalName();
            $baseFilename = basename($originalFilename);
            $safeFilename = preg_replace("/[^a-zA-Z0-9._-]/", "", $baseFilename);

            if (empty($safeFilename)) {
                $safeFilename = \Illuminate\Support\Str::uuid()->toString() . '.' . $imageFile->getClientOriginalExtension();
            }

            $s3TempPath = Storage::disk('s3')->putFileAs(
                PredictionService::S3_UPLOAD_DIR_TEMP,
                $imageFile,
                $safeFilename
            );

            if (!$s3TempPath) {
                throw new \RuntimeException('Gagal mengunggah file sementara ke S3.');
            }

            return response()->json([
                'success' => true,
                'message' => 'Gambar berhasil diunggah.',
                'filename' => $originalFilename,
                's3_path' => $s3TempPath,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Input tidak valid: ' . $e->validator->errors()->first()], 422);
        } catch (Throwable $e) {
            Log::error('Error saat handleImageUpload.', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat mengunggah.'], 500);
        }
    }

    private function determineFeedbackStatus(string $filename): array
    {
        // Prioritas 1: Cek apakah file sudah ada di antrian anotasi (dataset/train)
        $s3TrainPath = 'dataset/train/' . $filename;
        if (Storage::disk('s3')->exists($s3TrainPath)) {
            return [
                'feedback_given' => true,
                'feedback_details' => [
                    'feedback_type' => 'correction_needed',
                    'reason' => 'File exists in annotation queue', // Untuk debug
                ],
            ];
        }

        // Prioritas 2: Jika tidak ada di antrian, baru cek log feedback
        $feedbackEntry = $this->feedbackLogService->getLogEntry($filename);
        if ($feedbackEntry) {
            return [
                'feedback_given' => true,
                'feedback_details' => $feedbackEntry,
            ];
        }

        // Jika tidak ada keduanya
        return ['feedback_given' => false, 'feedback_details' => null];
    }

    public function predictFromUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->json()->all(), [
            'filename' => 'required|string',
            's3_path'  => 'required|string',
            'user_provided_bboxes'   => 'nullable|array',
            'user_provided_bboxes.*' => 'required_with:user_provided_bboxes|array',
            'user_provided_bboxes.*.cx' => 'required_with:user_provided_bboxes|numeric|min:0|max:1',
            'user_provided_bboxes.*.cy' => 'required_with:user_provided_bboxes|numeric|min:0|max:1',
            'user_provided_bboxes.*.w'  => 'required_with:user_provided_bboxes|numeric|min:0|max:1',
            'user_provided_bboxes.*.h'  => 'required_with:user_provided_bboxes|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Input tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        $s3ImagePath = $validatedData['s3_path'];
        $originalFilename = $validatedData['filename'];
        $userBboxes = $validatedData['user_provided_bboxes'] ?? null;

        $result = $this->predictionService->classifyImageFromS3($s3ImagePath, $originalFilename, $userBboxes);

        // ▼▼▼ PERUBAHAN DI SINI ▼▼▼
        // Ganti pengecekan feedback dari hasBeenLogged menjadi getLogEntry
        if ($result['success']) {
            $feedbackStatus = $this->determineFeedbackStatus($originalFilename);
            $result = array_merge($result, $feedbackStatus);
        }
        // ▲▲▲ AKHIR PERUBAHAN ▲▲▲

        if (Storage::disk('s3')->exists($s3ImagePath)) {
            $fileContent = Storage::disk('s3')->get($s3ImagePath);
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('s3');
            $mimeType = $disk->mimeType($s3ImagePath) ?: 'image/jpeg';
            $result['image_base64_data'] = 'data:' . $mimeType . ';base64,' . base64_encode($fileContent);
        }

        // ======== TAMBAHKAN DUA BARIS INI ========
        // Pastikan s3_path dan filename selalu ada di root response
        // untuk memudahkan frontend.
        $result['s3_path'] = $s3ImagePath;
        $result['filename'] = $originalFilename;
        // =========================================

        return response()->json($result);
    }

    /**
     * [METODE BARU] Memicu kamera Pi dan men-stream progres kembali ke frontend menggunakan SSE.
     */
    public function triggerPiCameraStream(): StreamedResponse
    {
        $piUrl = config('app.raspberry_pi_url_flask', env('RASPBERRY_PI_URL_FLASK'));

        // Validasi awal URL Pi
        if (!$piUrl) {
            // Kita tidak bisa return JSON biasa, jadi kita stream pesan error dan selesai.
            return new StreamedResponse(function () {
                $this->sendSseEvent('error', ['message' => 'URL Raspberry Pi tidak dikonfigurasi.']);
                $this->sendSseEvent('close', ['message' => 'Connection closed due to error.']);
            });
        }

        // StreamedResponse membutuhkan sebuah callback function
        $response = new StreamedResponse(function () use ($piUrl) {
            // Helper untuk membersihkan output buffer
            ob_end_flush();

            try {
                // 1. Kirim update pertama ke frontend
                $this->sendSseEvent('update', ['message' => 'Menghubungi Raspberry Pi...']);

                // 2. Lakukan request ke Pi (timeout disesuaikan untuk proses capture + upload)
                $piResponse = Http::timeout(60)->post("{$piUrl}/trigger-capture-upload");

                // 3. Setelah Pi merespons, kirim update lagi
                $this->sendSseEvent('update', ['message' => 'Menerima data dari Pi, memulai analisis server...']);

                if (!$piResponse->successful()) {
                    $errorMessage = 'Gagal menghubungi atau mendapat respons sukses dari Raspberry Pi: ' . $piResponse->reason();
                    throw new \RuntimeException($errorMessage);
                }

                $piData = $piResponse->json();
                if (!($piData['success'] ?? false) || !isset($piData['s3_path'], $piData['filename'])) {
                    throw new \RuntimeException('Respons dari Pi tidak valid. Pesan Pi: ' . ($piData['message'] ?? 'Tidak ada pesan.'));
                }

                $s3Path = $piData['s3_path'];
                $filename = $piData['filename'];

                // 4. Jalankan klasifikasi dan kirim update di setiap langkahnya
                Log::info("Gambar dari Pi diterima di S3, memulai klasifikasi via SSE.", ['s3_path' => $s3Path]);

                // Di sini Anda bisa memecah `classifyImageFromS3` jika ingin progress lebih detail
                // Untuk sekarang, kita anggap ini satu langkah besar
                $this->sendSseEvent('update', ['message' => 'Mendeteksi & Mengklasifikasi Melon... (Proses ini mungkin butuh waktu)']);

                $result = $this->predictionService->classifyImageFromS3($s3Path, $filename);

                if ($result['success']) {
                    $feedbackStatus = $this->determineFeedbackStatus($filename);
                    $result = array_merge($result, $feedbackStatus);

                    $imageContent = Storage::disk('s3')->get($s3Path);
                    /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
                    $disk = Storage::disk('s3');
                    $mimeType = $disk->mimeType($s3Path) ?: 'image/jpeg';
                    $result['image_base64_data'] = 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);
                    $result['s3_path'] = $s3Path;
                    $result['filename'] = $filename;
                }

                // 5. Kirim hasil akhir ke frontend
                $this->sendSseEvent('final_result', $result);
            } catch (Throwable $e) {
                Log::error('Gagal total saat proses stream kamera Pi.', ['error' => $e->getMessage()]);
                // Kirim event error ke frontend
                $this->sendSseEvent('error', ['message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
            } finally {
                // 6. Selalu kirim event 'close' untuk memberitahu frontend agar menutup koneksi
                $this->sendSseEvent('close', ['message' => 'Connection closed.']);
            }
        });

        // Set header yang diperlukan untuk SSE
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');
        return $response;
    }

    /**
     * [FUNGSI HELPER BARU] Mengirim data dalam format Server-Sent Event.
     */
    private function sendSseEvent(string $eventName, array $data): void
    {
        echo "event: " . $eventName . "\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
    }
}
