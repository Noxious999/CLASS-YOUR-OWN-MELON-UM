<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    // [PERBAIKAN] Hapus semua middleware HTTP dari sini.
    // Hanya daftarkan command Artisan saja.
    protected $commands = [
        \App\Console\Commands\TrainUnifiedModel::class,
        \App\Console\Commands\ExtractFeaturesCommand::class,
        \App\Console\Commands\TestS3Connection::class,
        \App\Console\Commands\GenerateThumbnails::class,
        \App\Console\Commands\SelectFeaturesCommand::class, // <-- TAMBAHKAN BARIS INI
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
