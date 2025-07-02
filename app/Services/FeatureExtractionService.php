<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Throwable;

class FeatureExtractionService
{
    public const S3_FEATURE_DIR = 'dataset/features';

    private const RESIZE_TARGET = 64;

    // --- PERUBAHAN 1: DEFINISIKAN DAFTAR FITUR TERPILIH ---
    // Daftar ini berisi 20 fitur dengan skor tertinggi dari hasil seleksi kita.
    public const SELECTED_FEATURE_NAMES = [
        'G_std',
        'H_mean',
        'R_mean',
        'S_mean',
        'bbox_area_ratio',
        'G_mean',
        'H_std',
        'energy_d1_a0',
        'contrast_d1_a0',
        'correlation_d1_a45',
        'homogeneity_d1_a0',
        'S_std',
        'correlation_d3_a90',
        'correlation_d1_a0',
        'aspect_ratio',
        'R_std',
        'energy_d1_a45',
        'correlation_d3_a0',
        'correlation_d1_a135',
        'homogeneity_d3_a0',
    ];

    public const COLOR_FEATURE_NAMES = [
        'R_mean',
        'G_mean',
        'B_mean',
        'R_std',
        'G_std',
        'B_std',
    ];

    // BARU: Fitur warna dari ruang HSV
    public const HSV_COLOR_FEATURE_NAMES = [
        'H_mean',
        'S_mean',
        'V_mean',
        'H_std',
        'S_std',
        'V_std',
    ];

    // BARU: Fitur bentuk sederhana
    public const SHAPE_FEATURE_NAMES = [
        'aspect_ratio',
        'bbox_area_ratio',
    ];

    public const TEXTURE_FEATURE_NAMES = [
        'contrast_d1_a0',
        'correlation_d1_a0',
        'energy_d1_a0',
        'homogeneity_d1_a0',
        'contrast_d1_a45',
        'correlation_d1_a45',
        'energy_d1_a45',
        'homogeneity_d1_a45',
        'contrast_d1_a90',
        'correlation_d1_a90',
        'energy_d1_a90',
        'homogeneity_d1_a90',
        'contrast_d1_a135',
        'correlation_d1_a135',
        'energy_d1_a135',
        'homogeneity_d1_a135',
        'contrast_d3_a0',
        'correlation_d3_a0',
        'energy_d3_a0',
        'homogeneity_d3_a0',
        'contrast_d3_a45',
        'correlation_d3_a45',
        'energy_d3_a45',
        'homogeneity_d3_a45',
        'contrast_d3_a90',
        'correlation_d3_a90',
        'energy_d3_a90',
        'homogeneity_d3_a90',
        'contrast_d3_a135',
        'correlation_d3_a135',
        'energy_d3_a135',
        'homogeneity_d3_a135',
    ];

    public const UNIFIED_FEATURE_NAMES_FULL = [
        ...self::COLOR_FEATURE_NAMES,
        ...self::HSV_COLOR_FEATURE_NAMES, // Ditambahkan
        ...self::SHAPE_FEATURE_NAMES,    // Ditambahkan
        ...self::TEXTURE_FEATURE_NAMES,
    ];

    public const UNIFIED_FEATURE_NAMES = self::SELECTED_FEATURE_NAMES;

    private const GLCM_DISTANCES = [1, 3];
    private const GLCM_ANGLES = [0, 45, 90, 135];
    private const GLCM_LEVELS = 8;

