<?php

use App\Jobs\SyncRecentGmailThreads;
use App\Jobs\SyncRecentOutlookThreads;
use App\Models\EmailAccount;
use App\Services\OutlookSubscriptionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: poll recent Gmail threads and enqueue per-thread sync
// Schedule::job(new SyncRecentGmailThreads)->withoutOverlapping()->everyMinute();
// Schedule::job(new SyncRecentOutlookThreads)->withoutOverlapping()->everyMinute();

Artisan::command('email:sync-outlook {accountId}', function ($accountId) {
    $account = EmailAccount::find($accountId);
    (new OutlookSubscriptionService)->subscribe($account);
})->describe('Sync recent Outlook threads for the given account ID');
