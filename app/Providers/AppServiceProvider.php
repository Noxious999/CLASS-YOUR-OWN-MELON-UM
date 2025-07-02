<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request; // Gunakan ini untuk objek request
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot(Request $request) // $request sekarang akan menjadi instance dari Illuminate\Http\Request
    {
        //$host = $request->getHost(); // Anda bisa tetap menggunakan ini jika perlu di logika lain

        // SEMENTARA NONAKTIFKAN BLOK INI UNTUK TES NGROK
        /*
    if (app()->environment('local')) {
        URL::forceRootUrl($request->getSchemeAndHttpHost());
        config(['app.url' => $request->getSchemeAndHttpHost()]);

        if ($request->isSecure()) {
            URL::forceScheme('https');
        }
    }
    */

        // Alternatif yang lebih aman jika Anda benar-benar perlu forceScheme
        // HANYA jika diakses via proxy yang mengirim X-Forwarded-Proto
        // dan APP_URL di .env sudah HTTPS.
        // Pastikan TrustProxies sudah bekerja dulu.
        if ($request->server('HTTP_X_FORWARDED_PROTO') === 'https' && !app()->runningInConsole()) {
            URL::forceScheme('https');
        }
    }
}
