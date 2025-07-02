<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request; // Pastikan ini di-import dengan benar
use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    // Untuk Ngrok dan proxy lain yang IP-nya bisa berubah,
    // '*' adalah cara termudah untuk testing.
    // Untuk produksi sebenarnya, lebih baik daftarkan IP proxy spesifik jika memungkinkan.
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    // Baris ini memberitahu Laravel untuk mempercayai header X-Forwarded-*
    // yang dikirim oleh Ngrok untuk menentukan skema (http/https), host, port, dll.
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB; // HEADER_X_FORWARDED_AWS_ELB bisa opsional
}
