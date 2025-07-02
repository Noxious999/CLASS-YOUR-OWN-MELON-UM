<?php

namespace App\Http\Controllers;

use App\Services\AnnotationService;
use App\Services\DatasetChangeService;
use App\Services\DatasetService;
use App\Services\PredictionService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class AnnotationController extends Controller
{
    public function __construct(
        protected AnnotationService $annotationService,
        protected PredictionService $predictionService,
        protected DatasetChangeService $datasetChangeService,
        protected DatasetService $datasetService
    ) {}

    public function index(Request $request): ViewContract | JsonResponse
    {
        // Jika ada permintaan AJAX, ini mungkin dari fungsionalitas lama.
        // Dengan arsitektur baru, ini tidak lagi relevan.
        if ($request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint AJAX ini tidak lagi digunakan.'
            ], 404);
        }

        // 1. Ambil SEMUA file yang belum teranotasi
        $unannotatedFilesData = $this->annotationService->getAllUnannotatedFiles();
        $galleryImages = [];

        foreach ($unannotatedFilesData as $s3PathKey => $fileDetails) {
            if (!is_array($fileDetails) || !isset($fileDetails['s3Path'], $fileDetails['filename'], $fileDetails['set'])) {
                continue;
            }

            // --- PERUBAHAN UTAMA: Hanya kirim path, BUKAN URL ---
            $thumbnailS3Path = DatasetService::S3_THUMBNAILS_BASE_DIR . '/' . $fileDetails['set'] . '/' . $fileDetails['filename'];
            $thumbnailS3Path = preg_replace('#/+#', '/', $thumbnailS3Path);

            $galleryImages[] = [
                's3Path'          => $fileDetails['s3Path'],
                'thumbnailS3Path' => $thumbnailS3Path, // Kirim path thumbnail
                'imagePathForCsv' => $fileDetails['set'] . '/' . $fileDetails['filename'],
                'imageUrl'        => null, // Akan diisi oleh frontend
                'thumbnailUrl'    => null, // Akan diisi oleh frontend
                'filename'        => $fileDetails['filename'],
                'set'             => $fileDetails['set'],
            ];
        }

        $dataForView = [
            'allUnannotatedImagesJson' => json_encode(array_values($galleryImages)),
        ];

        return view('annotate', $dataForView);
    }

    public function getTemporaryUrls(Request $request): JsonResponse
    {
        $paths = $request->input('paths', []);
        if (empty($paths)) {
            return response()->json(['urls' => []]);
        }

        /** @var \Illuminate\Filesystem\AwsS3V3Adapter $diskS3 */
        $diskS3 = Storage::disk('s3');
        $urlMap = [];

        foreach ($paths as $s3Path) {
            if ($diskS3->exists($s3Path)) {
                $urlMap[$s3Path] = $diskS3->temporaryUrl($s3Path, now()->addMinutes(20));
            } else {
                $urlMap[$s3Path] = null;
            }
        }

        return response()->json(['urls' => $urlMap]);
    }

    public function save(Request $request): JsonResponse
    {
        // [PERUBAHAN] Validasi disederhanakan
        $validator = Validator::make($request->all(), [
            'image_path'       => 'required|string',
            'dataset_set'      => 'required|string|in:' . implode(',', DatasetService::DATASET_SETS),
            'annotations_json' => 'required|json', // Sekarang wajib
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        $imagePathForCsv     = $validated['image_path'];
        $s3ImagePathFull     = preg_replace('#/+#', '/', DatasetService::S3_DATASET_BASE_DIR . '/' . $imagePathForCsv);
        $s3AnnotationCsvPath = AnnotationService::S3_ANNOTATION_DIR . '/' . $validated['dataset_set'] . '_annotations.csv';

        $allAnnotationRowsForCsv = [];
        $annotationsFromJson = json_decode($validated['annotations_json'], true);

        if (empty($annotationsFromJson) || !is_array($annotationsFromJson)) {
            return response()->json(['success' => false, 'message' => 'Anotasi Bounding Box diperlukan.'], 422);
        }

        foreach ($annotationsFromJson as $index => $bboxData) {
            if (!isset($bboxData['cx'], $bboxData['cy'], $bboxData['w'], $bboxData['h'], $bboxData['ripeness']) || !in_array($bboxData['ripeness'], ['ripe', 'unripe'])) {
                return response()->json(['success' => false, 'message' => "Data BBox #" . ($index + 1) . " tidak lengkap atau kematangan tidak valid."], 422);
            }

            $allAnnotationRowsForCsv[] = [
                'filename'        => $imagePathForCsv,
                'set'             => $validated['dataset_set'],
                'detection_class' => 'melon', // Selalu 'melon'
                'ripeness_class'  => $bboxData['ripeness'],
                'bbox_cx'         => (string) round((float) $bboxData['cx'], 6),
                'bbox_cy'         => (string) round((float) $bboxData['cy'], 6),
                'bbox_w'          => (string) round((float) $bboxData['w'], 6),
                'bbox_h'          => (string) round((float) $bboxData['h'], 6),
            ];
        }

        try {
            $updateSuccess = $this->datasetService->updateAnnotationsForImage($s3AnnotationCsvPath, $imagePathForCsv, $allAnnotationRowsForCsv);

            if ($updateSuccess) {
                $this->datasetChangeService->recordChange(
                    'manual_annotation_saved',
                    $imagePathForCsv,
                    count($allAnnotationRowsForCsv),
                    ['detection_class_chosen' => 'melon']
                );

                // Menghapus dari cache pending_bbox_annotations (ini sudah benar)
                $pendingBboxCache = Cache::get('pending_bbox_annotations', []);
                if (isset($pendingBboxCache[$s3ImagePathFull])) {
                    unset($pendingBboxCache[$s3ImagePathFull]);
                    Cache::put('pending_bbox_annotations', $pendingBboxCache, now()->addHours(24));
                }

                // !!! TAMBAHKAN BARIS INI UNTUK MEMBERSIHKAN CACHE DAFTAR FILE ANOTASI !!!
                Cache::forget('annotation_service_annotated_files_list_v4'); // <--- INI SUDAH BENAR!
                Log::info("[AnnotationController::save] Cache 'annotation_service_annotated_files_list_v4' dibersihkan setelah menyimpan anotasi untuk {$imagePathForCsv}.");
                // !!! AKHIR PENAMBAHAN !!!

                // Pembuatan thumbnail (ini sudah benar dari implementasi sebelumnya)
                $filenameForThumbnail = basename($imagePathForCsv);
                Log::info("Memastikan thumbnail ada untuk: {$filenameForThumbnail} dari set {$validated['dataset_set']} setelah anotasi disimpan.");
                app(DatasetService::class)->generateAndStoreThumbnail(
                    $s3ImagePathFull,
                    $validated['dataset_set'],
                    $filenameForThumbnail
                );

                $nextImageInfo = $this->annotationService->findNextUnannotatedImage($s3ImagePathFull, $validated['dataset_set']);

                return response()->json(array_merge(
                    ['success' => true, 'message' => 'Anotasi berhasil disimpan!'],
                    [ // Sesuaikan dengan struktur yang dikembalikan findNextUnannotatedImage
                        'next_image_data'           => $nextImageInfo['next_image_data'] ?? null,
                        'annotation_complete'       => $nextImageInfo['annotation_complete'] ?? false,
                        'message_if_complete'       => $nextImageInfo['message'] ?? null,
                        'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])), // Ini bisa tetap
                    ]
                ));
            } else {
                Log::error("[AnnotationController::save] Gagal menyimpan anotasi melalui FeedbackService.", [
                    's3_csv_path'      => $s3AnnotationCsvPath,
                    'image_identifier' => $imagePathForCsv,
                ]);
                return response()->json(['success' => false, 'message' => 'Gagal menyimpan anotasi ke file CSV di S3.'], 500);
            }
        } catch (Throwable $e) {
            Log::error("[AnnotationController::save] Exception saat proses penyimpanan anotasi", [
                'error_message'            => $e->getMessage(),
                'error_file'               => $e->getFile(),
                'error_line'               => $e->getLine(),
                's3_image_path_identifier' => $imagePathForCsv,
                'trace'                    => Str::limit($e->getTraceAsString(), 1000),
            ]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat menyimpan anotasi. Silakan cek log.'], 500);
        }
    }

    /**
     * Memicu pembuatan thumbnail untuk halaman spesifik.
     */
    public function generatePageThumbnails(Request $request, DatasetService $datasetService)
    {
        $request->validate(['s3_paths' => 'required|array', 's3_paths.*' => 'string']);
        $s3Paths = $request->input('s3_paths');

        if (empty($s3Paths)) {
            return response()->json(['success' => false, 'message' => 'Tidak ada path gambar yang dikirim.'], 400);
        }

        // Coba naikkan batas waktu, tapi tetap berisiko
        @set_time_limit(180);

        $generated = 0;
        $skipped   = 0;
        $failed    = 0;
        $s3Disk    = Storage::disk('s3');

        foreach ($s3Paths as $s3Path) {
            try {
                // Ekstrak set dan filename dari S3 Path (misal: 'dataset/train/image.jpg')
                // Pastikan S3_DATASET_BASE_DIR sudah benar
                $relativePath = Str::after($s3Path, rtrim(DatasetService::S3_DATASET_BASE_DIR, '/') . '/');
                $parts        = explode('/', $relativePath, 2); // Batasi jadi 2 bagian: set dan filename

                if (count($parts) !== 2 || ! in_array($parts[0], DatasetService::DATASET_SETS)) {
                    Log::warning("Format S3 path tidak valid: {$s3Path}");
                    $failed++;
                    continue;
                }
                $set      = $parts[0];
                $filename = $parts[1];

                // Buat path thumbnail
                $thumbnailS3Path = rtrim(DatasetService::S3_THUMBNAILS_BASE_DIR, '/') . '/' . $set . '/' . $filename;
                $thumbnailS3Path = preg_replace('#/+#', '/', $thumbnailS3Path);

                // Cek jika sudah ada
                if ($s3Disk->exists($thumbnailS3Path)) {
                    $skipped++;
                    continue;
                }

                // Jika belum ada, buat
                $success = $datasetService->generateAndStoreThumbnail($s3Path, $set, $filename);
                if ($success) {
                    $generated++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                Log::error("Error saat generate thumbnail '{$s3Path}' via tombol: " . $e->getMessage());
                $failed++;
            }
        }

        $message = "Proses selesai. Dibuat: {$generated}, Dilewati: {$skipped}, Gagal: {$failed}.";
        return response()->json(['success' => ($failed === 0), 'message' => $message]);
    }

    public function clearQueueCaches(Request $request): JsonResponse
    {
        try {
            // Hapus cache daftar semua file fisik
            Cache::forget('annotation_service_all_image_files_s3_v4');
            Log::info("Cache 'annotation_service_all_image_files_s3_v4' dibersihkan oleh 'Segarkan Antrian'.");

            // Hapus cache daftar file yang sudah memiliki entri anotasi
            Cache::forget('annotation_service_annotated_files_list_v4');
            Log::info("Cache 'annotation_service_annotated_files_list_v4' dibersihkan oleh 'Segarkan Antrian'.");

            // Anda bisa menambahkan cache key lain yang relevan di sini jika ada

            return response()->json(['success' => true, 'message' => 'Cache antrian di server berhasil dibersihkan. Memuat ulang data...']);
        } catch (Throwable $e) {
            Log::error("Error saat membersihkan cache antrian via tombol: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal membersihkan cache antrian di server.'], 500);
        }
    }

    public function estimateBboxAjax(Request $request): JsonResponse
    {
        // [PERBAIKAN 1B] Ubah 's3_path' menjadi 'image_path' agar sesuai dengan JS
        $validated = $request->validate(['image_path' => 'required|string']);
        $s3ImagePath = $validated['image_path'];

        try {
            // [PERBAIKAN] Panggil method terpusat dari PredictionService
            $bboxes = $this->predictionService->runPythonBboxEstimator($s3ImagePath);

            if (is_null($bboxes)) {
                // Jika null, berarti ada error saat eksekusi skrip
                throw new \RuntimeException('Skrip deteksi BBox gagal dijalankan atau output tidak valid.');
            }

            // Dapatkan dimensi gambar untuk konversi BBox ke format relatif
            $imageContent = Storage::disk('s3')->get($s3ImagePath);
            $image = new \Imagick();
            $image->readImageBlob($imageContent);
            $imgW = $image->getImageWidth();
            $imgH = $image->getImageHeight();
            $image->clear();

            if ($imgW === 0 || $imgH === 0) {
                throw new \RuntimeException('Gagal mendapatkan dimensi gambar.');
            }

            // Konversi BBox absolut (x,y,w,h) dari Python ke relatif (cx,cy,w,h) untuk frontend anotasi
            $relativeBboxes = array_map(function ($bbox) use ($imgW, $imgH) {
                return [
                    'cx' => ((float)$bbox['x'] + (float)$bbox['w'] / 2) / $imgW,
                    'cy' => ((float)$bbox['y'] + (float)$bbox['h'] / 2) / $imgH,
                    'w' => (float)$bbox['w'] / $imgW,
                    'h' => (float)$bbox['h'] / $imgH,
                ];
            }, $bboxes);

            return response()->json([
                'success' => true,
                'bboxes' => $relativeBboxes, // Kirim BBox dalam format relatif
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal saat estimasi BBox untuk anotasi.', ['error' => $e->getMessage(), 's3_path' => $s3ImagePath]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function queueStatus(): JsonResponse
    {
        try {
            // Gunakan method yang sudah ada untuk mendapatkan file tanpa anotasi
            $unannotatedFiles = $this->annotationService->getAllUnannotatedFiles();

            // Dapatkan daftar thumbnail yang sudah ada
            $existingThumbnails = collect(Storage::disk('s3')->files(DatasetService::S3_THUMBNAILS_BASE_DIR))
                ->map(fn($path) => basename($path))
                ->flip(); // Ubah value jadi key untuk pencarian cepat

            $filesWithoutThumbnails = [];
            foreach ($unannotatedFiles as $fileData) { // Ubah $file menjadi $fileData
                // [PERBAIKAN] Akses key 's3Path' dari array
                $baseFilename = basename($fileData['s3Path']);
                if (!isset($existingThumbnails[$baseFilename])) {
                    // [PERBAIKAN] Tambahkan path yang benar ke array
                    $filesWithoutThumbnails[] = $fileData['s3Path'];
                }
            }

            return response()->json([
                'success' => true,
                'needs_thumbnail_generation' => count($filesWithoutThumbnails) > 0,
                'unannotated_file_count' => count($unannotatedFiles),
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal memeriksa status antrian anotasi.', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal memeriksa status.'], 500);
        }
    }

    /**
     * Menyajikan gambar dari S3 (redirect ke URL publik).
     *
     * @param string $base64Path base64 dari 'set/filename.jpg'
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse|JsonResponse
     */
    public function serveStorageImage(string $base64Path): RedirectResponse
    {
        try {
            // $s3FullDecodedPath sekarang adalah path lengkap dari root bucket,
            // contoh: 'dataset/train/gambar.jpg' atau 'thumbnails/train/gambar.jpg'
            $s3FullDecodedPath = base64_decode($base64Path);
            if ($s3FullDecodedPath === false) {
                abort(400, 'Invalid image path encoding.');
            }

            // Sanitasi path
            $s3FullDecodedPath = Str::replace('..', '', $s3FullDecodedPath);
            $s3FullDecodedPath = ltrim(str_replace('\\', '/', $s3FullDecodedPath), '/');
            $s3FullDecodedPath = preg_replace('#/+#', '/', $s3FullDecodedPath); // Pastikan hanya satu slash

            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('s3');

            if (! $disk->exists($s3FullDecodedPath)) {
                Log::warning('AnnotationController:serveStorageImage: Gambar S3 tidak ditemukan.', ['s3_path' => $s3FullDecodedPath]);
                abort(404, 'Image not found on S3.'); // Ini akan memicu error 500 jika tidak ditangkap dengan baik oleh browser/JS
            }

            $temporaryUrl = $disk->temporaryUrl(
                $s3FullDecodedPath,
                now()->addMinutes(15) // Durasi URL temporer
            );
            Log::info("[ServeImage] Redirecting to S3 Temporary URL", ['s3_temp_url' => Str::limit($temporaryUrl, 100), 'original_path' => $s3FullDecodedPath]);
            return redirect($temporaryUrl);
        } catch (Throwable $e) {
            // Jika abort(404) dilempar, mungkin akan masuk ke sini jika tidak ada error handler khusus untuk 404 yang mengembalikan JSON.
            Log::error("[ServeImage] Exception", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            // Mengembalikan respons error yang lebih generik jika terjadi masalah tak terduga
            abort(500, 'Error serving image: ' . $e->getMessage());
        }
    }
}
