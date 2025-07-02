<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class AppMaintenanceController extends Controller
{
    /**
     * Membersihkan semua cache aplikasi.
     */
    public function clearCache(): RedirectResponse
    {
        try {
            // Membersihkan cache standar Laravel
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            // [PERUBAHAN KUNCI] Membersihkan direktori upload temporer di S3
            $tempDir = \App\Services\PredictionService::S3_UPLOAD_DIR_TEMP;
            \Illuminate\Support\Facades\Storage::disk('s3')->deleteDirectory($tempDir);
            \Illuminate\Support\Facades\Storage::disk('s3')->makeDirectory($tempDir); // Buat kembali folder kosong

            Log::info('Cache aplikasi dan direktori upload temporer dibersihkan via tombol UI.');
            return redirect()->back()->with('status', 'Cache aplikasi berhasil dibersihkan!');
        } catch (Throwable $e) {
            Log::error('Gagal membersihkan cache via tombol UI.', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Gagal membersihkan cache aplikasi. Silakan cek log.');
        }
    }
}
