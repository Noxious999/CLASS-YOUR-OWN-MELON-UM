<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TestS3Connection extends Command
{
    protected $signature = 'test:s3';
    protected $description = 'Quickly test the S3 connection and permissions for Wasabi.';

    public function handle(): int
    {
        $this->info("🚀 Memulai Tes Koneksi dan Izin ke Wasabi S3...");
        $disk = Storage::disk('s3');
        $bucket = config('filesystems.disks.s3.bucket');
        $this->line("Menggunakan bucket: <fg=cyan>{$bucket}</>");

        $overallSuccess = true;

        // Tes 1: List Directories (Memerlukan s3:ListBucket)
        $this->line("\n<fg=yellow>--- Tes 1: List Direktori ---</>");
        try {
            $this->comment("Mencoba mengambil daftar direktori di root bucket...");
            $directories = $disk->directories('/');
            $this->info("BERHASIL: Koneksi berhasil dan izin ListBucket OK.");
            $this->comment("Direktori yang ditemukan: " . (empty($directories) ? "Tidak ada" : implode(', ', $directories)));
        } catch (Throwable $e) {
            $this->error("GAGAL: Tidak bisa melakukan ListBucket.");
            $this->warn($e->getMessage());
            $overallSuccess = false;
        }

        // Tes 2: Write File (Memerlukan s3:PutObject)
        $this->line("\n<fg=yellow>--- Tes 2: Menulis File ---</>");
        $testPath = 'internal_data/s3_connection_test.txt';
        $testContent = 'Test ' . now()->toIso8601String();
        try {
            $this->comment("Mencoba menulis file tes ke: <fg=cyan>{$testPath}</>");
            $disk->put($testPath, $testContent);
            $this->info("BERHASIL: Izin PutObject OK.");
        } catch (Throwable $e) {
            $this->error("GAGAL: Tidak bisa menulis file ke S3.");
            $this->warn($e->getMessage());
            $overallSuccess = false;
        }

        // Tes 3: Read File (Memerlukan s3:GetObject)
        if ($overallSuccess) { // Hanya coba baca jika tulis berhasil
            $this->line("\n<fg=yellow>--- Tes 3: Membaca File ---</>");
            try {
                $this->comment("Mencoba membaca kembali file tes dari: <fg=cyan>{$testPath}</>");
                $readContent = $disk->get($testPath);
                if ($readContent === $testContent) {
                    $this->info("BERHASIL: Izin GetObject OK dan konten file cocok.");
                } else {
                    $this->error("GAGAL: Konten file tidak cocok setelah dibaca.");
                    $overallSuccess = false;
                }
            } catch (Throwable $e) {
                $this->error("GAGAL: Tidak bisa membaca file dari S3.");
                $this->warn($e->getMessage());
                $overallSuccess = false;
            }
        }

        // Tes 4: Delete File (Memerlukan s3:DeleteObject)
        if ($disk->exists($testPath)) {
            $this->line("\n<fg=yellow>--- Tes 4: Menghapus File ---</>");
            try {
                $this->comment("Mencoba menghapus file tes: <fg=cyan>{$testPath}</>");
                $disk->delete($testPath);
                $this->info("BERHASIL: Izin DeleteObject OK.");
            } catch (Throwable $e) {
                $this->error("GAGAL: Tidak bisa menghapus file dari S3.");
                $this->warn($e->getMessage());
                $overallSuccess = false;
            }
        }

        $this->line("\n----------------------------------------");
        if ($overallSuccess) {
            $this->info("✅ Tes Selesai. Semua tes koneksi dan izin dasar ke Wasabi S3 BERHASIL.");
        } else {
            $this->error("❌ Tes Selesai. Terdapat kegagalan pada koneksi atau izin ke Wasabi S3.");
        }
        $this->line("----------------------------------------");

        return $overallSuccess ? self::SUCCESS : self::FAILURE;
    }
}
