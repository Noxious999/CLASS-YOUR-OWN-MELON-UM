/**
 * =========================================================================
 * == ANNOTATION.JS - VERSI FINAL LENGKAP (TERMASUK PERBAIKAN LAYAR SELESAI)
 * =========================================================================
 */
document.addEventListener("DOMContentLoaded", () => {
    // Guard clause
    if (document.body.dataset.annotationToolInitialized) return;
    document.body.dataset.annotationToolInitialized = "true";

    const statusIndicator = document.getElementById("status-indicator");
    const refreshQueueBtn = document.getElementById("refresh-queue-btn");

    // === KUMPULAN ELEMEN DOM & KONFIGURASI ===
    const D = {
        CONTAINER: document.getElementById("annotation-page-container"),
        UI_WRAPPER: document.getElementById("annotation-ui-wrapper"),
        COMPLETION_WRAPPER: document.getElementById("completion-view-wrapper"),
        NOTIFICATION_AREA: document.getElementById("notification-area"),
        THUMBNAIL_CONTAINER: document.getElementById("thumbnail-container"),
        ANNOTATION_IMAGE: document.getElementById("annotation-image"),
        ANNOTATION_CONTAINER: document.getElementById("annotation-container"),
        BBOX_OVERLAY: document.getElementById("bbox-overlay"),
        getCsrfToken: () =>
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute("content"),
        ITEMS_PER_PAGE: 11,
    };

    // === STATE APLIKASI TERPUSAT ===
    let state = {
        allFiles: [],
        currentPage: 1,
        currentImageS3Path: null,
        isSelectMode: false,
        selectedForDeletion: new Set(),
        isEstimatingBbox: false,
        imageRect: null,
        annotations: [],
        selectedBoxId: -1,
        nextBoxId: 0,
        interactionState: { type: "none" },
        minBoxSize: 8,
        uploadFromCompletionScreen: false, // <-- TAMBAHKAN BARIS INI
    };

    if (!D.CONTAINER) return;

    // === FUNGSI UTILITAS ===
    function showNotification(message, type = "info", duration = 5000) {
        const area = D.NOTIFICATION_AREA;
        if (!area) return;
        const alertClass = `alert-${type}`;
        const iconClass =
            type === "success"
                ? "fa-check-circle"
                : type === "danger"
                ? "fa-exclamation-triangle"
                : "fa-info-circle";
        if (area.querySelector(".alert")) area.querySelector(".alert").remove();
        const alertDiv = document.createElement("div");
        alertDiv.className = `alert ${alertClass} alert-dismissible fade show`;
        alertDiv.setAttribute("role", "alert");
        alertDiv.innerHTML = `<i class="fas ${iconClass} me-2"></i> ${message}<button type="button" class="btn-close btn-sm py-0" data-bs-dismiss="alert" aria-label="Close"></button>`;
        area.appendChild(alertDiv);
        if (duration > 0) setTimeout(() => alertDiv.remove(), duration);
    }
    function showLoadingIndicator(show, message = "Memproses...") {
        let loadingDiv = document.getElementById("page-loading-indicator");
        if (show) {
            if (!loadingDiv) {
                loadingDiv = document.createElement("div");
                loadingDiv.id = "page-loading-indicator";
                loadingDiv.style.cssText =
                    "position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: rgba(0,0,0,0.5); z-index: 10000; display: flex; justify-content: center; align-items: center;";
                document.body.appendChild(loadingDiv);
            }
            loadingDiv.innerHTML = `<div class="card p-3 d-flex flex-row align-items-center"><div class="spinner-border text-primary me-2" role="status"></div><span>${message}</span></div>`;
            loadingDiv.style.display = "flex";
        } else if (loadingDiv) {
            loadingDiv.style.display = "none";
        }
    }

    // === PENGELOLA PERUBAHAN DATA & UI INTI ===
    function handleDataChange(options = {}) {
        const {
            pathsToRemove = [],
            filesToAdd = [],
            nextImageDataFromServer = null,
        } = options;
        if (pathsToRemove.length > 0) {
            const removalSet = new Set(pathsToRemove);
            state.allFiles = state.allFiles.filter(
                (file) => !removalSet.has(file.s3Path)
            );
        }
        if (filesToAdd.length > 0) {
            state.allFiles.unshift(
                ...filesToAdd.filter((f) => f.imageUrl && f.thumbnailUrl)
            );
        }
        localStorage.setItem(
            "unannotatedFilesCache",
            JSON.stringify(state.allFiles)
        );

        if (state.allFiles.length === 0) {
            D.UI_WRAPPER.classList.add("hidden");
            D.COMPLETION_WRAPPER.classList.remove("hidden");
            localStorage.removeItem("annotationCurrentPage");
            return;
        }

        D.UI_WRAPPER.classList.remove("hidden");
        D.COMPLETION_WRAPPER.classList.add("hidden");

        const totalPages =
            Math.ceil(state.allFiles.length / D.ITEMS_PER_PAGE) || 1;
        if (state.currentPage > totalPages) state.currentPage = totalPages;
        renderGalleryPage(state.currentPage);

        let imageToLoad = null;
        if (nextImageDataFromServer) {
            imageToLoad =
                state.allFiles.find(
                    (f) => f.s3Path === nextImageDataFromServer.s3Path
                ) || null;
        }
        if (!imageToLoad || pathsToRemove.includes(state.currentImageS3Path)) {
            const startIndex = (state.currentPage - 1) * D.ITEMS_PER_PAGE;
            imageToLoad =
                state.allFiles[startIndex] || state.allFiles[0] || null;
        }

        if (imageToLoad) {
            updateMainImage(imageToLoad);
        } else {
            D.UI_WRAPPER.classList.add("hidden");
            D.COMPLETION_WRAPPER.classList.remove("hidden");
        }
    }
    function renderGalleryPage(page) {
        state.currentPage = page;
        localStorage.setItem("annotationCurrentPage", state.currentPage);
        const totalItems = state.allFiles.length;
        const totalPages = Math.ceil(totalItems / D.ITEMS_PER_PAGE) || 1;
        const start = (page - 1) * D.ITEMS_PER_PAGE;
        const end = start + D.ITEMS_PER_PAGE;
        const pageItems = state.allFiles.slice(start, end);

        D.THUMBNAIL_CONTAINER.innerHTML = "";
        pageItems.forEach((imgData) => {
            const thumbWrapper = document.createElement("div");
            thumbWrapper.className = "gallery-thumbnail-wrapper";
            if (state.isSelectMode) thumbWrapper.classList.add("select-mode");
            const img = document.createElement("img");
            img.src = imgData.thumbnailUrl;
            img.className = "gallery-thumbnail";
            img.dataset.s3Path = imgData.s3Path;
            if (imgData.s3Path === state.currentImageS3Path)
                img.classList.add("active-thumb");
            thumbWrapper.appendChild(img);
            img.addEventListener("click", (e) => {
                if (state.isSelectMode) {
                    e.currentTarget.parentElement
                        .querySelector(".thumbnail-checkbox")
                        ?.click();
                } else {
                    const s3Path = e.target.dataset.s3Path;
                    if (s3Path && s3Path !== state.currentImageS3Path) {
                        const imageData = state.allFiles.find(
                            (f) => f.s3Path === s3Path
                        );
                        if (imageData) updateMainImage(imageData);
                    }
                }
            });
            if (state.isSelectMode) {
                const checkbox = document.createElement("input");
                checkbox.type = "checkbox";
                checkbox.className = "thumbnail-checkbox";
                checkbox.dataset.s3Path = imgData.s3Path;
                checkbox.checked = state.selectedForDeletion.has(
                    imgData.s3Path
                );
                if (checkbox.checked) thumbWrapper.classList.add("selected");
                checkbox.addEventListener("change", (e) => {
                    const path = e.target.dataset.s3Path;
                    const wrapper = e.target.closest(
                        ".gallery-thumbnail-wrapper"
                    );
                    if (e.target.checked) {
                        state.selectedForDeletion.add(path);
                        wrapper?.classList.add("selected");
                    } else {
                        state.selectedForDeletion.delete(path);
                        wrapper?.classList.remove("selected");
                    }
                    const deleteBtn = document.getElementById(
                        "delete-selected-btn"
                    );
                    deleteBtn.textContent = `Hapus ${state.selectedForDeletion.size} Gambar`;
                    deleteBtn.disabled = state.selectedForDeletion.size === 0;
                });
                thumbWrapper.appendChild(checkbox);
            }
            D.THUMBNAIL_CONTAINER.appendChild(thumbWrapper);
        });

        const currentPageDisplay = document.getElementById(
            "current-page-display"
        );
        if (currentPageDisplay)
            currentPageDisplay.textContent = state.currentPage;

        const totalPagesDisplay = document.getElementById(
            "total-pages-display"
        );
        if (totalPagesDisplay) totalPagesDisplay.textContent = totalPages;

        const totalImagesDisplay = document.getElementById(
            "total-images-display"
        );
        if (totalImagesDisplay) totalImagesDisplay.textContent = totalItems;

        const prevBtn = document.getElementById("prev-page-btn");
        if (prevBtn) prevBtn.disabled = state.currentPage <= 1;

        const nextBtn = document.getElementById("next-page-btn");
        if (nextBtn) nextBtn.disabled = state.currentPage >= totalPages;
    }

    function updateMainImage(imageData) {
        resetAnnotationState();
        const annotationWrapper = document.getElementById("annotation-wrapper");
        const activeImagePathSpan =
            document.getElementById("active-image-path");

        // [PERBAIKAN] Tambahkan pengecekan null untuk elemen wrapper
        if (!D.ANNOTATION_IMAGE || !imageData || !annotationWrapper) return;

        annotationWrapper.classList.add("image-loading");
        D.ANNOTATION_IMAGE.src = "";
        state.currentImageS3Path = imageData.s3Path;
        if (activeImagePathSpan)
            activeImagePathSpan.textContent = `${imageData.set}/${imageData.filename}`;
        document.getElementById("input-image-path").value =
            imageData.imagePathForCsv;
        document.getElementById("input-dataset-set").value = imageData.set;
        D.ANNOTATION_IMAGE.src = imageData.imageUrl;
        D.ANNOTATION_IMAGE.onload = () => {
            state.imageRect = D.ANNOTATION_IMAGE.getBoundingClientRect();
            annotationWrapper.classList.remove("image-loading");
        };
        D.ANNOTATION_IMAGE.onerror = () => {
            annotationWrapper.classList.remove("image-loading");
            if (activeImagePathSpan)
                activeImagePathSpan.textContent = `Error memuat: ${imageData.filename}`;
        };
        document.querySelectorAll(".gallery-thumbnail").forEach((thumb) => {
            thumb.classList.toggle(
                "active-thumb",
                thumb.dataset.s3Path === state.currentImageS3Path
            );
        });
    }

    // === SEMUA LOGIKA ANOTASI ===
    function addResizeHandles(boxElement) {
        if (!boxElement || boxElement.querySelector(".bbox-handle")) return;
        removeResizeHandles(boxElement);
        ["nw", "ne", "sw", "se", "n", "s", "e", "w"].forEach((type) => {
            const handle = document.createElement("div");
            handle.className = `bbox-handle handle-${type}`;
            handle.dataset.handleType = type;
            boxElement.appendChild(handle);
        });
    }
    function removeResizeHandles(boxElement) {
        boxElement
            ?.querySelectorAll(".bbox-handle")
            .forEach((handle) => handle.remove());
    }
    function selectAnnotation(id) {
        state.selectedBoxId = id;
        document.querySelectorAll(".bbox-div").forEach((el) => {
            const isSelected = parseInt(el.dataset.id) === id;
            el.classList.toggle("selected", isSelected);
            if (isSelected) {
                addResizeHandles(el);
            } else {
                removeResizeHandles(el);
            }
        });
        const selectedAnno = state.annotations.find((a) => a.id === id);
        const ripenessOptionsDiv = document.getElementById("ripeness-options");
        const ripenessRadios = document.querySelectorAll(".ripeness-radio");
        if (selectedAnno) {
            ripenessOptionsDiv.classList.remove("hidden");
            ripenessRadios.forEach((r) => {
                r.disabled = false;
                r.checked = r.value === selectedAnno.ripeness;
            });
            document.getElementById("selected-bbox-index").textContent =
                state.annotations.findIndex((a) => a.id === id) + 1;
        } else {
            ripenessOptionsDiv.classList.add("hidden");
            ripenessRadios.forEach((r) => {
                r.disabled = true;
                r.checked = false;
            });
        }
        renderBboxList();
    }
    function getRelativeCoords(event, relativeToElement) {
        // [PERBAIKAN] Tambahkan pengecekan keamanan jika elemen referensi tidak ada.
        if (!relativeToElement) {
            console.error(
                "getRelativeCoords dipanggil tanpa elemen referensi."
            );
            return null;
        }

        // `rect` akan berisi dimensi dan posisi dari `relativeToElement`.
        const rect = relativeToElement.getBoundingClientRect();
        const point = event.touches ? event.touches[0] : event;

        return {
            // [PERBAIKAN] Gunakan `rect.left` dan `rect.top` untuk kalkulasi koordinat,
            // dan `rect.width` / `rect.height` untuk membatasi nilai maksimum.
            x: Math.max(0, Math.min(point.clientX - rect.left, rect.width)),
            y: Math.max(0, Math.min(point.clientY - rect.top, rect.height)),
        };
    }
    function updateCoordsFromElement(annotation, element) {
        if (
            !annotation ||
            !element ||
            !state.imageRect ||
            !D.ANNOTATION_IMAGE.naturalWidth
        )
            return;
        const naturalW = D.ANNOTATION_IMAGE.naturalWidth,
            naturalH = D.ANNOTATION_IMAGE.naturalHeight;
        const scaleX = naturalW / state.imageRect.width,
            scaleY = naturalH / state.imageRect.height;
        const elStyle = element.style;
        annotation.w = Math.max(
            0,
            (parseFloat(elStyle.width) * scaleX) / naturalW
        );
        annotation.h = Math.max(
            0,
            (parseFloat(elStyle.height) * scaleY) / naturalH
        );
        annotation.cx = Math.max(
            0,
            (parseFloat(elStyle.left) * scaleX +
                (parseFloat(elStyle.width) * scaleX) / 2) /
                naturalW
        );
        annotation.cy = Math.max(
            0,
            (parseFloat(elStyle.top) * scaleY +
                (parseFloat(elStyle.height) * scaleY) / 2) /
                naturalH
        );
        validateAndEnableSave();
    }
    function handleMouseDown(e) {
        if (navigator.vibrate) {
            navigator.vibrate(50);
        }

        if (e.button !== undefined && e.button !== 0) return;
        if (state.isEstimatingBbox) return;
        state.imageRect = D.ANNOTATION_IMAGE.getBoundingClientRect();

        // [PERBAIKAN] Deklarasikan SEKALI dengan benar.
        // Di file ini, elemen referensinya adalah D.BBOX_OVERLAY.
        const relativePos = getRelativeCoords(e, D.BBOX_OVERLAY);
        if (!relativePos) return; // Pengecekan tetap di sini.

        const target = e.target;

        const boxElement = target.closest(".bbox-div");

        if (target.classList.contains("bbox-handle")) {
            e.stopPropagation();
            const rect = boxElement.getBoundingClientRect();
            const parentRect = D.BBOX_OVERLAY.getBoundingClientRect();
            state.interactionState = {
                type: "resize",
                targetBox: boxElement,
                handleType: target.dataset.handleType,
                startX: relativePos.x,
                startY: relativePos.y,
                initialBoxRect: {
                    left: rect.left - parentRect.left,
                    top: rect.top - parentRect.top,
                    width: rect.width,
                    height: rect.height,
                },
            };
            boxElement.classList.add("resizing");
        } else if (boxElement) {
            e.stopPropagation();
            selectAnnotation(parseInt(boxElement.dataset.id));
            const rect = boxElement.getBoundingClientRect();
            const parentRect = D.BBOX_OVERLAY.getBoundingClientRect();
            state.interactionState = {
                type: "move",
                targetBox: boxElement,
                startX: relativePos.x,
                startY: relativePos.y,
                initialBoxRect: {
                    left: rect.left - parentRect.left,
                    top: rect.top - parentRect.top,
                },
            };
            boxElement.classList.add("moving");
        } else if (target === D.ANNOTATION_IMAGE || target === D.BBOX_OVERLAY) {
            selectAnnotation(-1);
            const newBox = document.createElement("div");
            newBox.className = "bbox-div drawing";
            newBox.style.left = `${relativePos.x}px`;
            newBox.style.top = `${relativePos.y}px`;
            state.interactionState = {
                type: "draw",
                targetBox: newBox,
                startX: relativePos.x,
                startY: relativePos.y,
            };
            D.BBOX_OVERLAY.appendChild(newBox);
        }

        if (state.interactionState.type !== "none") {
            // [MODIFIKASI] Tambahkan juga listener untuk touch events
            document.addEventListener("mousemove", handleMouseMove);
            document.addEventListener("touchmove", handleTouchMove, {
                passive: false,
            }); // Penting!
            document.addEventListener("mouseup", handleMouseUp, { once: true });
            document.addEventListener("touchend", handleTouchEnd, {
                once: true,
            });
        }
    }
    function handleMouseMove(e) {
        if (state.interactionState.type === "none" || !state.imageRect) return;

        if (e.touches) {
            e.preventDefault();
        }

        // [PERBAIKAN] Panggil `getRelativeCoords` dengan parameter kedua yang benar.
        const pos = getRelativeCoords(e, D.BBOX_OVERLAY);
        if (!pos) return;
        const { type, targetBox, startX, startY, initialBoxRect, handleType } =
            state.interactionState;

        if (type === "draw") {
            targetBox.style.left = `${Math.min(startX, pos.x)}px`;
            targetBox.style.top = `${Math.min(startY, pos.y)}px`;
            targetBox.style.width = `${Math.abs(pos.x - startX)}px`;
            targetBox.style.height = `${Math.abs(pos.y - startY)}px`;
        } else if (type === "move") {
            const newLeft = initialBoxRect.left + (pos.x - startX);
            const newTop = initialBoxRect.top + (pos.y - startY);
            targetBox.style.left = `${Math.max(
                0,
                Math.min(
                    newLeft,
                    state.imageRect.width - parseFloat(targetBox.style.width)
                )
            )}px`;
            targetBox.style.top = `${Math.max(
                0,
                Math.min(
                    newTop,
                    state.imageRect.height - parseFloat(targetBox.style.height)
                )
            )}px`;
        } else if (type === "resize") {
            let { left, top, width, height } = initialBoxRect;
            const dx = pos.x - startX;
            const dy = pos.y - startY;
            if (handleType.includes("n")) {
                height -= dy;
                top += dy;
            }
            if (handleType.includes("s")) {
                height += dy;
            }
            if (handleType.includes("w")) {
                width -= dx;
                left += dx;
            }
            if (handleType.includes("e")) {
                width += dx;
            }
            if (width >= state.minBoxSize) {
                targetBox.style.left = `${left}px`;
                targetBox.style.width = `${width}px`;
            }
            if (height >= state.minBoxSize) {
                targetBox.style.top = `${top}px`;
                targetBox.style.height = `${height}px`;
            }
        }
    }
    function handleMouseUp() {
        // [MODIFIKASI] Hapus semua listener interaksi
        document.removeEventListener("mousemove", handleMouseMove);
        document.removeEventListener("touchmove", handleTouchMove);
        // Listener 'up' dan 'end' akan terhapus sendiri karena {once: true},
        // tapi tidak ada salahnya menghapus secara eksplisit untuk keamanan.
        document.removeEventListener("mouseup", handleMouseUp);
        document.removeEventListener("touchend", handleTouchEnd);

        const { type, targetBox } = state.interactionState;
        document.removeEventListener("mousemove", handleMouseMove);
        if (!targetBox) {
            state.interactionState = { type: "none" };
            return;
        }
        targetBox.classList.remove("drawing", "moving", "resizing");
        if (type === "draw") {
            if (
                parseFloat(targetBox.style.width) < state.minBoxSize ||
                parseFloat(targetBox.style.height) < state.minBoxSize
            ) {
                targetBox.remove();
                selectAnnotation(-1);
            } else {
                const newId = state.nextBoxId++;
                targetBox.dataset.id = newId;
                const newAnnotation = { id: newId, ripeness: null };
                state.annotations.push(newAnnotation);
                updateCoordsFromElement(newAnnotation, targetBox);
                selectAnnotation(newId);
            }
        } else if (type === "move" || type === "resize") {
            const boxId = parseInt(targetBox.dataset.id, 10);
            const annotationToUpdate = state.annotations.find(
                (a) => a.id === boxId
            );
            if (annotationToUpdate)
                updateCoordsFromElement(annotationToUpdate, targetBox);
        }
        state.interactionState = { type: "none" };
    }

    // [TAMBAHAN] Fungsi pembungkus untuk touch events
    function handleTouchStart(e) {
        // Hanya proses sentuhan pertama
        if (e.touches.length > 1) return;
        // Beri getaran singkat jika didukung browser
        if (navigator.vibrate) navigator.vibrate(50);
        // Panggil handler mouse yang sudah ada
        handleMouseDown(e);
    }

    // handleMouseMove sudah menangani `preventDefault`
    // jadi fungsi ini hanyalah alias
    function handleTouchMove(e) {
        handleMouseMove(e);
    }

    function handleTouchEnd() {
        // Cukup panggil mouseup karena tidak ada data koordinat di touchend
        handleMouseUp();
    }

    function resetAnnotationState() {
        state.annotations = [];
        state.nextBoxId = 0;
        state.selectedBoxId = -1;
        state.interactionState = { type: "none" };
        D.BBOX_OVERLAY.innerHTML = "";
        // Baris yang menyebabkan error telah dihapus dari sini.
        renderBboxList();
        selectAnnotation(-1);
    }

    function renderBboxList() {
        const bboxListUl = document.getElementById("bbox-list");
        document.getElementById("bbox-count").textContent =
            state.annotations.length;
        bboxListUl.innerHTML = "";
        if (state.annotations.length === 0) {
            bboxListUl.innerHTML =
                '<li class="list-group-item text-muted">Belum ada Bbox digambar.</li>';
            validateAndEnableSave();
            return;
        }
        state.annotations.forEach((anno, index) => {
            const li = document.createElement("li");
            li.className = `list-group-item d-flex justify-content-between align-items-center clickable ${
                anno.id === state.selectedBoxId ? "active" : ""
            }`;
            li.dataset.id = anno.id;
            const rClass =
                anno.ripeness === "ripe"
                    ? "bg-success"
                    : anno.ripeness === "unripe"
                    ? "bg-warning text-dark"
                    : "bg-secondary";
            const rText = anno.ripeness
                ? anno.ripeness === "ripe"
                    ? "Matang"
                    : "Belum Matang"
                : "Atur?";
            li.innerHTML = `<span>Bbox #${
                index + 1
            }<span class="badge rounded-pill ms-2 ${rClass}">${rText}</span></span><button type="button" class="btn btn-outline-danger btn-sm p-1 lh-1 btn-delete-bbox" data-id="${
                anno.id
            }"><i class="fas fa-trash-alt fa-xs"></i></button>`;
            li.addEventListener(
                "click",
                (e) =>
                    !e.target.closest(".btn-delete-bbox") &&
                    selectAnnotation(anno.id)
            );
            li.querySelector(".btn-delete-bbox").addEventListener(
                "click",
                (e) => {
                    e.stopPropagation();
                    deleteAnnotation(anno.id);
                }
            );
            bboxListUl.appendChild(li);
        });
        validateAndEnableSave();
    }
    function updateAnnotationRipeness(id, ripeness) {
        const index = state.annotations.findIndex((a) => a.id === id);
        if (index !== -1) {
            state.annotations[index].ripeness = ripeness;
            renderBboxList();
        }
    }
    function validateAndEnableSave() {
        const saveButton = document.getElementById("save-button");
        const annotationsJsonInput = document.getElementById(
            "input-annotations-json"
        );
        const allAnnotationsHaveRipeness =
            state.annotations.length > 0 &&
            state.annotations.every((anno) => anno.ripeness);
        saveButton.disabled = !allAnnotationsHaveRipeness;
        annotationsJsonInput.value = allAnnotationsHaveRipeness
            ? JSON.stringify(
                  state.annotations.map((a) => ({
                      cx: a.cx,
                      cy: a.cy,
                      w: a.w,
                      h: a.h,
                      ripeness: a.ripeness,
                  }))
              )
            : "[]";
    }
    function deleteAnnotation(id) {
        const index = state.annotations.findIndex((a) => a.id === id);
        if (index !== -1) {
            document.querySelector(`.bbox-div[data-id='${id}']`)?.remove();
            state.annotations.splice(index, 1);
            if (state.selectedBoxId === id) selectAnnotation(-1);
            else renderBboxList();
        }
    }
    async function requestBboxEstimation() {
        const estimateEndpoint = D.CONTAINER.dataset.estimateBboxEndpoint;
        if (
            !estimateEndpoint ||
            !D.getCsrfToken() ||
            !state.currentImageS3Path ||
            state.isEstimatingBbox
        )
            return;

        state.isEstimatingBbox = true;
        const overlay = document.getElementById("estimation-overlay");
        const overlayText = document.getElementById("estimation-overlay-text");
        const overlaySpinner = document.getElementById("estimation-spinner");

        // Tampilkan overlay dengan status loading
        overlaySpinner.classList.remove("hidden");
        overlayText.textContent = "Mengestimasi BBox...";
        overlay.classList.remove("hidden", "success", "error");

        try {
            const formData = new FormData();
            formData.append("image_path", state.currentImageS3Path);
            const response = await fetch(estimateEndpoint, {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN": D.getCsrfToken(),
                    Accept: "application/json",
                },
                body: formData,
            });

            const data = await response.json();
            if (!response.ok || !data.success)
                throw new Error(data.message || "Respons server tidak sukses.");

            if (Array.isArray(data.bboxes) && data.bboxes.length > 0) {
                resetAnnotationState(); // Hapus BBox manual yang mungkin sudah ada
                data.bboxes.forEach((bboxRel) => {
                    const newId = state.nextBoxId++;
                    const newBox = document.createElement("div");
                    newBox.className = "bbox-div";
                    newBox.dataset.id = newId;
                    newBox.style.left = `${
                        (bboxRel.cx - bboxRel.w / 2.0) * state.imageRect.width
                    }px`;
                    newBox.style.top = `${
                        (bboxRel.cy - bboxRel.h / 2.0) * state.imageRect.height
                    }px`;
                    newBox.style.width = `${
                        bboxRel.w * state.imageRect.width
                    }px`;
                    newBox.style.height = `${
                        bboxRel.h * state.imageRect.height
                    }px`;
                    D.BBOX_OVERLAY.appendChild(newBox);
                    state.annotations.push({
                        id: newId,
                        ripeness: null,
                        cx: bboxRel.cx,
                        cy: bboxRel.cy,
                        w: bboxRel.w,
                        h: bboxRel.h,
                    });
                });
                renderBboxList();
                if (state.annotations.length > 0)
                    selectAnnotation(state.annotations[0].id);

                // Tampilkan status sukses di overlay
                overlaySpinner.classList.add("hidden");
                overlayText.textContent = `Berhasil! Ditemukan ${data.bboxes.length} BBox.`;
                overlay.classList.add("success");
            } else {
                // Tampilkan status tidak ditemukan di overlay
                overlaySpinner.classList.add("hidden");
                overlayText.textContent =
                    data.message || "Tidak ada BBox terdeteksi.";
                overlay.classList.add("error");
            }
        } catch (error) {
            // Tampilkan status error di overlay
            overlaySpinner.classList.add("hidden");
            overlayText.textContent = `Error: ${error.message}`;
            overlay.classList.add("error");
        } finally {
            // Sembunyikan overlay setelah beberapa detik
            setTimeout(() => {
                overlay.classList.add("hidden");
            }, 2500);
            state.isEstimatingBbox = false;
        }
    }

    // === INISIALISASI & PENDAFTARAN EVENT LISTENERS ===
    // [PERBAIKAN] Fungsi untuk memeriksa status dan memicu generate thumbnail
    const checkQueueAndGenerateThumbnails = async () => {
        try {
            // Tampilkan status ke pengguna
            if (statusIndicator)
                statusIndicator.textContent =
                    "Memeriksa antrian gambar baru...";

            // [PERBAIKAN] Ambil URL dari data attribute, bukan sintaks Blade
            const queueStatusUrl = D.CONTAINER.dataset.queueStatusUrl;
            if (!queueStatusUrl) {
                console.error("URL untuk queue status tidak ditemukan!");
                return;
            }

            const response = await fetch(queueStatusUrl); // <-- PERBAIKAN DI SINI

            // [PERBAIKAN] Tambahkan pengecekan jika respons bukan JSON
            if (
                !response.headers
                    .get("content-type")
                    ?.includes("application/json")
            ) {
                throw new Error(
                    "Respons dari server bukan JSON. Kemungkinan URL salah atau terjadi error server."
                );
            }

            const data = await response.json();

            if (data.success && data.needs_thumbnail_generation) {
                if (statusIndicator)
                    statusIndicator.textContent =
                        "Gambar baru ditemukan! Membuat thumbnail... (Halaman akan refresh otomatis)";

                // Kita panggil fungsi refresh yang sudah ada, karena itu yang paling relevan
                const refreshAction = document.getElementById(
                    "refresh-gallery-btn-main"
                )?.onclick;
                if (typeof refreshAction === "function") {
                    // Beri jeda sedikit agar pesan bisa terbaca
                    setTimeout(() => window.location.reload(), 2000);
                }
            } else {
                if (statusIndicator)
                    statusIndicator.textContent = "Semua gambar sudah siap.";
                setTimeout(() => {
                    if (statusIndicator) statusIndicator.style.display = "none";
                }, 2000);
            }
        } catch (error) {
            console.error("Gagal memeriksa status antrian:", error);
            if (statusIndicator) {
                statusIndicator.textContent = `Error: ${error.message}`;
                statusIndicator.classList.remove("alert-info");
                statusIndicator.classList.add("alert-danger");
            }
        }
    };

    // [DIHAPUS] Fungsi triggerThumbnailGeneration tidak lagi diperlukan karena
    // alur "Segarkan" sudah ditangani oleh tombol yang ada.
    // Logika tombol "Segarkan" di `setupEventListeners` sudah benar (`localStorage.clear(); window.location.reload();`)
    // dan itu adalah cara yang paling tepat untuk mengatasi masalah cache.

    // Panggil fungsi pengecekan saat halaman selesai dimuat
    // Kita nonaktifkan sementara agar tidak membingungkan, fokus pada fungsi tombol "Segarkan"
    // checkQueueAndGenerateThumbnails();
    // Alih-alih, kita langsung saja sembunyikan status indicator setelah beberapa saat
    if (statusIndicator) {
        setTimeout(() => {
            statusIndicator.style.display = "none";
        }, 1500);
    }

    async function hydrateUrls() {
        const filesToHydrate = state.allFiles.filter(
            (f) => !f.imageUrl || !f.thumbnailUrl
        );
        if (filesToHydrate.length === 0) return Promise.resolve();
        showLoadingIndicator(true, "Menyiapkan URL gambar...");
        const pathsToFetch = filesToHydrate.flatMap((file) => [
            file.s3Path,
            file.thumbnailS3Path,
        ]);
        try {
            const response = await fetch(D.CONTAINER.dataset.getUrlsEndpoint, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": D.getCsrfToken(),
                },
                body: JSON.stringify({ paths: [...new Set(pathsToFetch)] }),
            });
            const data = await response.json();
            state.allFiles.forEach((file) => {
                if (data.urls[file.s3Path])
                    file.imageUrl = data.urls[file.s3Path];
                if (data.urls[file.thumbnailS3Path])
                    file.thumbnailUrl = data.urls[file.thumbnailS3Path];
            });
        } catch (error) {
            showNotification("Gagal menyiapkan gambar dari server.", "danger");
        } finally {
            showLoadingIndicator(false);
        }
    }
    function setupEventListeners() {
        document
            .getElementById("btn-estimate-bbox")
            ?.addEventListener("click", requestBboxEstimation);

        // Paginasi
        document
            .getElementById("prev-page-btn")
            .addEventListener("click", () => {
                if (state.currentPage > 1)
                    renderGalleryPage(state.currentPage - 1);
            });
        document
            .getElementById("next-page-btn")
            .addEventListener("click", () => {
                if (
                    state.currentPage <
                    Math.ceil(state.allFiles.length / D.ITEMS_PER_PAGE)
                )
                    renderGalleryPage(state.currentPage + 1);
            });

        // Aksi Utama & Layar Selesai
        const uploadModalInstance = bootstrap.Modal.getOrCreateInstance(
            document.getElementById("uploadDatasetModal")
        );

        // Listener untuk tombol di Halaman Anotasi Utama
        document
            .getElementById("upload-new-dataset-btn-main")
            ?.addEventListener("click", () => {
                state.uploadFromCompletionScreen = false; // Set penanda ke false
                uploadModalInstance.show();
            });

        // Listener untuk tombol di Halaman "Selesai"
        document
            .getElementById("upload-new-dataset-btn-complete")
            ?.addEventListener("click", () => {
                state.uploadFromCompletionScreen = true; // Set penanda ke true
                uploadModalInstance.show();
            });
        const refreshAction = () => {
            // 1. Tampilkan overlay loading
            showLoadingIndicator(
                true,
                "Menghapus cache dan memuat ulang data..."
            );

            // 2. Beri jeda sedikit agar overlay sempat tampil sebelum halaman di-reload
            setTimeout(() => {
                localStorage.clear(); // Bersihkan semua cache lokal
                window.location.reload(); // Reload halaman
            }, 250);
        };
        document
            .getElementById("refresh-gallery-btn-main")
            ?.addEventListener("click", refreshAction);
        document
            .getElementById("refresh-gallery-btn-complete")
            ?.addEventListener("click", refreshAction); // [PERBAIKAN]

        // Form Anotasi
        document
            .getElementById("annotation-form")
            .addEventListener("submit", async function (e) {
                e.preventDefault();
                if (this.querySelector('button[type="submit"]').disabled)
                    return;
                showLoadingIndicator(true, "Menyimpan anotasi...");
                const s3PathToRemove = state.currentImageS3Path;
                try {
                    const response = await fetch(this.action, {
                        method: "POST",
                        headers: {
                            Accept: "application/json",
                            "X-CSRF-TOKEN": D.getCsrfToken(),
                        },
                        body: new FormData(this),
                    });
                    const data = await response.json();
                    if (!response.ok)
                        throw new Error(data.message || "Gagal menyimpan.");
                    showNotification(data.message, "success");
                    handleDataChange({
                        pathsToRemove: [s3PathToRemove],
                        nextImageDataFromServer: data.next_image_data,
                    });
                } catch (error) {
                    showNotification(
                        `Gagal menyimpan: ${error.message}`,
                        "danger"
                    );
                } finally {
                    showLoadingIndicator(false);
                }
            });

        // Hapus Tunggal
        document
            .getElementById("delete-current-image-btn")
            .addEventListener("click", async () => {
                if (
                    !state.currentImageS3Path ||
                    !confirm(`Anda yakin ingin menghapus gambar ini?`)
                )
                    return;
                showLoadingIndicator(true, "Menghapus...");
                const s3PathToRemove = state.currentImageS3Path;
                try {
                    const formData = new FormData();
                    formData.append("s3_path_original", s3PathToRemove);
                    const response = await fetch(
                        D.CONTAINER.dataset.deleteImageUrl,
                        {
                            method: "POST",
                            headers: {
                                Accept: "application/json",
                                "X-CSRF-TOKEN": D.getCsrfToken(),
                            },
                            body: formData,
                        }
                    );
                    const data = await response.json();
                    if (!response.ok)
                        throw new Error(data.message || "Gagal menghapus.");
                    showNotification(data.message, "success");
                    handleDataChange({
                        pathsToRemove: [s3PathToRemove],
                        nextImageDataFromServer: data.next_image_data,
                    });
                } catch (error) {
                    showNotification(
                        `Gagal menghapus: ${error.message}`,
                        "danger"
                    );
                } finally {
                    showLoadingIndicator(false);
                }
            });

        // Hapus Batch
        const toggleSelectMode = (enable) => {
            state.isSelectMode = enable;
            document
                .getElementById("batch-delete-controls")
                .classList.toggle("hidden", !enable);
            document
                .getElementById("gallery-actions")
                .classList.toggle("hidden", enable);
            D.THUMBNAIL_CONTAINER.classList.toggle("in-select-mode", enable);
            if (!enable) state.selectedForDeletion.clear();
            renderGalleryPage(state.currentPage);
        };
        document
            .getElementById("select-mode-btn")
            ?.addEventListener("click", () => toggleSelectMode(true));
        document
            .getElementById("cancel-select-mode-btn")
            ?.addEventListener("click", () => toggleSelectMode(false));
        document
            .getElementById("delete-selected-btn")
            ?.addEventListener("click", async () => {
                if (
                    state.selectedForDeletion.size === 0 ||
                    !confirm(
                        `Anda yakin ingin menghapus ${state.selectedForDeletion.size} gambar ini?`
                    )
                )
                    return;
                showLoadingIndicator(
                    true,
                    `Menghapus ${state.selectedForDeletion.size} gambar...`
                );
                const pathsToRemove = Array.from(state.selectedForDeletion);
                try {
                    const response = await fetch(
                        D.CONTAINER.dataset.batchDeleteUrl,
                        {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                                Accept: "application/json",
                                "X-CSRF-TOKEN": D.getCsrfToken(),
                            },
                            body: JSON.stringify({ s3_paths: pathsToRemove }),
                        }
                    );
                    const data = await response.json();
                    if (!response.ok || !data.success)
                        throw new Error(data.message || "Gagal hapus batch.");
                    showNotification(data.message, "success");
                    handleDataChange({ pathsToRemove: pathsToRemove });
                } catch (error) {
                    showNotification(`Gagal: ${error.message}`, "danger");
                } finally {
                    toggleSelectMode(false);
                    showLoadingIndicator(false);
                }
            });

        // Modal Unggah
        document
            .getElementById("submitUploadDatasetBtn")
            ?.addEventListener("click", async () => {
                const modalElement =
                    document.getElementById("uploadDatasetModal");
                const modalBSInstance =
                    bootstrap.Modal.getInstance(modalElement);
                const form = document.getElementById("uploadDatasetForm");

                showLoadingIndicator(true, `Mengunggah...`);

                try {
                    const response = await fetch(
                        D.CONTAINER.dataset.uploadDatasetUrl,
                        {
                            method: "POST",
                            headers: {
                                Accept: "application/json",
                                "X-CSRF-TOKEN": D.getCsrfToken(),
                            },
                            body: new FormData(form),
                        }
                    );

                    const data = await response.json();
                    if (!response.ok)
                        throw new Error(data.message || "Gagal mengunggah.");

                    // Sembunyikan loading awal & tutup modal
                    showLoadingIndicator(false);
                    if (modalBSInstance) modalBSInstance.hide();

                    // Tampilkan notifikasi sukses dari server
                    showNotification(data.message, "success");

                    // Reset form modal SETELAH notifikasi tampil & modal ditutup
                    form.reset();

                    // Jika unggahan berhasil dan ada file baru, jalankan refresh
                    if (data.success && data.new_files?.length > 0) {
                        // Beri jeda 1.5 detik agar notifikasi bisa terbaca
                        setTimeout(() => {
                            showLoadingIndicator(
                                true,
                                "Memuat ulang data baru..."
                            );
                            localStorage.clear(); // Bersihkan cache lama
                            window.location.reload(); // Lakukan refresh total
                        }, 1500);
                    }
                } catch (error) {
                    // Jika gagal, tetap sembunyikan loading & tampilkan error
                    showLoadingIndicator(false);
                    showNotification(`Gagal: ${error.message}`, "danger");
                } finally {
                    // Reset penanda (opsional, karena logika refresh sudah sama untuk semua)
                    state.uploadFromCompletionScreen = false;
                }
            });

        if (D.ANNOTATION_CONTAINER) {
            D.ANNOTATION_CONTAINER.addEventListener(
                "mousedown",
                handleMouseDown
            );
            D.ANNOTATION_CONTAINER.addEventListener(
                "touchstart",
                handleTouchStart,
                { passive: false }
            );
        }

        document.querySelectorAll(".ripeness-radio").forEach((radio) =>
            radio.addEventListener("change", function () {
                if (state.selectedBoxId !== -1)
                    updateAnnotationRipeness(state.selectedBoxId, this.value);
            })
        );
    }

    // === Titik Masuk Utama Aplikasi ===
    async function main() {
        // !!! ===== PERBAIKAN UTAMA NO. 2 DI SINI ===== !!!
        // Selalu hapus cache file dari localStorage setiap kali halaman dimuat.
        // Ini memastikan data yang diambil selalu yang terbaru dari server-rendered script.
        localStorage.removeItem("unannotatedFilesCache");
        console.log(
            "Local storage cache untuk daftar file anotasi telah dibersihkan pada saat load."
        );
        // !!! ===== AKHIR PERBAIKAN ===== !!!

        const cachedFiles = localStorage.getItem("unannotatedFilesCache");
        if (cachedFiles) {
            try {
                state.allFiles = JSON.parse(cachedFiles);
            } catch (e) {
                localStorage.removeItem("unannotatedFilesCache");
            }
        }
        if (state.allFiles.length === 0) {
            const dataScript = document.getElementById(
                "all-unannotated-images-data"
            );
            if (dataScript?.textContent)
                state.allFiles = JSON.parse(dataScript.textContent);
        }
        await hydrateUrls();
        setupEventListeners();
        state.currentPage =
            parseInt(localStorage.getItem("annotationCurrentPage"), 10) || 1;
        handleDataChange();
    }

    // ▼▼▼ TAMBAHKAN BLOK BARU DI SINI, SEBELUM main() ▼▼▼
    function setupNavigationOverlay() {
        document.body.addEventListener("click", function (e) {
            const link = e.target.closest(".nav-loader-link");
            if (!link || e.ctrlKey || e.metaKey || e.button === 1) {
                return;
            }
            e.preventDefault();
            const destination = link.href;
            const pageName = link.dataset.pageName || "halaman";

            // Gunakan fungsi loading indicator yang sudah ada di file ini
            showLoadingIndicator(true, `Membuka ${pageName}...`);

            setTimeout(() => {
                window.location.href = destination;
            }, 150);
        });
    }
    // ▲▲▲ AKHIR BLOK BARU ▲▲▲

    main();
    setupNavigationOverlay(); // Panggil fungsi baru setelah main
});
