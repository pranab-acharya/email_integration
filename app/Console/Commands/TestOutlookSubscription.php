<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Services\OutlookSubscriptionService;
use Illuminate\Console\Command;

class TestOutlookSubscription extends Command
{
    protected $signature = 'test:outlook-subscription {email?}';
    protected $description = 'Test Outlook webhook subscription';

    public function handle(OutlookSubscriptionService $service)
    {
        $email = $this->argument('email');
        $query = EmailAccount::where('provider', 'outlook');

        if ($email) {
            $query->where('email', $email);
        }

        $account = $query->first();

        if (! $account) {
            $this->error('No Outlook account found');

            return 1;
        }

        $this->info('Testing subscription for ' . $account->email);

        $result = $service->subscribe($account);

        $this->info('Result: ' . json_encode($result, JSON_PRETTY_PRINT));

        return 0;
    }
}
