<?php

namespace App\Http\Controllers;

use App\Services\AnnotationService;
use App\Services\DatasetService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator; // <-- TAMBAHKAN BARIS INI
use Illuminate\Support\Str;
use Throwable;

class DatasetController extends Controller
{
    protected DatasetService $datasetService;
    protected AnnotationService $annotationService;

    public function __construct(
        DatasetService $datasetService,
        AnnotationService $annotationService
    ) {
        $this->datasetService    = $datasetService;
        $this->annotationService = $annotationService;
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image_file' => 'required|array',
            'image_file.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'target_set' => 'required|string|in:' . implode(',', DatasetService::DATASET_SETS),
        ]);

        $targetSet = $request->input('target_set');
        $s3DatasetBasePath = rtrim(DatasetService::S3_DATASET_BASE_DIR, '/');
        $newlyAddedFilesData = [];
        $errors = [];

        if ($request->hasFile('image_file')) {
            foreach ($request->file('image_file') as $file) {
                $originalFilename = $file->getClientOriginalName();
                $s3ImagePath = preg_replace('#/+#', '/', $s3DatasetBasePath . '/' . $targetSet . '/' . $originalFilename);

                try {
                    if (Storage::disk('s3')->exists($s3ImagePath)) {
                        $errors[] = "Gambar '{$originalFilename}' sudah ada.";
                        continue;
                    }

                    Storage::disk('s3')->put($s3ImagePath, file_get_contents($file), 'private');
                    $this->datasetService->generateAndStoreThumbnail($s3ImagePath, $targetSet, $originalFilename);

                    $thumbnailS3Path = DatasetService::S3_THUMBNAILS_BASE_DIR . '/' . $targetSet . '/' . $originalFilename;
                    $newlyAddedFilesData[] = [
                        's3Path' => $s3ImagePath,
                        'thumbnailS3Path' => preg_replace('#/+#', '/', $thumbnailS3Path),
                        'imagePathForCsv' => $targetSet . '/' . $originalFilename,
                        'imageUrl' => null,
                        'thumbnailUrl' => null,
                        'filename' => $originalFilename,
                        'set' => $targetSet,
                    ];
                } catch (Throwable $e) {
                    Log::error("Gagal mengunggah '{$originalFilename}'", ['error' => $e->getMessage()]);
                    $errors[] = "Gagal mengunggah '{$originalFilename}'.";
                }
            }
        }

        if (!empty($newlyAddedFilesData)) {
            Cache::forget('annotation_service_all_image_files_s3_v4');
        }

        if (!empty($newlyAddedFilesData)) {
            return response()->json([
                'success' => true,
                'message' => count($newlyAddedFilesData) . " gambar berhasil diunggah.",
                'new_files' => $newlyAddedFilesData,
                'errors' => $errors,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "Tidak ada gambar yang berhasil diunggah.",
            'errors' => $errors,
        ], 422);
    }

    public function deleteImage(Request $request): JsonResponse
    {
        $request->validate(['s3_path_original' => 'required|string']);
        $originalS3Path = $request->input('s3_path_original');

        try {
            // [PERUBAHAN] Panggil method dari DatasetService
            $deleteSuccess = $this->datasetService->deleteImageAndAssociatedData($originalS3Path);
            if (!$deleteSuccess) {
                throw new \Exception("Gagal menghapus data gambar dari service.");
            }

            Cache::forget('annotation_service_all_image_files_s3_v4');
            Cache::forget('annotation_service_annotated_files_list_v4');

            $nextImageInfo = $this->annotationService->findNextUnannotatedImage($originalS3Path);

            return response()->json([
                'success'             => true,
                'message'             => "Gambar '" . basename($originalS3Path) . "' dan anotasinya berhasil dihapus.",
                'next_image_data'     => $nextImageInfo['next_image_data'] ?? null,
                'annotation_complete' => $nextImageInfo['annotation_complete'] ?? false,
            ]);
        } catch (Throwable $e) {
            Log::error("Error saat menghapus gambar '{$originalS3Path}': " . $e->getMessage(), ['trace' => Str::limit($e->getTraceAsString(), 1000)]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat menghapus gambar.'], 500);
        }
    }

    public function batchDeleteImages(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            's3_paths'   => 'required|array',
            's3_paths.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $s3PathsToDelete = $request->input('s3_paths');
        $deletedCount = 0;
        $failedCount = 0;

        foreach ($s3PathsToDelete as $originalS3Path) {
            // [PERUBAHAN] Panggil method dari DatasetService
            $success = $this->datasetService->deleteImageAndAssociatedData($originalS3Path);
            if ($success) {
                $deletedCount++;
            } else {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            Cache::forget('annotation_service_all_image_files_s3_v4');
            Cache::forget('annotation_service_annotated_files_list_v4');
            Log::info("Cache antrian dibersihkan setelah batch delete.");
        }

        return response()->json([
            'success' => $failedCount === 0,
            'message' => "Proses selesai. Berhasil dihapus: {$deletedCount}. Gagal: {$failedCount}.",
        ]);
    }
}
