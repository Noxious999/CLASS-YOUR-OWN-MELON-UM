<?php

namespace App\Persisters;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Rubix\ML\Persistable;
use Rubix\ML\Persisters\Filesystem;
use RuntimeException;
use Throwable;
use Rubix\ML\Encoding;

class S3ObjectPersister
{
    protected string $s3Path;

    public function __construct(string $s3PathInBucket)
    {
        $this->s3Path = $s3PathInBucket;
    }

    public function save(Persistable $estimator, string $visibility = 'private'): void
    {
        $localTempPath = tempnam(sys_get_temp_dir(), 'rubix_model_') . '.model';

        try {
            $serializedData = serialize($estimator);
            $encoding = new Encoding($serializedData);

            $persister = new Filesystem($localTempPath);
            $persister->save($encoding);

            // LOGGING TAMBAHAN UNTUK DIAGNOSIS
            $fileSize = filesize($localTempPath);
            if ($fileSize === false || $fileSize === 0) {
                throw new RuntimeException("Gagal menyimpan ke file temporer atau file kosong. Path: {$localTempPath}");
            }
            Log::debug("Model berhasil disimpan ke file temporer lokal. Ukuran: {$fileSize} bytes.", ['path' => $localTempPath]);

            $uploaded = Storage::disk('s3')->putFileAs(
                dirname($this->s3Path),
                $localTempPath,
                basename($this->s3Path),
                $visibility
            );

            if (!$uploaded) {
                throw new RuntimeException("Gagal mengunggah file model dari '{$localTempPath}' ke S3 '{$this->s3Path}'.");
            }

            Log::info("Objek berhasil diunggah ke S3", ['s3_path' => $this->s3Path, 'size' => $fileSize]);
        } catch (Throwable $e) {
            Log::error("Exception saat menyimpan objek ke S3", ['s3_path' => $this->s3Path, 'error' => $e->getMessage()]);
            throw new RuntimeException("Exception saat menyimpan objek ke S3: " . $e->getMessage(), 0, $e);
        } finally {
            if (file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }

    public function load(): Persistable
    {
        if (!Storage::disk('s3')->exists($this->s3Path)) {
            Log::error("Objek model tidak ditemukan di S3 untuk dimuat", ['s3_path' => $this->s3Path]);
            throw new RuntimeException("Objek tidak ditemukan di S3: {$this->s3Path}");
        }

        $localTempPath = tempnam(sys_get_temp_dir(), 'rubix_load_') . '.model';

        try {
            $fileContent = Storage::disk('s3')->get($this->s3Path);
            if ($fileContent === null) {
                throw new RuntimeException("Gagal mengambil konten file dari S3 (null returned) untuk path: {$this->s3Path}");
            }

            // LOGGING TAMBAHAN UNTUK DIAGNOSIS
            Log::debug("Konten model berhasil diunduh dari S3. Ukuran: " . strlen($fileContent) . " bytes.", ['s3_path' => $this->s3Path]);

            file_put_contents($localTempPath, $fileContent);

            $persister = new Filesystem($localTempPath);
            $encoding = $persister->load();
            $serializedData = $encoding->data();

            // PENGECEKAN KRITIS YANG BARU
            $loadedObject = unserialize($serializedData);
            if ($loadedObject === false) {
                Log::error("UNSERIALIZE GAGAL! Konten file model mungkin korup atau tidak kompatibel.", ['s3_path' => $this->s3Path]);
                throw new RuntimeException("Gagal melakukan unserialize pada objek model dari S3. File mungkin korup. Path: {$this->s3Path}");
            }

            Log::info("Objek berhasil di-unserialize dari S3", ['s3_path' => $this->s3Path, 'class' => get_class($loadedObject)]);
            return $loadedObject;
        } catch (Throwable $e) {
            Log::error("Exception saat memuat objek dari S3", ['s3_path' => $this->s3Path, 'error' => $e->getMessage()]);
            throw new RuntimeException("Exception saat memuat objek dari S3: " . $e->getMessage(), 0, $e);
        } finally {
            if (file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }
}
