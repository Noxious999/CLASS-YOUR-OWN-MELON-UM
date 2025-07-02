<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dasbor Evaluasi Model</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/css/evaluate.css', 'resources/css/responsive.css', 'resources/js/app.js', 'resources/js/evaluate.js'])
</head>

<body class="evaluate-page">
    <div class="dashboard-container container-fluid my-4 px-lg-4">
        <header class="page-header mb-4">
            <h1 class="page-title"><i class="fas fa-analytics"></i>Evaluasi Sistem</h1>
            <div class="page-actions">
                {{-- PERUBAHAN DI SINI --}}
                <a href="{{ route('melon.index') }}" class="btn btn-outline-secondary btn-sm nav-loader-link"
                    data-page-name="Klasifikasi"><i class="fas fa-arrow-left"></i> Lakukan Klasifikasi</a>
                {{-- PERUBAHAN DI SINI --}}
                <a href="{{ route('annotate.index') }}" class="btn btn-sm btn-primary nav-loader-link"
                    data-page-name="Anotasi"><i class="fas fa-arrow-right me-1"></i> Lakukan Anotasi</a>
            </div>
        </header>

        <div id="notification-area-main" class="mb-3"></div>
        {{-- ▼▼▼ TAMBAHKAN BLOK INI ▼▼▼ --}}
        @if (session('status'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-times-circle me-2"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <ul class="nav nav-tabs main-tabs" id="mainEvaluationTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="quality-tab" data-bs-toggle="tab" data-bs-target="#quality-tab-pane"
                    type="button" role="tab">
                    <i class="fas fa-award"></i> Kualitas & Kontrol Dataset
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="analysis-tab" data-bs-toggle="tab" data-bs-target="#analysis-tab-pane"
                    type="button" role="tab">
                    <i class="fas fa-brain"></i> Analisis Detail Model
                </button>
            </li>
        </ul>

        <div class="tab-content" id="mainEvaluationTabContent">
            {{-- KONTEN TAB 1: KUALITAS & KONTROL --}}
            <div class="tab-pane fade show active" id="quality-tab-pane" role="tabpanel" tabindex="0">
                {{-- Memanggil satu partial yang mengatur semua layout di tab ini --}}
                @include('partials.quality-and-controls')
            </div>

            {{-- KONTEN TAB 2: ANALISIS MODEL --}}
            <div class="tab-pane fade" id="analysis-tab-pane" role="tabpanel" tabindex="0">
                @if (empty(array_filter($evaluation)))
                    <div class="text-center py-5 my-5 text-muted">
                        <p>Belum ada data evaluasi. Latih model terlebih dahulu.</p>
                    </div>
                @else
                    {{-- Navigasi Sub-Tab untuk setiap model --}}
                    <ul class="nav nav-pills model-sub-sub-tabs my-3" id="modelPills" role="tablist">
                        @foreach ($evaluation as $modelKey => $evalData)
                            @if ($evalData)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                                        id="{{ Str::slug($modelKey) }}-tab" data-bs-toggle="pill"
                                        data-bs-target="#{{ Str::slug($modelKey) }}-pane" type="button">
                                        @if ($evalData['is_ensemble'] ?? false)
                                            <i class="fas fa-star text-warning me-1"></i>
                                        @endif
                                        {{ Str::title(str_replace(['_model', '_'], ['', ' '], $modelKey)) }}
                                    </button>
                                </li>
                            @endif
                        @endforeach
                    </ul>

                    {{-- Konten untuk setiap sub-tab --}}
                    <div class="tab-content" id="modelPillsContent">
                        @foreach ($evaluation as $modelKey => $evalData)
                            <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                                id="{{ Str::slug($modelKey) }}-pane" role="tabpanel">
                                @if ($evalData)
                                    <div class="card individual-model-card">
                                        <div class="card-body">
                                            @if ($evalData['is_ensemble'] ?? false)
                                                @include('partials.ensemble-card-content', [
                                                    'evalData' => $evalData,
                                                    'chartIdSuffix' => Str::slug($modelKey),
                                                ])
                                            @else
                                                @include('partials.evaluation-card-content', [
                                                    'modelKey' => $modelKey,
                                                    'evalData' => $evalData,
                                                    'chartIdSuffix' => Str::slug($modelKey),
                                                ])
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <p class="text-muted text-center p-5">Data untuk model ini tidak tersedia.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
        window.evaluationData = @json($evaluation ?? null);
        window.streamExtractUrl = "{{ route('evaluate.stream.extract_features_incremental') }}";
        window.streamTrainUrl = "{{ route('evaluate.stream.train_model') }}";
        window.csrfToken = "{{ csrf_token() }}";
    </script>
</body>

</html>
