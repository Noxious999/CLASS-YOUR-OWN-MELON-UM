<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeedbackLogService
{
    /**
     * [PERUBAHAN] Path ke file log sekarang di dalam bucket S3.
     */
    private const LOG_FILE_PATH = 'internal_data/feedback_log.json';

    /**
     * Memeriksa apakah sebuah nama file sudah pernah di-log.
     */
    public function hasBeenLogged(string $filename): bool
    {
        $log = $this->readLog();
        return isset($log[$filename]);
    }

    // ▼▼▼ TAMBAHKAN METHOD BARU DI SINI ▼▼▼
    /**
     * Mengambil detail entri log untuk sebuah file.
     *
     * @param string $filename Nama file yang dicari.
     * @return array|null Data log jika ditemukan, atau null jika tidak ada.
     */
    public function getLogEntry(string $filename): ?array
    {
        $log = $this->readLog();
        return $log[$filename] ?? null;
    }
    // ▲▲▲ AKHIR METHOD BARU ▲▲▲

    /**
     * Menambahkan entri baru ke dalam file log.
     */
    public function log(string $filename, string $feedbackType, array $context = []): void
    {
        $log = $this->readLog();
        // [PERUBAHAN] Menyimpan lebih banyak detail pada log
        $log[$filename] = [
            'logged_at' => now()->toIso8601String(),
            'feedback_type' => $feedbackType, // 'confirmed' atau 'correction_needed'
            'context' => $context,
        ];
        $this->writeLog($log);
    }

    /**
     * Membaca dan mendekode file log JSON dari S3.
     */
    private function readLog(): array
    {
        try {
            // [PERUBAHAN] Menggunakan disk 's3'
            if (!Storage::disk('s3')->exists(self::LOG_FILE_PATH)) {
                return [];
            }
            $content = Storage::disk('s3')->get(self::LOG_FILE_PATH);
            return json_decode($content, true) ?: [];
        } catch (Throwable $e) {
            // Log error jika gagal baca dari S3, ini penting untuk debugging
            Log::error('Gagal membaca feedback_log.json dari S3.', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Menulis array ke file log JSON di S3.
     */
    private function writeLog(array $data): void
    {
        // [PERUBAHAN] Menggunakan disk 's3'
        // makeDirectory tidak diperlukan untuk S3
        Storage::disk('s3')->put(
            self::LOG_FILE_PATH,
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Menghapus entri log berdasarkan nama file.
     */
    public function deleteLog(string $filename): bool
    {
        $log = $this->readLog();
        if (isset($log[$filename])) {
            unset($log[$filename]);
            $this->writeLog($log);
            return true; // Berhasil dihapus
        }
        return false; // Entri tidak ditemukan
    }
}
