<?php

use App\Jobs\SyncRecentGmailThreads;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: poll recent Gmail threads and enqueue per-thread sync
Schedule::job(new SyncRecentGmailThreads)->everyMinute();
