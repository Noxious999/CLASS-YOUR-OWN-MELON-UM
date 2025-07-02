<?php

namespace App\Http\Controllers;

use App\Services\DatasetChangeService;
use App\Services\DatasetService;
use App\Services\EvaluationService;
use App\Services\FeatureExtractionService; // <-- Tambahkan ini
use App\Services\ModelService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // <-- Tambahkan ini
use Illuminate\Support\Str;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Learner;
use Rubix\ML\Probabilistic; // <-- Tambahkan ini
use Rubix\ML\Transformers\Transformer; // <-- Tambahkan ini
use Rubix\ML\CrossValidation\Metrics\Accuracy; // <-- Tambahkan ini
use Symfony\Component\HttpFoundation\StreamedResponse; // <-- Tambahkan ini
use Symfony\Component\Process\Process;
use Throwable;

class EvaluationController extends Controller
{
    public function __construct(
        protected EvaluationService $evaluationService,
        protected DatasetService $datasetService,
        protected DatasetChangeService $datasetChangeService,
        protected ModelService $modelService
    ) {}

    public function streamExtractFeaturesIncremental(): StreamedResponse
    {
        // Panggil dengan flag --incremental
        return $this->streamArtisanCommand('dataset:extract-features', ['--incremental'], 'extract_features_incremental');
    }

    public function streamExtractFeaturesOverwrite(): StreamedResponse
    {
        return $this->streamArtisanCommand('dataset:extract-features', [], 'extract_features_overwrite');
    }

    public function streamTrainModel(): StreamedResponse
    {
        return $this->streamArtisanCommand('train:melon-model', ['--with-test'], 'train_model_unified');
    }

