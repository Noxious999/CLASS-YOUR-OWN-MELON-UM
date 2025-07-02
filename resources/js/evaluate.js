import { Chart, registerables } from "chart.js";
Chart.register(...registerables);
import * as bootstrap from "bootstrap";

document.addEventListener("DOMContentLoaded", function () {
    const evaluationData = window.evaluationData || {};
    const csrfToken = window.csrfToken;
    const sseButtons = document.querySelectorAll(".sse-action-btn");
    const sseLogContainer = document.getElementById("sse-progress-log");
    const originalButtonHtmlCache = {};
    let activeEventSource = null;
    let sseInProgress = false;

    const showOverlay = (text) => {
        let overlay = document.getElementById("page-overlay-eval");
        if (!overlay) {
            overlay = document.createElement("div");
            overlay.id = "page-overlay-eval";
            overlay.style.cssText =
                "position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(248, 249, 250, 0.9); z-index:1070; display:flex; align-items:center; justify-content:center;";
            overlay.innerHTML = `<div><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div><span class="ms-3 fs-5 text-primary" id="overlay-text-eval"></span></div>`;
            document.body.appendChild(overlay);
        }
        document.getElementById("overlay-text-eval").textContent = text;
        overlay.style.display = "flex";
    };

    const showGlobalNotification = (
        message,
        type = "info",
        duration = 7000
    ) => {
        const area = document.getElementById("notification-area-main");
        if (!area) return;
        const icon =
            {
                success: "fa-check-circle",
                info: "fa-info-circle",
                warning: "fa-exclamation-triangle",
                danger: "fa-times-circle",
            }[type] || "fa-info-circle";
        const alertDiv = document.createElement("div");
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `<i class="fas ${icon} me-2"></i>${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        area.innerHTML = "";
        area.appendChild(alertDiv);
        if (duration > 0)
            setTimeout(
                () => bootstrap.Alert.getOrCreateInstance(alertDiv)?.close(),
                duration
            );
    };

    const createMetricsChart = (canvasId, metricsData, title = "") => {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !metricsData || !metricsData.metrics_per_class) return;

        const ctx = canvas.getContext("2d");
        if (Chart.getChart(ctx)) Chart.getChart(ctx).destroy();

        const labels = Object.keys(metricsData.metrics_per_class);
        new Chart(ctx, {
            type: "bar",
            data: {
                labels: labels.map((l) =>
                    l
                        .replace(/_/g, " ")
                        .replace(/\b\w/g, (c) => c.toUpperCase())
                ),
                datasets: [
                    {
                        label: "Presisi",
                        data: labels.map(
                            (l) =>
                                (metricsData.metrics_per_class[l]?.precision ||
                                    0) * 100
                        ),
                        backgroundColor: "rgba(54, 162, 235, 0.7)", // BIRU
                    },
                    {
                        label: "Recall",
                        data: labels.map(
                            (l) =>
                                (metricsData.metrics_per_class[l]?.recall ||
                                    0) * 100
                        ),
                        backgroundColor: "rgba(255, 99, 132, 0.7)", // MERAH
                    },
                    {
                        label: "F1-Score",
                        data: labels.map(
                            (l) =>
                                (metricsData.metrics_per_class[l]?.f1_score ||
                                    0) * 100
                        ),
                        // PERUBAHAN WARNA DI SINI
                        backgroundColor: "rgba(40, 167, 69, 0.7)", // HIJAU
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: (v) => `${v}%` },
                    },
                },
                plugins: {
                    legend: {
                        position: "bottom",
                        labels: { boxWidth: 12, font: { size: 10 } },
                    },
                },
            },
        });
    };

    const createLearningCurveChart = (canvasId, lcData) => {
        const canvas = document.getElementById(canvasId);
        if (
            !canvas ||
            !lcData ||
            !lcData.train_sizes ||
            lcData.train_sizes.length === 0
        )
            return;
        const ctx = canvas.getContext("2d");
        if (Chart.getChart(ctx)) Chart.getChart(ctx).destroy();

        new Chart(ctx, {
            type: "line",
            data: {
                labels: lcData.train_sizes,
                datasets: [
                    {
                        label: "Skor Training",
                        data: lcData.train_scores.map((s) => s * 100),
                        borderColor: "rgba(54, 162, 235, 1)",
                        tension: 0.1,
                    },
                    {
                        label: "Skor Validasi",
                        data: lcData.test_scores.map((s) => s * 100),
                        borderColor: "rgba(255, 99, 132, 1)",
                        tension: 0.1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: (v) => `${v}%` },
                    },
                    x: { title: { display: true, text: "Jumlah Sampel" } },
                },
                plugins: { legend: { position: "bottom" } },
            },
        });
    };

    // BARU: Fungsi untuk grafik perbandingan performa ensemble
    const createPerformanceCompareChart = (
        canvasId,
        valMetrics,
        testMetrics
    ) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !valMetrics || !testMetrics) return;
        const ctx = canvas.getContext("2d");
        if (Chart.getChart(ctx)) Chart.getChart(ctx).destroy();

        const valAccuracy = (valMetrics.metrics.accuracy || 0) * 100;
        const testAccuracy = (testMetrics.metrics.accuracy || 0) * 100;

        new Chart(ctx, {
            type: "bar",
            data: {
                labels: ["Akurasi"],
                datasets: [
                    {
                        label: "Validasi",
                        data: [valAccuracy],
                        backgroundColor: "rgba(255, 159, 64, 0.7)",
                    },
                    {
                        label: "Test",
                        data: [testAccuracy],
                        backgroundColor: "rgba(75, 192, 192, 0.7)",
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: (v) => `${v}%` },
                    },
                },
                plugins: { legend: { position: "bottom" } },
            },
        });
    };

    const initializeChartsForModel = (modelKey) => {
        const modelData = evaluationData[modelKey];
        if (!modelData) return;

        const chartSuffix = modelKey.replace(/_/g, "-");

        // Render grafik jika datanya ada
        if (modelData.learning_curve_data) {
            createLearningCurveChart(
                `learningCurve-${chartSuffix}`,
                modelData.learning_curve_data
            );
        }
        if (modelData.validation_metrics) {
            createMetricsChart(
                `valMetricsChart-${chartSuffix}`,
                modelData.validation_metrics
            );
        }
        if (modelData.test_results) {
            createMetricsChart(
                `testMetricsChart-${chartSuffix}`,
                modelData.test_results
            );
        }
        // BARU: Jika ini ensemble, buat grafik perbandingan
        if (
            modelData.is_ensemble &&
            modelData.validation_metrics &&
            modelData.test_results
        ) {
            createPerformanceCompareChart(
                `perfCompareChart-${chartSuffix}`,
                modelData.validation_metrics,
                modelData.test_results
            );
        }
    };

    const setupTabEventListeners = () => {
        const modelPills = document.querySelectorAll("#modelPills .nav-link");
        modelPills.forEach((pill) => {
            pill.addEventListener("shown.bs.tab", (event) => {
                const paneId = event.target.getAttribute("data-bs-target");
                if (!paneId) return;
                const modelKey = paneId
                    .substring(1)
                    .replace("-pane", "")
                    .replace(/-/g, "_");
                initializeChartsForModel(modelKey);
            });
        });

        // Inisialisasi grafik untuk tab yang aktif saat halaman dimuat
        const activePill = document.querySelector(
            "#modelPills .nav-link.active"
        );
        if (activePill) {
            const initialPaneId = activePill.getAttribute("data-bs-target");
            if (initialPaneId) {
                const initialModelKey = initialPaneId
                    .substring(1)
                    .replace("-pane", "")
                    .replace(/-/g, "_");
                initializeChartsForModel(initialModelKey);
            }
        }
    };

    const setupSseListeners = () => {
        sseButtons.forEach((button) => {
            originalButtonHtmlCache[button.id] = button.innerHTML;
            button.addEventListener("click", () => {
                if (sseInProgress) {
                    showGlobalNotification(
                        "Proses lain sedang berjalan, harap tunggu.",
                        "warning"
                    );
                    return;
                }

                const streamUrl = button.dataset.streamUrl;
                // BARU: Dapatkan target kontainer log dari atribut data-*
                const logTargetId = button.dataset.logTarget;
                if (!streamUrl || !logTargetId) return;

                const sseLogContainer = document.querySelector(logTargetId);
                if (!sseLogContainer) return;

                // Sembunyikan semua kontainer log lain sebelum memulai
                document
                    .querySelectorAll(".sse-log")
                    .forEach((log) => (log.style.display = "none"));

                if (activeEventSource) activeEventSource.close();

                sseInProgress = true;
                sseButtons.forEach((btn) => (btn.disabled = true));
                button.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span> Berjalan...';

                sseLogContainer.innerHTML = "Menghubungkan ke server...";
                sseLogContainer.style.display = "block"; // Tampilkan hanya kontainer yang relevan

                activeEventSource = new EventSource(streamUrl);

                activeEventSource.onopen = () => {
                    sseLogContainer.innerHTML =
                        "Koneksi berhasil. Memulai proses...\n";
                };

                activeEventSource.onmessage = (event) => {
                    const data = JSON.parse(event.data);
                    if (data.log) {
                        sseLogContainer.textContent += data.log + "\n";
                        sseLogContainer.scrollTop =
                            sseLogContainer.scrollHeight;
                    }
                    if (data.status === "DONE" || data.status === "ERROR") {
                        showGlobalNotification(
                            data.message,
                            data.status === "DONE" ? "success" : "danger"
                        );
                        activeEventSource.close();
                        sseInProgress = false;
                        sseButtons.forEach((btn) => {
                            btn.disabled = false;
                            btn.innerHTML = originalButtonHtmlCache[btn.id];
                        });
                        if (data.status === "DONE") {
                            setTimeout(() => window.location.reload(), 2000);
                        }
                    }
                };

                activeEventSource.onerror = () => {
                    sseLogContainer.textContent +=
                        "Koneksi error atau terputus.\n";
                    activeEventSource.close();
                    sseInProgress = false;
                    sseButtons.forEach((btn) => {
                        btn.disabled = false;
                        btn.innerHTML = originalButtonHtmlCache[btn.id];
                    });
                    showGlobalNotification(
                        "Koneksi ke server terputus.",
                        "danger"
                    );
                };
            });
        });
    };

    if (evaluationData && Object.keys(evaluationData).length > 0) {
        setupTabEventListeners();
    }
    setupSseListeners();

    // ▼▼▼ TAMBAHKAN BLOK BARU DI SINI ▼▼▼
    document.body.addEventListener("click", function (e) {
        const link = e.target.closest(".nav-loader-link");
        if (!link || e.ctrlKey || e.metaKey || e.button === 1) {
            return;
        }
        e.preventDefault();
        const destination = link.href;
        const pageName = link.dataset.pageName || "halaman";
        showOverlay(`Membuka ${pageName}...`);
        setTimeout(() => {
            window.location.href = destination;
        }, 150);
    });
    // ▲▲▲ AKHIR BLOK BARU ▲▲▲
});