    public function extractFeaturesFromAnnotation(string $s3ImagePath, array $annotation): ?array
    {
        $img = null;
        $processedImg = null;
        $resizedImg = null;
        try {
            $img = $this->loadImage($s3ImagePath);
            if (!$this->isValidImagick($img)) return null;

            $imgWidth = $img->getImageWidth();
            $imgHeight = $img->getImageHeight();
            $shapeFeatures = null;

            if ($annotation['detection_class'] === 'melon') {
                $w = (float)$annotation['bbox_w'] * $imgWidth;
                $h = (float)$annotation['bbox_h'] * $imgHeight;
                $x = ((float)$annotation['bbox_cx'] * $imgWidth) - ($w / 2);
                $y = ((float)$annotation['bbox_cy'] * $imgHeight) - ($h / 2);
                $processedImg = $this->cropImage($img, (int)round($x), (int)round($y), (int)round($w), (int)round($h));
                $shapeFeatures = $this->extractShapeFeatures((int)round($w), (int)round($h), $imgWidth, $imgHeight);
            } else {
                $processedImg = clone $img;
                $shapeFeatures = array_fill(0, count(self::SHAPE_FEATURE_NAMES), 0.0);
            }

            if (!$this->isValidImagick($processedImg)) return null;
            $resizedImg = $this->resizeImage($processedImg, self::RESIZE_TARGET);
            if (!$this->isValidImagick($resizedImg)) return null;

            $colorFeatures = $this->extractColorFeatures($resizedImg);
            $hsvFeatures = $this->extractHsvFeatures($resizedImg);
            $textureFeatures = $this->extractTextureFeatures($resizedImg);

            if (!$colorFeatures || !$hsvFeatures || !$shapeFeatures || !$textureFeatures) {
                Log::warning("Salah satu kelompok fitur gagal diekstrak untuk {$s3ImagePath}");
                return null;
            }

            $allExtractedFeatures = array_merge($colorFeatures, $hsvFeatures, $shapeFeatures, $textureFeatures);
            $allFeaturesMap = array_combine(self::UNIFIED_FEATURE_NAMES_FULL, $allExtractedFeatures);
            if ($allFeaturesMap === false) return null;

            // Logika penting: Hanya kembalikan fitur yang ada di SELECTED_FEATURE_NAMES
            $selectedFeatures = [];
            foreach (self::SELECTED_FEATURE_NAMES as $featureName) {
                $selectedFeatures[] = $allFeaturesMap[$featureName] ?? 0.0;
            }
            return $selectedFeatures;
        } catch (Throwable $e) {
            Log::error("Feature extraction failed for {$s3ImagePath}", ['error' => $e->getMessage()]);
            return null;
        } finally {
            $this->cleanupImagick($img, $processedImg, $resizedImg);
        }
    }

    /**
     * BARU: Mengekstrak fitur bentuk sederhana dari dimensi bounding box.
     */
    private function extractShapeFeatures(int $w, int $h, int $originalWidth, int $originalHeight): ?array
    {
        // Pencegahan pembagian dengan nol
        if ($h === 0 || $originalWidth === 0 || $originalHeight === 0) {
            return array_fill(0, count(self::SHAPE_FEATURE_NAMES), 0.0);
        }

        $aspectRatio = $w / $h;
        $bboxAreaRatio = ($w * $h) / ($originalWidth * $originalHeight);

        $features = [$aspectRatio, $bboxAreaRatio];
        return count($features) === count(self::SHAPE_FEATURE_NAMES) ? $features : null;
    }