    private function streamArtisanCommand(string $commandName, array $arguments = [], ?string $actionKeyForLog = null): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($commandName, $arguments, $actionKeyForLog) {
            set_time_limit(0);
            if (ob_get_level() > 0) @ob_end_flush();

            $command = array_merge([PHP_BINARY ?: 'php', base_path('artisan'), $commandName], $arguments);
            $startTime = microtime(true);

            $this->sendSseMessage(['status' => 'START', 'message' => "Memulai proses {$commandName}..."]);

            $process = new Process($command, base_path(), null, null, 7200.0);
            $commandOutputForSummary = "";

            try {
                $process->start();
                foreach ($process->getIterator($process::ITER_KEEP_OUTPUT) as $line) {
                    $this->sendSseMessage(['log' => trim($line)]);
                    if (Str::startsWith(trim($line), ['✅', '🚀', 'Error', 'Warning'])) {
                        $commandOutputForSummary .= trim($line) . "\n";
                    }
                    if (connection_aborted()) {
                        if ($process->isRunning()) $process->stop();
                        break;
                    }
                }
                if ($process->isRunning()) $process->wait();

                $duration = round(microtime(true) - $startTime, 2);
                $finalStatus = $process->isSuccessful() ? 'DONE' : 'ERROR';
                $statusMessage = "Proses {$commandName} " . ($finalStatus === 'DONE' ? "selesai" : "GAGAL") . " dalam {$duration} detik.";

                if ($finalStatus === 'ERROR') {
                    $commandOutputForSummary .= "ERROR: " . Str::limit($process->getErrorOutput(), 200);
                }

                $this->sendSseMessage(['status' => $finalStatus, 'message' => $statusMessage]);

                if ($actionKeyForLog) {
                    app(DatasetChangeService::class)->recordLastActionPerformed($actionKeyForLog, [
                        'status' => $finalStatus === 'DONE' ? 'Sukses' : 'Gagal',
                        'duration_seconds' => $duration,
                        'output_summary' => Str::limit($commandOutputForSummary, 500),
                    ]);
                }
            } catch (Throwable $e) {
                $this->sendSseMessage(['status' => 'ERROR', 'message' => "Terjadi kesalahan server: " . Str::limit($e->getMessage(), 150)]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        return $response;
    }

    private function sendSseMessage(array $data): void
    {
        echo "data: " . json_encode($data) . "\n\n";
        if (ob_get_level() > 0) @ob_flush();
        @flush();
    }

    public function showEvaluationPage(): ViewContract|RedirectResponse
    {
        try {
            $evaluationData = $this->evaluationService->getAggregatedEvaluationData();
            foreach ($evaluationData as $modelKey => &$data) {
                if ($data) {
                    $data['test_results'] = $this->evaluationService->loadTestResults($modelKey);
                }
            }
            unset($data);

            $stackingEnsembleResults = $this->loadStackingEnsembleResults();
            if ($stackingEnsembleResults) {
                $evaluationData['stacking_ensemble'] = $stackingEnsembleResults;
            }

            // --- [PERBAIKAN UTAMA] Menyiapkan info fitur yang benar ---
            $selectedFeatures = FeatureExtractionService::SELECTED_FEATURE_NAMES;
            $featureInfo = [
                'count' => count($selectedFeatures),
                'names' => $selectedFeatures,
            ];
            // --- AKHIR PERBAIKAN ---

            $datasetStats = $this->datasetService->getStatistics();
            $notificationData = $this->datasetChangeService->getUnseenChangesNotificationData();

            return view('evaluate', [
                'evaluation' => $evaluationData,
                'datasetStats' => $datasetStats,
                'featureInfo' => $featureInfo, // Kirim info fitur yang sudah benar ke view
                'lastTrainingTimeFormatted' => $this->evaluationService->getLatestTrainingTimes($evaluationData)[0] ?? 'N/A',
                'showDatasetChangeNotification' => $notificationData['show_notification'],
                'datasetChangeSummary' => $notificationData['summary'],
            ]);
        } catch (Throwable $e) {
            Log::error("Error loading evaluation page data", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->route('melon.index')->with('error', 'Gagal memuat halaman evaluasi: ' . $e->getMessage());
        }
    }

    /**
     * METHOD BARU: Untuk memuat data hasil dan metadata dari Stacking Ensemble
     */
    private function loadStackingEnsembleResults(): ?array
    {
        $s3ResultsPath = ModelService::MODEL_DIR_S3 . '/stacking_ensemble_test_results.json';
        $s3MetaPath = ModelService::MODEL_DIR_S3 . '/stacking_meta_learner_meta.json';
        // BARU: Path untuk metrik validasi meta-learner
        $s3ValidationPath = ModelService::MODEL_DIR_S3 . '/stacking_meta_learner_validation_metrics.json';

        if (!Storage::disk('s3')->exists($s3ResultsPath) || !Storage::disk('s3')->exists($s3MetaPath)) {
            return null;
        }

        try {
            $testResults = json_decode(Storage::disk('s3')->get($s3ResultsPath), true);
            $metadata = json_decode(Storage::disk('s3')->get($s3MetaPath), true);

            // BARU: Muat metrik validasi jika ada
            $validationMetrics = null;
            if (Storage::disk('s3')->exists($s3ValidationPath)) {
                $validationMetrics = json_decode(Storage::disk('s3')->get($s3ValidationPath), true);
            }

            return [
                'metadata' => $metadata,
                'validation_metrics' => $validationMetrics, // Tambahkan ini
                'learning_curve_data' => null, // Ensemble tidak punya learning curve tradisional
                'cv_results' => null,
                'test_results' => $testResults,
                'is_ensemble' => true,
            ];
        } catch (Throwable $e) {
            Log::error("Gagal memuat hasil Stacking Ensemble", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function loadFeaturesFromCsv(string $s3Path): array
    {
        if (!Storage::disk('s3')->exists($s3Path)) {
            return [[], []];
        }
        $content = Storage::disk('s3')->get($s3Path);
        if (empty(trim($content))) {
            return [[], []];
        }
        $lines = explode("\n", trim($content));
        array_shift($lines);
        $samples = [];
        $labels = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $row = str_getcsv($line);
            $features = array_slice($row, 2);
            if (count($features) === FeatureExtractionService::UNIFIED_FEATURE_NAMES) {
                $samples[] = array_map('floatval', $features);
                $labels[] = strtolower(trim($row[1] ?? ''));
            }
        }
        return [$samples, $labels];
    }

    public function handleDatasetAction(Request $request): JsonResponse
    {
        $action = $request->input('action');
        try {
            switch ($action) {
                case 'get_stats':
                    return response()->json(['success' => true, 'stats' => $this->datasetService->getStatistics(), 'timestamp' => now()->toDateTimeString()]);
                case 'analyze':
                    $stats = $this->datasetService->getStatistics();
                    return response()->json(['success' => true, 'details' => $this->datasetService->analyzeQuality($stats)]);
                case 'adjust':
                    return response()->json($this->datasetService->adjustBalance());
                default:
                    return response()->json(['success' => false, 'message' => 'Aksi tidak valid.'], 400);
            }
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
        }
    }

    public function index(): ViewContract | RedirectResponse
    {
        return $this->showEvaluationPage();
    }
}
