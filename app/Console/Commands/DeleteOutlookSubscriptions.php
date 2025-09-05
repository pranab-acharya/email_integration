<?php

namespace App\Console\Commands;

use App\Models\EmailWebhookSubscription;
use App\Services\OutlookSubscriptionService;
use Illuminate\Console\Command;

class DeleteOutlookSubscriptions extends Command
{
    protected $signature = 'outlook:delete-subscriptions';
    protected $description = 'Delete all active Outlook webhook subscriptions';

    public function handle()
    {
        $this->info('Fetching active Outlook subscriptions...');

        $subscriptions = EmailWebhookSubscription::where('provider', 'outlook')
            ->where('is_active', true)
            ->with('emailAccount')
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No active subscriptions found.');

            return;
        }

        $this->info(sprintf('Found %d active subscriptions.', $subscriptions->count()));

        $service = new OutlookSubscriptionService;

        foreach ($subscriptions as $subscription) {
            $this->info(sprintf(
                'Deleting subscription %s for %s...',
                $subscription->subscription_id,
                $subscription->emailAccount?->email ?? 'unknown'
            ));

            $result = $service->deleteSubscription($subscription);

            if ($result['success']) {
                $this->info('✓ Successfully deleted.');
            } else {
                $this->error('✗ Failed: ' . $result['message']);
            }
        }

        $this->info('Done.');
    }
}