    /**
     * BARU: Mengekstrak fitur warna dari ruang warna HSV.
     */
    private function extractHsvFeatures(Imagick $img): ?array
    {
        $hsvImg = null;
        try {
            $hsvImg = clone $img;
            // Ubah ke ruang warna HSV
            $hsvImg->transformImageColorspace(Imagick::COLORSPACE_HSV);

            // Di HSV, channel-nya dipetakan ke R,G,B oleh Imagick
            // H (Hue) -> Red channel
            // S (Saturation) -> Green channel
            // V (Value/Brightness) -> Blue channel
            $stats = $hsvImg->getImageChannelStatistics();
            if (empty($stats[Imagick::CHANNEL_RED])) return null;

            $quantumRange = $hsvImg->getQuantumRange()['quantumRangeLong'];
            $normalize = fn($value) => ($quantumRange > 0) ? ($value / $quantumRange) * 255.0 : 0.0;

            $features = [
                $normalize($stats[Imagick::CHANNEL_RED]['mean']),      // H_mean
                $normalize($stats[Imagick::CHANNEL_GREEN]['mean']),    // S_mean
                $normalize($stats[Imagick::CHANNEL_BLUE]['mean']),     // V_mean
                $normalize($stats[Imagick::CHANNEL_RED]['standardDeviation']),  // H_std
                $normalize($stats[Imagick::CHANNEL_GREEN]['standardDeviation']), // S_std
                $normalize($stats[Imagick::CHANNEL_BLUE]['standardDeviation']), // V_std
            ];
            return count($features) === count(self::HSV_COLOR_FEATURE_NAMES) ? $features : null;
        } catch (Throwable $e) {
            Log::error("HSV color feature extraction failed", ['error' => $e->getMessage()]);
            return null;
        } finally {
            $this->cleanupImagick($hsvImg);
        }
    }

