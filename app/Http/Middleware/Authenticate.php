<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // [PERBAIKAN] Ubah dari 'login' ke route utama aplikasi Anda.
        return $request->expectsJson() ? null : route('melon.index');
    }
}
