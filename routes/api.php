<?php

use App\Http\Controllers\PredictionController;
use Illuminate\Support\Facades\Route;

Route::get('trigger-pi-camera-stream', [PredictionController::class, 'triggerPiCameraStream'])
    ->name('api.trigger_pi_camera_stream');