    private function cropImage(Imagick $img, int $x, int $y, int $w, int $h): ?Imagick
    {
        try {
            $cropped = clone $img;
            $cropped->cropImage($w, $h, $x, $y);
            $cropped->setImagePage(0, 0, 0, 0);
            return $this->isValidImagick($cropped) ? $cropped : null;
        } catch (Throwable $e) {
            Log::error("Imagick crop failed.", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function loadImage(string $s3Path): ?Imagick
    {
        try {
            $imageBlob = Storage::disk('s3')->get($s3Path);
            if ($imageBlob === null) return null;
            $img = new Imagick();
            $img->readImageBlob($imageBlob);
            return $this->isValidImagick($img) ? $img : null;
        } catch (Throwable $e) {
            Log::error("Failed to load image from S3: {$s3Path}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function resizeImage(Imagick $img, int $targetSize): ?Imagick
    {
        try {
            $resized = clone $img;
            $resized->resizeImage($targetSize, $targetSize, Imagick::FILTER_LANCZOS, 1, true);
            return $this->isValidImagick($resized) ? $resized : null;
        } catch (Throwable $e) {
            Log::error("Imagick resize failed.", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function extractColorFeatures(Imagick $img): ?array
    {
        try {
            $stats = $img->getImageChannelStatistics();
            if (empty($stats[Imagick::CHANNEL_RED])) return null;

            $quantumRange = $img->getQuantumRange()['quantumRangeLong'];
            $normalize = fn($value) => ($quantumRange > 0) ? ($value / $quantumRange) * 255.0 : 0.0;

            $features = [
                $normalize($stats[Imagick::CHANNEL_RED]['mean']),
                $normalize($stats[Imagick::CHANNEL_GREEN]['mean']),
                $normalize($stats[Imagick::CHANNEL_BLUE]['mean']),
                $normalize($stats[Imagick::CHANNEL_RED]['standardDeviation']),
                $normalize($stats[Imagick::CHANNEL_GREEN]['standardDeviation']),
                $normalize($stats[Imagick::CHANNEL_BLUE]['standardDeviation']),
            ];
            // PERBAIKAN: Bandingkan dengan count()
            return count($features) === count(self::COLOR_FEATURE_NAMES) ? $features : null;
        } catch (Throwable $e) {
            Log::error("Color feature extraction failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function extractTextureFeatures(Imagick $img): ?array
    {
        $grayImg = null;
        try {
            $grayImg = clone $img;
            if ($grayImg->getImageColorspace() !== Imagick::COLORSPACE_GRAY) {
                $grayImg->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            }
            if (!$this->isValidImagick($grayImg)) return null;

            $width  = $grayImg->getImageWidth();
            $height = $grayImg->getImageHeight();
            $pixels = $grayImg->exportImagePixels(0, 0, $width, $height, "I", Imagick::PIXEL_FLOAT);
            if (!is_array($pixels) || empty($pixels)) return null;

            $maxVal = max($pixels);
            if ($maxVal > 0) {
                $pixels = array_map(fn($p) => $p / $maxVal, $pixels);
            }
            $grayImage2D = array_chunk($pixels, $width);
            $textureFeatures = [];

            foreach (self::GLCM_DISTANCES as $distance) {
                foreach (self::GLCM_ANGLES as $angle) {
                    $glcm = $this->calculateGLCM($grayImage2D, $distance, $angle);
                    if (!$glcm) return null;
                    $haralick = $this->calculateHaralickFeatures($glcm);
                    array_push($textureFeatures, ...array_values($haralick));
                }
            }
            // PERBAIKAN: Bandingkan dengan count()
            return count($textureFeatures) === count(self::TEXTURE_FEATURE_NAMES) ? $textureFeatures : null;
        } catch (Throwable $e) {
            Log::error("Texture feature extraction failed", ['error' => $e->getMessage()]);
            return null;
        } finally {
            $this->cleanupImagick($grayImg);
        }
    }

    private function calculateGLCM(array $grayImage, int $distance, float $angle): ?array
    {
        $glcm = array_fill(0, self::GLCM_LEVELS, array_fill(0, self::GLCM_LEVELS, 0.0));
        $dx = (int) round($distance * cos(deg2rad($angle)));
        $dy = (int) round($distance * sin(deg2rad($angle)));
        $height = count($grayImage);
        $width = count($grayImage[0]);
        $total = 0;

        for ($i = 0; $i < $height; $i++) {
            for ($j = 0; $j < $width; $j++) {
                $ni = $i + $dy;
                $nj = $j + $dx;
                if ($ni >= 0 && $ni < $height && $nj >= 0 && $nj < $width) {
                    $level1 = min(self::GLCM_LEVELS - 1, (int) ($grayImage[$i][$j] * (self::GLCM_LEVELS - 1)));
                    $level2 = min(self::GLCM_LEVELS - 1, (int) ($grayImage[$ni][$nj] * (self::GLCM_LEVELS - 1)));
                    $glcm[$level1][$level2]++;
                    $total++;
                }
            }
        }

        if ($total > 0) {
            for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
                for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                    $glcm[$i][$j] /= $total;
                }
            }
        }
        return $glcm;
    }

    private function calculateHaralickFeatures(array $glcm): array
    {
        $contrast = 0;
        $correlation = 0;
        $energy = 0;
        $homogeneity = 0;
        $meanI = 0;
        $meanJ = 0;
        $stdI = 0;
        $stdJ = 0;

        for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
            for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                $meanI += $i * $glcm[$i][$j];
                $meanJ += $j * $glcm[$i][$j];
            }
        }
        for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
            for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                $stdI += pow($i - $meanI, 2) * $glcm[$i][$j];
                $stdJ += pow($j - $meanJ, 2) * $glcm[$i][$j];
            }
        }
        $stdI = sqrt($stdI);
        $stdJ = sqrt($stdJ);

        for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
            for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                $pij = $glcm[$i][$j];
                $contrast += $pij * pow($i - $j, 2);
                if ($stdI > 0 && $stdJ > 0) $correlation += $pij * ($i - $meanI) * ($j - $meanJ) / ($stdI * $stdJ);
                $energy += $pij * $pij;
                $homogeneity += $pij / (1 + abs($i - $j));
            }
        }
        return ['contrast' => $contrast, 'correlation' => $correlation, 'energy' => $energy, 'homogeneity' => $homogeneity];
    }

    private function isValidImagick(?Imagick $img): bool
    {
        return $img instanceof Imagick && $img->getNumberImages() > 0 && $img->getImageWidth() > 0 && $img->getImageHeight() > 0;
    }

    private function cleanupImagick(?Imagick ...$imagickObjects): void
    {
        foreach ($imagickObjects as $img) {
            if ($img instanceof Imagick) $img->clear();
        }
    }
}
