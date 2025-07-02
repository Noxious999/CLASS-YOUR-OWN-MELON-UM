<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AppMaintenanceController;
use App\Http\Controllers\DatasetChangeController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\MelonController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\StreamProxyController; // <-- TAMBAHKAN INI
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => redirect()->route('melon.index'));

Route::get('/melon', [MelonController::class, 'index'])->name('melon.index');
Route::post('/clear-application-cache', [AppMaintenanceController::class, 'clearCache'])->name('app.clear_cache');
Route::get('/stream-proxy/pi-video', [StreamProxyController::class, 'getPiVideoStream'])->name('stream.pi_video_proxy');

// --- Rute Anotasi ---
Route::prefix('annotate')->name('annotate.')->group(function () {
    Route::get('/', [AnnotationController::class, 'index'])->name('index');
    Route::post('/save', [AnnotationController::class, 'save'])->name('save');
    Route::post('/estimate-bbox', [AnnotationController::class, 'estimateBboxAjax'])->name('estimate_bbox');
    Route::post('/get-temporary-urls', [AnnotationController::class, 'getTemporaryUrls'])->name('get_urls');
    Route::post('/clear-queue-caches', [AnnotationController::class, 'clearQueueCaches'])->name('clear_queue_caches');
    Route::get('/queue-status', [AnnotationController::class, 'queueStatus'])->name('queue_status');
});

// Route yang berhubungan dengan dataset & storage
Route::post('/dataset/upload', [DatasetController::class, 'uploadImage'])->name('dataset.upload_image');
Route::post('/dataset/image/delete', [DatasetController::class, 'deleteImage'])->name('dataset.image.delete');

// --- [PERBAIKAN] TAMBAHKAN ROUTE BARU INI ---
Route::post('/dataset/images/batch-delete', [DatasetController::class, 'batchDeleteImages'])->name('dataset.images.batch-delete');
// --- AKHIR PERBAIKAN ---

Route::get('/storage-image/{path}', [AnnotationController::class, 'serveStorageImage'])->where('path', '.*')->name('storage.image');
Route::post('/generate-page-thumbnails', [AnnotationController::class, 'generatePageThumbnails'])->name('admin.trigger_page_thumbnails');

Route::prefix('predict')->name('predict.')->group(function () {
    Route::post('/from-upload', [PredictionController::class, 'predictFromUpload'])->name('from_upload');
    Route::post('/upload-temp', [PredictionController::class, 'handleImageUpload'])->name('upload_temp');
});

Route::post('/feedback/submit', [FeedbackController::class, 'handleFeedback'])->name('feedback.submit')->middleware('throttle:10,1');
Route::post('/feedback/delete', [FeedbackController::class, 'deleteFeedback'])->name('feedback.delete')->middleware('throttle:10,1');


Route::prefix('evaluate')->name('evaluate.')->group(function () {
    Route::get('/', [EvaluationController::class, 'index'])->name('index');
    Route::post('/dataset/action', [EvaluationController::class, 'handleDatasetAction'])->name('dataset.action')->middleware('throttle:10,1');
    Route::get('/stream/extract-features-inc', [EvaluationController::class, 'streamExtractFeaturesIncremental'])->name('stream.extract_features_incremental');
    Route::get('/stream/extract-features-over', [EvaluationController::class, 'streamExtractFeaturesOverwrite'])->name('stream.extract_features_overwrite');
    Route::get('/stream/train-model', [EvaluationController::class, 'streamTrainModel'])->name('stream.train_model');
});
