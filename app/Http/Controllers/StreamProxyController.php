<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamProxyController extends Controller
{
    public function getPiVideoStream()
    {
        // Ambil URL Pi dari konfigurasi Anda
        $piStreamUrl = config('app.raspberry_pi_url_flask', env('RASPBERRY_PI_URL_FLASK')) . '/video_feed';

        // Validasi URL
        if (!$piStreamUrl || filter_var($piStreamUrl, FILTER_VALIDATE_URL) === false) {
            // Mengembalikan gambar placeholder atau pesan error jika URL tidak valid
            return response('URL Raspberry Pi tidak valid.', 500);
        }

        $response = new StreamedResponse(function () use ($piStreamUrl) {

            // Nonaktifkan buffering output PHP
            if (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Membuka koneksi stream ke Raspberry Pi
            // @ digunakan untuk menekan warning jika koneksi gagal, kita tangani di bawah
            $streamHandle = @fopen($piStreamUrl, 'r');

            if (!$streamHandle) {
                // Jika koneksi ke Pi gagal, kita bisa log error atau berhenti
                // Untuk sekarang, kita cukup hentikan skrip.
                // Anda bisa membuat gambar "koneksi gagal" dan menampilkannya di sini.
                error_log("Gagal membuka stream ke: " . $piStreamUrl);
                return;
            }

            // Selama koneksi terbuka, baca data dari Pi dan langsung kirim ke browser
            while (false !== ($chunk = fread($streamHandle, 1024))) {
                echo $chunk;
                flush(); // Dorong output ke browser
            }

            fclose($streamHandle);
        });

        // Set header untuk memberitahu browser bahwa ini adalah stream MJPEG
        $response->headers->set('Content-Type', 'multipart/x-mixed-replace; boundary=frame');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Untuk Nginx

        return $response;
    }
}
