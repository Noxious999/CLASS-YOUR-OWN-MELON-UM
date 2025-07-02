import "./bootstrap";
import * as bootstrap from "bootstrap";
window.bootstrap = bootstrap;

document.addEventListener("DOMContentLoaded", () => {
    // === KUMPULAN ELEMEN DOM ===
    const uploadForm = document.getElementById("upload-form");
    const imageInput = document.getElementById("image-input");
    const classifyBtn = document.getElementById("classify-btn");
    const resultSection = document.getElementById("result-section");
    const resultMainCard = document.getElementById("result-main-card");
    const overlay = document.getElementById("overlay");
    const overlayText = document.getElementById("overlay-text");
    const pipelineErrorDisplay = document.getElementById(
        "pipeline-error-display"
    );
    const classificationResultArea = document.getElementById(
        "classification-result-area"
    );
    const resultFilenameDisplay = document.getElementById(
        "result-filename-display"
    );
    const originalImageDisplay = document.getElementById(
        "original-image-display"
    );
    const resultImageDisplay = document.getElementById("result-image-display");
    const bboxOverlay = document.getElementById("bbox-overlay-interactive");
    const manualBboxControls = document.getElementById("manual-bbox-controls");
    const addBboxBtn = document.getElementById("add-bbox-btn");
    const deleteSelectedBboxBtn = document.getElementById(
        "delete-selected-bbox-btn"
    );
    const clearAllBboxBtn = document.getElementById("clear-all-bbox-btn");
    const reclassifyBtn = document.getElementById("reclassify-btn");
    const reEstimateBtn = document.getElementById("re-estimate-btn");
    const cancelDrawBtn = document.getElementById("cancel-draw-btn");
    const editBboxBtn = document.getElementById("edit-bbox-btn");
    const multiResultCardTemplate = document.getElementById(
        "multi-result-card-template"
    );
    const overallResultCardTemplate = document.getElementById(
        "overall-result-card-template"
    );
    const resultTemplateNonMelon = document.getElementById(
        "result-template-non-melon"
    );
    const modeToggle = document.getElementById("mode-toggle");
    const modeLabel = document.getElementById("mode-label");
    const manualUploadMode = document.getElementById("manual-upload-mode");
    const piCameraMode = document.getElementById("pi-camera-mode");
    const triggerPiBtn = document.getElementById("trigger-pi-btn");
    const toggleStreamBtn = document.getElementById("toggle-stream-btn");
    const piVideoStream = document.getElementById("pi-video-stream");
    const videoStreamContainer = document.querySelector(
        ".video-stream-container"
    );
    const feedbackSection = document.getElementById("feedback-section");
    const feedbackInitialPrompt = document.getElementById(
        "feedback-initial-prompt"
    );
    const feedbackCorrectionOptions = document.getElementById(
        "feedback-correction-options"
    );
    const feedbackGivenState = document.getElementById("feedback-given-state");
    const previousFeedbackDisplay = document.getElementById(
        "previous-feedback-display"
    );
    const feedbackResultMessage = document.getElementById(
        "feedback-result-message"
    );

    const bboxModeInstruction = document.getElementById(
        "bbox-mode-instruction"
    ); // <-- TAMBAHKAN INI

    const annotationNotificationArea = document.getElementById(
        "annotation-notification-area"
    );
    const pendingAnnotationCountSpan = document.getElementById(
        "pending-annotation-count"
    );
    const feedbackToastEl = document.getElementById("feedback-toast");
    const feedbackToast = feedbackToastEl
        ? new bootstrap.Toast(feedbackToastEl)
        : null;

    // === STATE APLIKASI ===
    const {
        uploadImageUrl,
        classifyUrl,
        feedbackUrl,
        csrfToken,
        triggerPiUrl,
        deleteFeedbackUrl,
        piStreamProxyUrl, // <-- TAMBAHKAN INI (PASTIKAN ADA DI SCRIPT BLADE)
    } = window;
    let currentApiData = {};
    let currentBboxes = [];
    let selectedBoxId = -1;
    let nextBoxId = 0;
    let interactionState = { type: "none", targetId: -1, startX: 0, startY: 0 };
    let imageRenderData = null;
    let isStreaming = false;
    let isLoadingStream = false; // State baru untuk mencegah klik ganda
    let connectionTimeout = null; // [PERUBAHAN KUNCI] Variabel untuk menyimpan timer kita
    let isEditMode = false;
    let isDrawingMode = false;
    const minBoxSize = 10;

    // === FUNGSI UTILITAS UI ===
    const showOverlay = (text) => {
        if (overlayText) overlayText.textContent = text;
        if (overlay) overlay.style.display = "flex";
        if (classifyBtn) classifyBtn.disabled = true;
        if (triggerPiBtn) triggerPiBtn.disabled = true;
    };

    const hideOverlay = () => {
        if (overlay) overlay.style.display = "none";
        if (classifyBtn) classifyBtn.disabled = false;
        if (triggerPiBtn) triggerPiBtn.disabled = false;
    };

    const resetUI = (fullReset = true) => {
        if (fullReset) {
            uploadForm?.reset();
            resultSection?.classList.add("d-none");
            originalImageDisplay.src = ""; // Hapus gambar asli
            resultFilenameDisplay.textContent = ""; // <-- TAMBAHKAN INI untuk membersihkan nama file
            currentApiData = {}; // <-- INI YANG PALING PENTING
        }

        // ▼▼▼ TAMBAHKAN BLOK INI ▼▼▼
        // Selalu pastikan prompt feedback kembali ke kondisi awal saat UI di-reset.
        // Ini mengaktifkan kembali tombol dan menampilkannya, sambil menyembunyikan pesan "sudah feedback".
        if (feedbackInitialPrompt && feedbackGivenState) {
            feedbackInitialPrompt.style.display = "block";
            feedbackInitialPrompt
                .querySelectorAll("button")
                .forEach((btn) => (btn.disabled = false));
            feedbackGivenState.classList.add("d-none");
        }
        // ▲▲▲ AKHIR BLOK TAMBAHAN ▲▲▲

        currentBboxes = [];
        nextBoxId = 0;
        selectedBoxId = -1;
        interactionState = { type: "none" };
        imageRenderData = null; // <-- TAMBAHKAN INI untuk membersihkan data render
        pipelineErrorDisplay?.classList.add("d-none");
        resultMainCard?.classList.remove("d-none");
        classificationResultArea.innerHTML = "";
        manualBboxControls?.classList.add("d-none");
        feedbackSection?.classList.add("d-none");
        bboxOverlay.innerHTML = "";
        deactivateAllModes();
        updateBboxButtonStates();
    };

    const calculateImageRenderData = () => {
        // [PERBAIKAN DEFINITIF] untuk error 'resultImageDíplay is not defined'
        if (
            !resultImageDisplay ||
            !resultImageDisplay.naturalWidth ||
            resultImageDisplay.naturalWidth === 0
        ) {
            return null;
        }

        const containerW = resultImageDisplay.clientWidth;
        const containerH = resultImageDisplay.clientHeight; // <-- TYPO SUDAH DIPERBAIKI
        const naturalW = resultImageDisplay.naturalWidth;
        const naturalH = resultImageDisplay.naturalHeight;

        const imgRatio = naturalW / naturalH;
        const containerRatio = containerW / containerH;

        let renderW, renderH, offsetX, offsetY;

        if (containerRatio > imgRatio) {
            // Pillarbox (ada space kosong di kiri-kanan)
            renderH = containerH;
            renderW = containerH * imgRatio;
            offsetX = (containerW - renderW) / 2;
            offsetY = 0;
        } else {
            // Letterbox (ada space kosong di atas-bawah)
            renderW = containerW;
            renderH = containerW / imgRatio;
            offsetX = 0;
            offsetY = (containerH - renderH) / 2;
        }

        return {
            renderW: renderW,
            renderH: renderH,
            offsetX: offsetX,
            offsetY: offsetY,
            naturalW: naturalW,
            naturalH: naturalH,
        };
    };

    // [PERBAIKAN] Fungsi terpusat untuk menonaktifkan semua mode interaksi BBox
    const deactivateAllModes = () => {
        isEditMode = false;
        isDrawingMode = false;
        editBboxBtn.classList.remove("active");
        addBboxBtn.classList.remove("active");
        cancelDrawBtn.classList.add("d-none");
        bboxOverlay.style.cursor = "default";
        selectedBoxId = -1; // <-- GANTI DENGAN BARIS INI. Langsung ubah state.

        // Secara eksplisit kembalikan teks tombol "Ubah" ke keadaan semula.
        if (editBboxBtn) {
            editBboxBtn.innerHTML = `<i class="fas fa-edit"></i> <span class="d-none d-md-inline">Ubah</span>`;
        }

        if (bboxModeInstruction) {
            bboxModeInstruction.classList.add("d-none");
        }

        renderAllBboxes();
    };

    const updateBboxButtonStates = () => {
        const hasBboxes = currentBboxes.length > 0;
        // Tombol hapus terpilih hanya aktif jika ada BBox yang dipilih
        deleteSelectedBboxBtn.disabled = selectedBoxId === -1;
        clearAllBboxBtn.disabled = !hasBboxes;
        editBboxBtn.disabled = !hasBboxes;
        reclassifyBtn.disabled = !hasBboxes;
        if (!hasBboxes) {
            deactivateAllModes();
        }
        // Tampilkan/Sembunyikan feedback berdasarkan apakah ada hasil klasifikasi (bukan cuma deteksi)
        const hasClassificationResults =
            classificationResultArea.children.length > 0;
        feedbackSection.classList.toggle("d-none", !hasClassificationResults);
    };

    // === FUNGSI UTAMA UNTUK UPDATE UI FEEDBACK ===
    function updateFeedbackDisplay(feedbackDetails) {
        // Pastikan semua elemen DOM yang dibutuhkan ada.
        const deleteBtn = document.getElementById("delete-feedback-btn");
        if (!feedbackGivenState || !feedbackInitialPrompt || !deleteBtn) return;

        if (feedbackDetails) {
            // --- KONDISI: FEEDBACK SUDAH DIBERIKAN ---

            // 1. Sembunyikan prompt pilihan awal.
            feedbackInitialPrompt.style.display = "none";

            // 2. Tampilkan kontainer "feedback diberikan".
            feedbackGivenState.classList.remove("d-none");

            // 3. [PERBAIKAN KUNCI] Pastikan tombol "Hapus Feedback" SELALU AKTIF.
            deleteBtn.disabled = false;

            // 4. Atur teks yang menampilkan riwayat feedback.
            if (previousFeedbackDisplay) {
                switch (feedbackDetails.feedback_type) {
                    case "confirmed":
                        previousFeedbackDisplay.textContent = '"Ya, Sesuai"';
                        break;
                    case "correction_needed":
                        previousFeedbackDisplay.textContent =
                            '"Tidak, Perlu Koreksi"';
                        break;
                    default:
                        previousFeedbackDisplay.textContent =
                            "(feedback dicatat)";
                }
            }
        } else {
            // --- KONDISI: TIDAK ADA FEEDBACK / FEEDBACK DIHAPUS ---

            // 1. Tampilkan prompt pilihan awal.
            feedbackInitialPrompt.style.display = "block";

            // 2. [PERBAIKAN KUNCI] Pastikan tombol "Ya/Tidak" SELALU AKTIF.
            feedbackInitialPrompt
                .querySelectorAll("button")
                .forEach((btn) => (btn.disabled = false));

            // 3. Sembunyikan kontainer "feedback diberikan".
            feedbackGivenState.classList.add("d-none");

            // 4. Kosongkan teks riwayat feedback.
            if (previousFeedbackDisplay) {
                previousFeedbackDisplay.textContent = "";
            }
        }
    }

    const renderResultsUI = (data) => {
        // ==========================================================
        // BAGIAN 1: SETUP AWAL DAN PERSIAPAN DATA
        // ==========================================================
        resultSection.classList.remove("d-none");
        resultMainCard.classList.remove("d-none");
        manualBboxControls.classList.remove("d-none");
        pipelineErrorDisplay.classList.add("d-none");

        imageRenderData = calculateImageRenderData();
        if (!imageRenderData) {
            console.error(
                "Gagal menghitung data render gambar. Proses dihentikan."
            );
            pipelineErrorDisplay.textContent =
                "Gagal memproses dimensi gambar. Coba unggah ulang.";
            pipelineErrorDisplay.classList.remove("d-none");
            return;
        }

        if (bboxOverlay) {
            bboxOverlay.style.left = `${imageRenderData.offsetX}px`;
            bboxOverlay.style.top = `${imageRenderData.offsetY}px`;
            bboxOverlay.style.width = `${imageRenderData.renderW}px`;
            bboxOverlay.style.height = `${imageRenderData.renderH}px`;
        }

        currentBboxes = [];
        nextBoxId = 0;

        if (data.all_results?.length > 0) {
            currentBboxes = data.all_results.map((r) => ({
                id: nextBoxId++,
                ...r.bbox,
            }));
        }

        renderAllBboxes();

        // Memanggil fungsi baru yang terpusat untuk render kartu
        renderClassificationCards(data);
        updateFeedbackDisplay(
            data.feedback_given ? data.feedback_details : null
        );

        // ==========================================================
        // BAGIAN 2: PERBARUI UI FEEDBACK DAN TOMBOL
        // ==========================================================
        const feedbackGivenState = document.getElementById(
            "feedback-given-state"
        );
        const previousFeedbackDisplay = document.getElementById(
            "previous-feedback-display"
        ); // Ambil elemen baru

        if (data.feedback_given) {
            // Jika feedback sudah diberikan, tampilkan state "diberikan"
            feedbackInitialPrompt.style.display = "none";
            feedbackGivenState.classList.remove("d-none");
        }
        if (previousFeedbackDisplay && data.feedback_details) {
            switch (data.feedback_details.feedback_type) {
                case "confirmed":
                    previousFeedbackDisplay.textContent = '"Ya, Sesuai"';
                    break;
                case "correction_needed":
                    previousFeedbackDisplay.textContent =
                        '"Tidak, Perlu Koreksi"';
                    break;
                default:
                    previousFeedbackDisplay.textContent = "(feedback dicatat)";
            }
        } else {
            feedbackInitialPrompt.style.display = "block";
            feedbackGivenState.classList.add("d-none");
            if (previousFeedbackDisplay) {
                previousFeedbackDisplay.textContent = ""; // Pastikan bersih saat tidak ada feedback
            }
        }
        if (feedbackCorrectionOptions) {
            feedbackCorrectionOptions.classList.add("d-none");
        }
        if (feedbackResultMessage) {
            feedbackResultMessage.innerHTML = "";
        }
        const hasClassificationResults =
            classificationResultArea.children.length > 0 &&
            !classificationResultArea.querySelector(
                "#result-template-non-melon"
            );
        feedbackSection.classList.toggle("d-none", !hasClassificationResults);

        updateBboxButtonStates();
    };

    // [PERBAIKAN] Fungsi displayResults sekarang menjadi "controller"
    // Ia memutuskan apakah perlu load gambar baru atau cukup re-render UI
    const displayResults = (data) => {
        // Jika sumber gambar yang ada berbeda dengan data baru, muat ulang gambarnya.
        // Ini akan terjadi saat unggah/trigger pertama kali.
        if (resultImageDisplay.src !== data.image_base64_data) {
            resultImageDisplay.src = data.image_base64_data;
            resultImageDisplay.onload = () => renderResultsUI(data);
            resultImageDisplay.onerror = () => {
                pipelineErrorDisplay.textContent =
                    "Gagal memuat data gambar base64.";
                pipelineErrorDisplay.classList.remove("d-none");
                hideOverlay();
            };
        } else {
            // Jika gambar sudah sama, cukup panggil re-render.
            // Ini akan terjadi saat hapus BBox, re-klasifikasi, dll.
            renderResultsUI(data);
        }
    };

    const handleReclassify = async () => {
        deactivateAllModes();
        if (!currentApiData.s3_path) {
            alert("Data upload tidak ditemukan. Silakan unggah ulang gambar.");
            return;
        }
        if (currentBboxes.length === 0) {
            alert(
                'Tidak ada Bounding Box untuk diklasifikasi. Gambar BBox terlebih dahulu atau gunakan "Deteksi Ulang".'
            );
            return;
        }

        showOverlay("Mengklasifikasi ulang dengan Bbox saat ini...");
        const relativeBboxes = currentBboxes.map((bbox) => ({
            cx: (bbox.x + bbox.w / 2) / imageRenderData.naturalW,
            cy: (bbox.y + bbox.h / 2) / imageRenderData.naturalH,
            w: bbox.w / imageRenderData.naturalW,
            h: bbox.h / imageRenderData.naturalH,
        }));

        try {
            const payload = {
                s3_path: currentApiData.s3_path,
                filename: currentApiData.filename,
                user_provided_bboxes: relativeBboxes,
            };
            const response = await fetch(classifyUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok)
                throw new Error(result.message || "Gagal klasifikasi ulang.");

            // ▼▼▼ PERBAIKAN UTAMA ADA DI SINI ▼▼▼
            // Perbarui state aplikasi dengan hasil baru dari server
            Object.assign(currentApiData, result);
            // Panggil displayResults dengan state yang sudah sinkron
            displayResults(currentApiData);
            // ▲▲▲ AKHIR PERBAIKAN ▲▲▲
        } catch (error) {
            pipelineErrorDisplay.textContent = error.message;
            pipelineErrorDisplay.classList.remove("d-none");
        } finally {
            hideOverlay();
        }
    };

    // === [PERBAIKAN TOTAL] LOGIKA EDITOR BBOX ===
    const renderAllBboxes = () => {
        if (!bboxOverlay || !imageRenderData) return;
        bboxOverlay.innerHTML = "";
        const scaleX = imageRenderData.renderW / imageRenderData.naturalW;
        const scaleY = imageRenderData.renderH / imageRenderData.naturalH;

        currentBboxes.forEach((bbox) => {
            const boxDiv = document.createElement("div");
            boxDiv.className = "bbox-div";
            boxDiv.dataset.id = bbox.id;

            // [PERBAIKAN 2: Hapus penambahan offset karena overlay sudah diposisikan]
            // Koordinat sekarang relatif terhadap overlay yang ukurannya sudah pas dengan gambar
            boxDiv.style.left = `${bbox.x * scaleX}px`;
            boxDiv.style.top = `${bbox.y * scaleY}px`;
            // [AKHIR PERBAIKAN 2]

            boxDiv.style.width = `${bbox.w * scaleX}px`;
            boxDiv.style.height = `${bbox.h * scaleY}px`;

            if (isEditMode) {
                boxDiv.style.pointerEvents = "auto";
                if (bbox.id === selectedBoxId) {
                    boxDiv.classList.add("selected");
                    addResizeHandles(boxDiv);
                }
            } else {
                boxDiv.style.pointerEvents = "none";
            }
            bboxOverlay.appendChild(boxDiv);
        });
    };

    const renderClassificationCards = (data) => {
        classificationResultArea.innerHTML = ""; // Selalu kosongkan dulu

        // BAGIAN 2: TAMPILKAN KARTU HASIL INDIVIDU
        if (!data.all_results || data.all_results.length === 0) {
            const nonMelonNode = resultTemplateNonMelon.content.cloneNode(true);
            classificationResultArea.appendChild(nonMelonNode);
        } else {
            data.all_results.forEach((result, index) => {
                const cardNode =
                    multiResultCardTemplate.content.cloneNode(true);
                const { classification, confidence_scores, error_message } =
                    result;
                cardNode.querySelector(".bbox-number").textContent = index + 1;

                if (classification === "error") {
                    const errorDiv = cardNode.querySelector(
                        ".result-error-message"
                    );
                    errorDiv.textContent =
                        error_message || "Terjadi kesalahan klasifikasi.";
                    errorDiv.classList.remove("d-none");
                    cardNode.querySelector(
                        ".card-body > .score-item:nth-of-type(1)"
                    ).style.display = "none";
                    cardNode.querySelector(
                        ".card-body > .score-item:nth-of-type(2)"
                    ).style.display = "none";
                } else {
                    const ripeScore = (confidence_scores.ripe || 0) * 100;
                    const unripeScore = (confidence_scores.unripe || 0) * 100;
                    const ripeBar = cardNode.querySelector(".score-bar-ripe");
                    const unripeBar =
                        cardNode.querySelector(".score-bar-unripe");
                    const ripeValue =
                        cardNode.querySelector(".score-value-ripe");
                    const unripeValue = cardNode.querySelector(
                        ".score-value-unripe"
                    );

                    ripeBar.style.width = `${ripeScore.toFixed(1)}%`;
                    ripeBar.setAttribute("aria-valuenow", ripeScore);
                    ripeValue.textContent = `${ripeScore.toFixed(1)}%`;

                    unripeBar.style.width = `${unripeScore.toFixed(1)}%`;
                    unripeBar.setAttribute("aria-valuenow", unripeScore);
                    unripeValue.textContent = `${unripeScore.toFixed(1)}%`;
                }
                classificationResultArea.appendChild(cardNode);
            });
        }

        // BAGIAN 3: HITUNG DAN TAMPILKAN KARTU RATA-RATA KESELURUHAN
        const existingOverallCard = document.getElementById("overall-card");
        if (existingOverallCard) existingOverallCard.remove();

        if (data.all_results && data.all_results.length > 1) {
            let totalRipe = 0,
                totalUnripe = 0,
                validResultsCount = 0;
            data.all_results.forEach((result) => {
                if (
                    result.classification !== "error" &&
                    result.confidence_scores
                ) {
                    totalRipe += result.confidence_scores.ripe || 0;
                    totalUnripe += result.confidence_scores.unripe || 0;
                    validResultsCount++;
                }
            });
            if (validResultsCount > 0) {
                const avgRipe = (totalRipe / validResultsCount) * 100;
                const avgUnripe = (totalUnripe / validResultsCount) * 100;
                const finalVerdict =
                    avgRipe > avgUnripe ? "MATANG" : "BELUM MATANG";
                const cardNode =
                    overallResultCardTemplate.content.cloneNode(true);
                const overallCardDiv = cardNode.querySelector(".card");
                overallCardDiv.id = "overall-card";
                cardNode.getElementById(
                    "overall-ripe-score"
                ).textContent = `${avgRipe.toFixed(1)}%`;
                cardNode.getElementById(
                    "overall-unripe-score"
                ).textContent = `${avgUnripe.toFixed(1)}%`;
                cardNode.getElementById("overall-verdict").textContent =
                    finalVerdict;
                classificationResultArea.prepend(cardNode);
            }
        }
    };
    // ▲▲▲▲▲ AKHIR FUNGSI BARU ▲▲▲▲▲

    function addResizeHandles(boxElement) {
        if (!boxElement || boxElement.querySelector(".bbox-handle")) return;
        ["nw", "ne", "sw", "se", "n", "s", "e", "w"].forEach((type) => {
            const handle = document.createElement("div");
            handle.className = `bbox-handle handle-${type}`;
            handle.dataset.handleType = type;
            boxElement.appendChild(handle);
        });
    }

    function getRelativeCoords(evt, relativeToElement) {
        const rect = relativeToElement.getBoundingClientRect();
        const point = evt.touches ? evt.touches[0] : evt; // Ambil data sentuhan pertama jika ada

        return {
            x: Math.max(0, Math.min(point.clientX - rect.left, rect.width)),
            y: Math.max(0, Math.min(point.clientY - rect.top, rect.height)),
        };
    }

    function selectAnnotation(id) {
        selectedBoxId = id;

        // ▼▼▼▼▼ LOGIKA INDIKATOR DIMULAI DI SINI ▼▼▼▼▼
        if (editBboxBtn) {
            // Pastikan tombolnya ada
            if (id !== -1) {
                // Jika ada BBox yang dipilih, cari urutannya
                const bboxIndex = currentBboxes.findIndex((b) => b.id === id);
                if (bboxIndex !== -1) {
                    // Ubah teks tombol untuk menampilkan nomor BBox
                    editBboxBtn.innerHTML = `<i class="fas fa-edit"></i> Ubah Bbox #${
                        bboxIndex + 1
                    }`;
                }
            } else {
                // Jika tidak ada BBox yang dipilih, kembalikan ke teks semula
                editBboxBtn.innerHTML = `<i class="fas fa-edit"></i> <span class="d-none d-md-inline">Ubah</span>`;
            }
        }
        // ▲▲▲▲▲ LOGIKA INDIKATOR SELESAI ▲▲▲▲▲

        renderAllBboxes();
        updateBboxButtonStates();
    }

    function handleMouseDown(e) {
        if (navigator.vibrate) {
            navigator.vibrate(50);
        }

        if (e.button !== 0 || !imageRenderData) return;

        // [PERBAIKAN] Deklarasikan SEKALI dengan benar, dan langsung gunakan.
        const relativePos = getRelativeCoords(e, bboxOverlay);
        if (!relativePos) return; // Pengecekan tetap di sini.

        const target = e.target;

        if (target.classList.contains("bbox-handle")) {
            if (!isEditMode) return;
            e.stopPropagation();
            const boxElement = target.closest(".bbox-div");
            const boxId = parseInt(boxElement.dataset.id, 10);
            interactionState = {
                type: "resize",
                targetId: boxId,
                handleType: target.dataset.handleType,
                startX: e.clientX,
                startY: e.clientY,
                initialBox: { ...currentBboxes.find((b) => b.id === boxId) },
            };
        } else if (target.classList.contains("bbox-div") && isEditMode) {
            e.stopPropagation();
            const boxId = parseInt(target.dataset.id, 10);
            selectAnnotation(boxId);
            interactionState = {
                type: "move",
                targetId: boxId,
                startX: e.clientX,
                startY: e.clientY,
                initialBox: { ...currentBboxes.find((b) => b.id === boxId) },
            };
        } else if (isDrawingMode && target === bboxOverlay) {
            e.stopPropagation();
            selectAnnotation(-1);

            // === LOGIKA BARU DIMULAI DI SINI ===
            const newBoxDiv = document.createElement("div");
            newBoxDiv.className = "bbox-div drawing"; // Beri class drawing
            newBoxDiv.style.left = `${relativePos.x}px`;
            newBoxDiv.style.top = `${relativePos.y}px`;
            newBoxDiv.style.width = "0px";
            newBoxDiv.style.height = "0px";
            bboxOverlay.appendChild(newBoxDiv);

            interactionState = {
                type: "draw",
                targetBoxElement: newBoxDiv, // Simpan elemen div-nya
                startX: relativePos.x,
                startY: relativePos.y,
            };
            // === AKHIR LOGIKA BARU ===
        } else {
            selectAnnotation(-1);
        }

        if (interactionState.type !== "none") {
            document.addEventListener("mousemove", handleMouseMove);
            document.addEventListener("mouseup", handleMouseUp, { once: true });

            document.addEventListener("touchmove", handleTouchMove, {
                passive: false,
            });
            document.addEventListener("touchend", handleTouchEnd, {
                once: true,
            });
        }
    }

    function handleMouseMove(e) {
        if (interactionState.type === "none" || !imageRenderData) return;
        e.preventDefault();

        // [PERBAIKAN] Hapus deklarasi `coords` yang mubazir.
        // Cukup panggil fungsi sekali dan simpan ke variabel `pos`.
        const pos = getRelativeCoords(e, bboxOverlay);
        if (!pos) return;

        const { type } = interactionState;

        // === LOGIKA BARU UNTUK MENGGAMBAR ===
        if (type === "draw") {
            const { targetBoxElement, startX, startY } = interactionState;
            targetBoxElement.style.left = `${Math.min(startX, pos.x)}px`;
            targetBoxElement.style.top = `${Math.min(startY, pos.y)}px`;
            targetBoxElement.style.width = `${Math.abs(pos.x - startX)}px`;
            targetBoxElement.style.height = `${Math.abs(pos.y - startY)}px`;
            return; // Keluar setelah selesai
        }
        // === AKHIR LOGIKA BARU ===

        // Logika untuk move dan resize (sedikit penyesuaian)
        const { targetId, startX, startY, initialBox, handleType } =
            interactionState;
        const boxToUpdate = currentBboxes.find((b) => b.id === targetId);
        if (!boxToUpdate) return;

        const scaleX = imageRenderData.naturalW / imageRenderData.renderW;
        const scaleY = imageRenderData.naturalH / imageRenderData.renderH;
        const dx = (e.clientX - startX) * scaleX;
        const dy = (e.clientY - startY) * scaleY;

        if (type === "move") {
            let newX = initialBox.x + dx;
            let newY = initialBox.y + dy;
            newX = Math.max(
                0,
                Math.min(newX, imageRenderData.naturalW - boxToUpdate.w)
            );
            newY = Math.max(
                0,
                Math.min(newY, imageRenderData.naturalH - boxToUpdate.h)
            );
            boxToUpdate.x = newX;
            boxToUpdate.y = newY;
        } else if (type === "resize") {
            let { x, y, w, h } = initialBox;
            if (handleType.includes("e"))
                w = Math.min(w + dx, imageRenderData.naturalW - x);
            if (handleType.includes("s"))
                h = Math.min(h + dy, imageRenderData.naturalH - y);
            if (handleType.includes("w")) {
                const newW = w - dx;
                x += w - newW;
                w = newW;
                if (x < 0) {
                    w += x;
                    x = 0;
                }
            }
            if (handleType.includes("n")) {
                const newH = h - dy;
                y += h - newH;
                h = newH;
                if (y < 0) {
                    h += y;
                    y = 0;
                }
            }
            if (w >= minBoxSize) {
                boxToUpdate.w = w;
                boxToUpdate.x = x;
            }
            if (h >= minBoxSize) {
                boxToUpdate.h = h;
                boxToUpdate.y = y;
            }
        }
        renderAllBboxes();
    }

    function handleMouseUp(e) {
        document.removeEventListener("mousemove", handleMouseMove);
        const { type, targetBoxElement } = interactionState;

        if (type === "draw" && targetBoxElement) {
            const finalWidth = parseFloat(targetBoxElement.style.width);
            const finalHeight = parseFloat(targetBoxElement.style.height);

            if (finalWidth < minBoxSize || finalHeight < minBoxSize) {
                targetBoxElement.remove();
            } else {
                const scaleX =
                    imageRenderData.naturalW / imageRenderData.renderW;
                const scaleY =
                    imageRenderData.naturalH / imageRenderData.renderH;
                const newBboxData = {
                    id: nextBoxId++,
                    x: parseFloat(targetBoxElement.style.left) * scaleX,
                    y: parseFloat(targetBoxElement.style.top) * scaleY,
                    w: finalWidth * scaleX,
                    h: finalHeight * scaleY,
                };
                currentBboxes.push(newBboxData);

                // ▼▼▼ TAMBAHKAN LOGIKA BARU INI ▼▼▼
                // Buat placeholder untuk kartu hasil agar UI tetap sinkron
                const newResultPlaceholder = {
                    bbox: newBboxData,
                    classification: "error",
                    error_message: "Bbox baru, perlu diklasifikasi ulang.",
                    confidence_scores: {},
                };
                if (!currentApiData.all_results) {
                    currentApiData.all_results = [];
                }
                currentApiData.all_results.push(newResultPlaceholder);

                // Panggil render kartu untuk memperbarui tampilan
                renderClassificationCards(currentApiData);
                // ▲▲▲ AKHIR LOGIKA BARU ▲▲▲

                targetBoxElement.classList.remove("drawing");
                targetBoxElement.dataset.id = newBboxData.id;
                renderAllBboxes();

                const isTouchEvent =
                    e.changedTouches && e.changedTouches.length > 0;

                if (isTouchEvent) {
                    // UNTUK PERANGKAT SENTUH (SMARTPHONE/TABLET):
                    // 1. Tetap dalam mode menggambar agar pengguna bisa langsung membuat Bbox lain.
                    // 2. Pilih anotasi yang baru saja dibuat.
                    selectAnnotation(newBboxData.id);
                } else {
                    // UNTUK PERANGKAT DENGAN MOUSE (PC/LAPTOP):
                    // 1. Pertahankan logika asli yang Anda suka.
                    // 2. Mode menggambar dinonaktifkan.
                    isDrawingMode = false;
                    addBboxBtn.classList.remove("active");
                    // 3. Mode edit diaktifkan secara otomatis.
                    isEditMode = true;
                    editBboxBtn.classList.add("active");
                    // 4. Pilih anotasi yang baru dibuat dalam mode edit.
                    selectAnnotation(newBboxData.id);
                }
                // ▲▲▲ AKHIR DARI KODE PENGGANTI ▲▲▲
            }
        }

        document.removeEventListener("mousemove", handleMouseMove);
        document.removeEventListener("mouseup", handleMouseUp);
        document.removeEventListener("touchmove", handleTouchMove);
        document.removeEventListener("touchend", handleTouchEnd);

        interactionState = { type: "none" };
    }

    // **LANGKAH 2: Buat handler untuk TOUCH events**

    function handleTouchStart(e) {
        // Hanya proses sentuhan pertama, abaikan multi-touch
        if (e.touches.length > 1) return;

        // Panggil handler mouse yang sudah ada, tapi dengan event touch
        handleMouseDown(e);
    }

    function handleTouchMove(e) {
        // Mencegah layar scrolling saat menggambar BBox
        e.preventDefault();

        handleMouseMove(e);
    }

    function handleTouchEnd(e) {
        // Karena touchend tidak memiliki info koordinat, kita gunakan event mouseup
        handleMouseUp(e);
    }

    // === EVENT LISTENERS ===
    if (uploadForm) {
        uploadForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            const selectedFile = imageInput.files[0];
            if (!selectedFile) return;

            resetUI(true);
            showOverlay("Mengunggah & Menganalisis...");

            const reader = new FileReader();
            reader.onload = (event) => {
                // [PERBAIKAN] Tambahkan pengecekan untuk memastikan elemen ada sebelum digunakan
                if (originalImageDisplay) {
                    originalImageDisplay.src = event.target.result;
                } else {
                    // Jika elemen tidak ditemukan, berikan pesan error yang jelas di console
                    console.error(
                        "KRITIS: Elemen dengan ID 'original-image-display' tidak ditemukan di DOM. Klasifikasi tidak dapat menampilkan gambar asli."
                    );
                    // Anda juga bisa menampilkan alert ke pengguna di sini jika perlu
                    // alert("Terjadi error pada antarmuka. Tidak dapat menampilkan gambar asli.");
                }
            };
            reader.readAsDataURL(selectedFile);

            const formData = new FormData();
            formData.append("imageFile", selectedFile);

            try {
                const uploadResponse = await fetch(uploadImageUrl, {
                    method: "POST",
                    headers: {
                        "X-CSRF-TOKEN": csrfToken,
                        Accept: "application/json",
                    },
                    body: formData,
                });
                const uploadResult = await uploadResponse.json();
                if (!uploadResponse.ok)
                    throw new Error(
                        uploadResult.message || "Gagal mengunggah file."
                    );

                // ======== PERUBAHAN DIMULAI DI SINI ========
                // Buat payload HANYA dengan data relevan untuk klasifikasi
                const classifyPayload = {
                    s3_path: uploadResult.s3_path,
                    filename: uploadResult.filename,
                };

                const classifyResponse = await fetch(classifyUrl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                        Accept: "application/json",
                    },
                    body: JSON.stringify(classifyPayload), // Kirim payload yang bersih
                });
                const classifyResult = await classifyResponse.json();
                if (!classifyResponse.ok)
                    throw new Error(
                        classifyResult.message || "Gagal klasifikasi awal."
                    );

                // Setelah mendapat hasil, TIMPA currentApiData dengan hasil BARU dari server.
                // Ini mencegah properti lama (seperti feedback_given) dari gambar sebelumnya bocor.
                Object.assign(currentApiData, classifyResult);

                resultFilenameDisplay.textContent = selectedFile.name;
                displayResults(currentApiData); // Panggil dengan state yang sudah bersih dan lengkap
                // ======== AKHIR PERUBAHAN ========
            } catch (error) {
                resultSection.classList.remove("d-none");
                resultMainCard.classList.add("d-none");
                pipelineErrorDisplay.textContent = error.message;
                pipelineErrorDisplay.classList.remove("d-none");
            } finally {
                hideOverlay();
            }
        });
    }

    if (modeToggle) {
        modeToggle.addEventListener("change", () => {
            const isPiMode = modeToggle.checked;
            manualUploadMode.classList.toggle("d-none", isPiMode);
            piCameraMode.classList.toggle("d-none", !isPiMode);
            if (modeLabel)
                modeLabel.textContent = isPiMode
                    ? "Mode Kamera Pi"
                    : "Mode Unggah Manual";
        });
    }

    // --- FUNGSI BARU UNTUK KONTROL STREAM ---
    function startVideoStream() {
        if (isLoadingStream || isStreaming) return;

        isLoadingStream = true;
        toggleStreamBtn.disabled = true;
        toggleStreamBtn.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Menghubungkan...';

        piVideoStream.onload = null;
        piVideoStream.onerror = null;

        connectionTimeout = setTimeout(() => {
            console.log("Connection timed out after 10 seconds.");
            if (!isStreaming) {
                piVideoStream.onerror();
            }
        }, 10000);

        piVideoStream.onload = () => {
            console.log("Stream started successfully.");
            clearTimeout(connectionTimeout);
            isLoadingStream = false;
            isStreaming = true;
            toggleStreamBtn.disabled = false;
            toggleStreamBtn.innerHTML =
                '<i class="fas fa-eye-slash"></i> Hentikan Preview';
            toggleStreamBtn.classList.remove("btn-danger", "btn-info");
            toggleStreamBtn.classList.add("btn-warning");
        };

        piVideoStream.onerror = () => {
            // ▼▼▼ PERUBAHAN KUNCI ADA DI BARIS BERIKUTNYA ▼▼▼
            piVideoStream.onerror = null; // Putus rantai pemicu agar fungsi ini tidak dipanggil lagi!

            console.error(
                "Failed to load video stream. Pi server might be down."
            );
            clearTimeout(connectionTimeout);

            isLoadingStream = false;
            isStreaming = false;
            toggleStreamBtn.disabled = false;
            toggleStreamBtn.innerHTML =
                '<i class="fas fa-exclamation-triangle"></i> Gagal, Coba Lagi';
            toggleStreamBtn.classList.remove("btn-info", "btn-warning");
            toggleStreamBtn.classList.add("btn-danger");

            if (piVideoStream.src) {
                piVideoStream.src = "";
            }
            videoStreamContainer.classList.add("d-none");

            // Alert sekarang hanya akan muncul satu kali.
            alert(
                "Gagal memulai video preview.\n\nKemungkinan penyebab:\n1. Server Flask di Raspberry Pi belum dijalankan.\n2. Pi tidak terhubung ke jaringan WiFi yang sama.\n\nPastikan server sudah berjalan, lalu coba lagi."
            );
        };

        piVideoStream.src = piStreamProxyUrl + "?t=" + new Date().getTime();
        videoStreamContainer.classList.remove("d-none");
    }

    function stopVideoStream() {
        // [PERUBAHAN KUNCI] Saat menghentikan, pastikan juga membatalkan timer koneksi jika ada
        if (connectionTimeout) {
            clearTimeout(connectionTimeout);
        }

        piVideoStream.onload = null;
        piVideoStream.onerror = null;

        if (piVideoStream) {
            piVideoStream.src = "";
        }
        if (videoStreamContainer) {
            videoStreamContainer.classList.add("d-none");
        }

        if (toggleStreamBtn) {
            toggleStreamBtn.disabled = false;
            toggleStreamBtn.innerHTML =
                '<i class="fas fa-eye"></i> Mulai Preview';
            toggleStreamBtn.classList.remove("btn-warning", "btn-danger");
            toggleStreamBtn.classList.add("btn-info");
        }
        isStreaming = false;
        isLoadingStream = false;
    }

    // --- EVENT LISTENERS YANG DIPERBARUI ---
    if (toggleStreamBtn) {
        toggleStreamBtn.addEventListener("click", () => {
            if (!isStreaming) {
                startVideoStream();
            } else {
                stopVideoStream();
            }
        });
    }

    if (triggerPiBtn) {
        triggerPiBtn.addEventListener("click", () => {
            // Selalu hentikan stream sebelum memulai capture
            if (isStreaming || isLoadingStream) {
                stopVideoStream();
            }

            // Beri jeda singkat agar koneksi stream di server benar-benar ditutup
            setTimeout(() => {
                // Lanjutkan dengan logika EventSource yang sudah ada
                resetUI(true);
                showOverlay("Mempersiapkan koneksi...");
                const eventSource = new EventSource(triggerPiUrl);

                // Listener untuk event 'update' yang berisi pesan progress
                eventSource.addEventListener("update", (event) => {
                    const data = JSON.parse(event.data);
                    overlayText.textContent = data.message;
                });

                // Listener untuk event 'final_result' yang berisi data hasil akhir
                eventSource.addEventListener("final_result", (event) => {
                    const result = JSON.parse(event.data);
                    Object.assign(currentApiData, result);
                    resultFilenameDisplay.textContent =
                        result.filename || "Gambar dari Pi";
                    originalImageDisplay.src = result.image_base64_data;
                    displayResults(currentApiData);
                    hideOverlay();
                });

                // Listener untuk event 'error' dari server
                eventSource.addEventListener("error", (event) => {
                    console.error("An error occurred with the stream:", event);
                    let errorMessage =
                        "Koneksi ke server terputus atau terjadi kesalahan.";

                    // Coba parsing jika ada pesan custom dari server
                    if (event.data) {
                        try {
                            const data = JSON.parse(event.data);
                            if (data.message) errorMessage = data.message;
                        } catch (e) {
                            // biarkan pesan error default
                        }
                    }

                    // Tampilkan error di UI
                    resultSection.classList.remove("d-none");
                    resultMainCard.classList.add("d-none");
                    manualBboxControls.classList.add("d-none");
                    feedbackSection.classList.add("d-none");
                    pipelineErrorDisplay.classList.remove("d-none");

                    pipelineErrorDisplay.textContent =
                        "Koneksi ke server terputus atau terjadi kesalahan.";
                    hideOverlay();
                    eventSource.close();
                });

                // Listener untuk event 'close' yang dikirim server
                eventSource.addEventListener("close", (event) => {
                    eventSource.close();
                });
            }, 500); // Jeda 500ms
        });
    }

    if (bboxOverlay) {
        bboxOverlay.addEventListener("mousedown", handleMouseDown);
        bboxOverlay.addEventListener("touchstart", handleTouchStart, {
            passive: false,
        });
    }

    // [PERBAIKAN POIN 8] Logika baru untuk tombol kontrol BBox
    addBboxBtn?.addEventListener("click", () => {
        // Jika SUDAH dalam mode gambar, klik lagi berarti keluar/batal.
        if (isDrawingMode) {
            deactivateAllModes();
        } else {
            // Jika BELUM dalam mode gambar, maka aktifkan mode gambar.
            deactivateAllModes(); // Selalu reset dulu untuk mematikan mode lain
            isDrawingMode = true;
            addBboxBtn.classList.add("active");
            cancelDrawBtn.classList.remove("d-none");
            bboxOverlay.style.cursor = "crosshair";
            // Tampilkan instruksi untuk mode gambar
            if (bboxModeInstruction) {
                bboxModeInstruction.textContent =
                    "Klik dan seret pada area gambar untuk membuat Bbox baru.";
                bboxModeInstruction.classList.remove("d-none");
            }
        }
    });

    editBboxBtn?.addEventListener("click", () => {
        // Jika SUDAH dalam mode edit, klik lagi berarti keluar/batal.
        if (isEditMode) {
            deactivateAllModes();
        } else {
            // Jika BELUM dalam mode edit, maka aktifkan mode edit.
            deactivateAllModes(); // Selalu reset dulu untuk mematikan mode lain (spt gambar)
            isEditMode = true;
            editBboxBtn.classList.add("active");
            cancelDrawBtn.classList.remove("d-none");
            renderAllBboxes(); // Render ulang agar BBox bisa di-klik

            // Tampilkan instruksi untuk mode edit
            if (bboxModeInstruction) {
                bboxModeInstruction.textContent =
                    "Klik pada salah satu Bbox untuk memilih, mengubah ukuran, memindahkannya, atau hapus (ikon sampah = satu bbox | ikon x = semua bbox).";
                bboxModeInstruction.classList.remove("d-none");
            }
        }
    });

    cancelDrawBtn?.addEventListener("click", () => {
        deactivateAllModes();
    });

    // Listener untuk tombol Hapus Bbox Terpilih
    deleteSelectedBboxBtn?.addEventListener("click", () => {
        if (selectedBoxId === -1) return;

        const boxIndex = currentBboxes.findIndex((b) => b.id === selectedBoxId);
        if (boxIndex === -1) return;

        if (confirm(`Anda yakin ingin menghapus Bbox #${boxIndex + 1}?`)) {
            // Hapus BBox dari state
            currentBboxes.splice(boxIndex, 1);
            if (currentApiData.all_results) {
                currentApiData.all_results.splice(boxIndex, 1);
            }

            // Batalkan pilihan BBox
            selectAnnotation(-1);

            // Render ulang HANYA BBox dan Kartu Hasil
            renderAllBboxes();
            renderClassificationCards(currentApiData);
        }
    });

    clearAllBboxBtn?.addEventListener("click", () => {
        if (
            currentBboxes.length > 0 &&
            confirm("Anda yakin ingin menghapus semua Bounding Box?")
        ) {
            currentBboxes = [];
            // ▼▼▼ TAMBAHKAN BARIS INI UNTUK MEMBERSIHKAN DATA HASIL ▼▼▼
            if (currentApiData.all_results) {
                currentApiData.all_results = [];
            }
            classificationResultArea.innerHTML = "";
            const nonMelonNode = resultTemplateNonMelon.content.cloneNode(true);
            classificationResultArea.appendChild(nonMelonNode);
            deactivateAllModes();
            updateBboxButtonStates();
        }
    });

    reEstimateBtn?.addEventListener("click", async () => {
        if (
            !currentApiData.s3_path ||
            !confirm(
                "Ini akan menghapus semua Bbox manual dan menjalankan ulang deteksi otomatis. Lanjutkan?"
            )
        )
            return;
        showOverlay("Menjalankan ulang deteksi otomatis...");
        deactivateAllModes();
        try {
            const payload = {
                s3_path: currentApiData.s3_path,
                filename: currentApiData.filename,
                user_provided_bboxes: null,
            };
            const response = await fetch(classifyUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok)
                throw new Error(result.message || "Gagal deteksi ulang.");

            // ▼▼▼ TAMBAHKAN BARIS INI UNTUK MEMPERBARUI STATE APLIKASI ▼▼▼
            Object.assign(currentApiData, result);
            // ▲▲▲ AKHIR PENAMBAHAN ▲▲▲

            displayResults(currentApiData); // Panggil dengan state yang sudah sinkron
        } catch (error) {
            resultMainCard.classList.add("d-none");
            pipelineErrorDisplay.textContent = error.message;
            pipelineErrorDisplay.classList.remove("d-none");
        } finally {
            hideOverlay();
        }
    });

    reclassifyBtn?.addEventListener("click", handleReclassify);

    if (feedbackSection) {
        feedbackSection.addEventListener("click", async (e) => {
            const target = e.target.closest("button");
            // Pastikan yang diklik adalah tombol di dalam prompt awal
            if (!target || !target.closest("#feedback-initial-prompt")) return;

            const feedbackAction = target.dataset.feedback; // Hasilnya 'correct' atau 'incorrect'
            if (!feedbackAction) return;

            // Nonaktifkan semua tombol di prompt dan tampilkan overlay
            feedbackInitialPrompt
                .querySelectorAll("button")
                .forEach((btn) => (btn.disabled = true));
            showOverlay("Menyimpan feedback...");

            try {
                // [PERBAIKAN 1] Ambil aksi dari tombol yang diklik di awal.
                const feedbackAction = target.dataset.feedback; // Hasil: 'correct' atau 'incorrect'

                // [PERBAIKAN 2] Buat payload dengan benar menggunakan variabel di atas.
                const payload = {
                    s3_temp_path: currentApiData.s3_path,
                    original_filename: currentApiData.filename,
                    correct_label:
                        feedbackAction === "incorrect"
                            ? "correction_needed"
                            : "ripe", // 'ripe' adalah placeholder untuk konfirmasi "Ya, Sesuai".
                };

                // [DIHAPUS] Baris 'updateFeedbackDisplay' yang salah telah dihapus dari sini.

                const response = await fetch(feedbackUrl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                        Accept: "application/json",
                    },
                    body: JSON.stringify(payload),
                });

                // [DIHAPUS] Deklarasi `const feedbackType` yang salah tempat telah dihapus.

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(
                        data.message || "Gagal mengirim feedback ke server."
                    );
                }

                // Tampilkan pesan konfirmasi di toast (kode ini sudah benar).
                if (feedbackToast && data.message) {
                    const toastBody =
                        feedbackToastEl.querySelector(".toast-body");
                    toastBody.textContent = data.message;
                    feedbackToast.show();
                }

                // Perbarui notifikasi antrian anotasi (kode ini sudah benar).
                const newCount = data.new_pending_annotation_count;
                if (
                    newCount !== undefined &&
                    pendingAnnotationCountSpan &&
                    annotationNotificationArea
                ) {
                    pendingAnnotationCountSpan.textContent = newCount;
                    annotationNotificationArea.classList.toggle(
                        "d-none",
                        newCount <= 0
                    );
                }

                // --- [PERBAIKAN 3] Menyeragamkan pembaruan UI dan State ---
                // Update state aplikasi secara "bedah" (surgical) seperti pada kode asli.
                currentApiData.feedback_given = true;

                // Simpan juga detail feedback. Ini penting agar UI tetap konsisten.
                currentApiData.feedback_details = {
                    feedback_type:
                        payload.correct_label === "correction_needed"
                            ? "correction_needed"
                            : "confirmed",
                };

                // Gunakan fungsi terpusat untuk memperbarui UI. Ini akan menyembunyikan
                // tombol "Ya/Tidak" dan menampilkan pesan "Anda sudah memberi feedback".
                updateFeedbackDisplay(currentApiData.feedback_details);
            } catch (error) {
                alert(`Error: ${error.message}`);
                // Jika gagal, aktifkan kembali tombolnya (kode ini sudah benar).
                feedbackInitialPrompt
                    .querySelectorAll("button")
                    .forEach((btn) => (btn.disabled = false));
            } finally {
                hideOverlay();
            }
        });
    }

    document
        .getElementById("delete-feedback-btn")
        ?.addEventListener("click", async () => {
            if (!currentApiData.filename) return;

            const deleteBtn = document.getElementById("delete-feedback-btn");
            deleteBtn.disabled = true;
            showOverlay("Menghapus feedback...");

            try {
                const response = await fetch(deleteFeedbackUrl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                        Accept: "application/json",
                    },
                    body: JSON.stringify({
                        original_filename: currentApiData.filename,
                    }),
                });
                const data = await response.json();
                if (!response.ok)
                    throw new Error(
                        data.message || "Gagal menghapus feedback."
                    );

                // PERBAIKAN: Panggil fungsi terpusat untuk reset tampilan feedback
                updateFeedbackDisplay(null);
                currentApiData.feedback_given = false;
                currentApiData.feedback_details = null;

                feedbackGivenState.classList.add("d-none");
                feedbackInitialPrompt.style.display = "block";
                // Bersihkan juga display feedback sebelumnya

                if (previousFeedbackDisplay) {
                    previousFeedbackDisplay.textContent = "";
                }
                feedbackInitialPrompt
                    .querySelectorAll("button")
                    .forEach((btn) => (btn.disabled = false));

                // --- PERBAIKAN UI NOTIFIKASI ---
                const newCount = data.new_pending_annotation_count;
                if (
                    newCount !== undefined &&
                    pendingAnnotationCountSpan &&
                    annotationNotificationArea
                ) {
                    pendingAnnotationCountSpan.textContent = newCount;
                    // Kelola visibilitas div notifikasi
                    if (newCount > 0) {
                        annotationNotificationArea.classList.remove("d-none");
                    } else {
                        annotationNotificationArea.classList.add("d-none");
                    }
                }
                // --- AKHIR PERBAIKAN ---

                alert(data.message);
            } catch (error) {
                alert(`Error: ${error.message}`);
                deleteBtn.disabled = false;
            } finally {
                hideOverlay();
            }
        });
    // ▲▲▲ AKHIR BLOK PENGGANTIAN ▲▲▲

    window.addEventListener("resize", () => {
        // [PERBAIKAN] Pastikan listener ini ada dan benar
        if (
            !resultSection ||
            resultSection.classList.contains("d-none") ||
            !imageRenderData
        )
            return;

        // Hitung ulang data render
        imageRenderData = calculateImageRenderData();

        // Atur ulang posisi dan ukuran overlay agar pas lagi
        if (bboxOverlay) {
            bboxOverlay.style.left = `${imageRenderData.offsetX}px`;
            bboxOverlay.style.top = `${imageRenderData.offsetY}px`;
            bboxOverlay.style.width = `${imageRenderData.renderW}px`;
            bboxOverlay.style.height = `${imageRenderData.renderH}px`;
        }

        // Gambar ulang semua bbox dengan data yang baru
        renderAllBboxes();
    });

    // ▼▼▼ TAMBAHKAN BLOK KODE INI DI AKHIR EVENT DOMContentLoaded ▼▼▼
    /**
     * Menambahkan overlay loading untuk link navigasi utama.
     * Caranya: Tambahkan class "nav-loader-link" pada tag <a> di navbar Anda.
     * Contoh: <a class="nav-link nav-loader-link" href="{{ route('annotate.index') }}">Anotasi</a>
     */
    document.body.addEventListener("click", function (e) {
        // Cari elemen .nav-loader-link terdekat dari target yang di-klik
        const link = e.target.closest(".nav-loader-link");

        // Jika tidak ditemukan, atau jika ini klik kanan/tengah, abaikan
        if (!link || e.ctrlKey || e.metaKey || e.button === 1) {
            return;
        }

        // Hentikan navigasi standar
        e.preventDefault();

        const destination = link.href;
        const pageName = link.dataset.pageName || "halaman";

        showOverlay(`Membuka ${pageName}...`);

        setTimeout(() => {
            window.location.href = destination;
        }, 150);
    });
    // ▲▲▲ AKHIR BLOK KODE BARU ▲▲▲

    // ▼▼▼ TAMBAHKAN BLOK KODE BARU INI ▼▼▼
    /**
     * Menambahkan overlay loading untuk form 'Hapus Cache'
     */
    const clearCacheForm = document.getElementById("clear-cache-form");
    if (clearCacheForm) {
        clearCacheForm.addEventListener("submit", function (e) {
            e.preventDefault();
            showOverlay("Membersihkan cache server...");
            setTimeout(() => {
                this.submit();
            }, 150);
        });
    }
    // ▲▲▲ AKHIR BLOK KODE BARU ▲▲▲
});
