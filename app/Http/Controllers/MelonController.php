<?php

namespace App\Http\Controllers;

use App\Services\AnnotationService; // <-- Tambahkan
use App\Services\DatasetChangeService;
use App\Services\EvaluationService;
use App\Services\ModelService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

class MelonController extends Controller
{
    public function __construct(
        protected ModelService $modelService,
        protected DatasetChangeService $datasetChangeService,
        protected AnnotationService $annotationService // <-- Tambahkan
    ) {}

    public function index(Request $request): ViewContract
    {
        $result = $request->session()->pull(EvaluationService::FLASH_RESULT);

        $modelKeysForView = Cache::remember('melon_controller_model_keys_for_view_v4', now()->addHours(6), function () {
            $mapping = [];
            $unifiedModels = [
                'k_nearest_neighbors_model',
                'random_forest_model',
            ];
            foreach ($unifiedModels as $modelKey) {
                $meta = $this->modelService->loadModelMetadata($modelKey);
                if ($meta && isset($meta['algorithm_class'])) {
                    $baseName = class_basename($meta['algorithm_class']);
                    $mapping[$modelKey] = "{$baseName} (Terpadu)";
                }
            }
            return $mapping;
        });

        $lastTrainingTime = $this->getLastTrainingTime();
        $unannotatedFiles = $this->annotationService->getAllUnannotatedFiles();
        $pendingAnnotationCount = count($unannotatedFiles);
        $datasetChangeNotificationData = $this->datasetChangeService->getUnseenChangesNotificationData();

        return view('melon', [
            'result' => $result,
            'modelKeysForView' => $modelKeysForView,
            'lastTrainingTimeFormatted' => $lastTrainingTime,
            'pendingAnnotationCount' => $pendingAnnotationCount, // <-- Kirim ke view
            'showDatasetChangeNotification' => $datasetChangeNotificationData['show_notification'],
            'datasetChangeSummary' => $datasetChangeNotificationData['summary'],
        ]);
    }

    private function getLastTrainingTime(): string
    {
        $latest = null;
        $unifiedModels = [
            'k_nearest_neighbors_model',
            'random_forest_model',
            'classification_tree_model',
        ];

        foreach ($unifiedModels as $modelKey) {
            $meta = $this->modelService->loadModelMetadata($modelKey);
            if ($meta && isset($meta['trained_at'])) {
                try {
                    $time = Carbon::parse($meta['trained_at']);
                    if (!$latest || $time->isAfter($latest)) {
                        $latest = $time;
                    }
                } catch (Throwable $e) {
                    Log::warning("Invalid date format in metadata for {$modelKey}");
                }
            }
        }
        return $latest ? $latest->isoFormat('D MMM HH:mm') : 'Belum tersedia';
    }
}
