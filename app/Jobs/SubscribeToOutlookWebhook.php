<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\OutlookSubscriptionService;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscribeToOutlookWebhook implements ShouldQueue
{
    use Queueable;
    public $timeout = 300; // 5 minutes timeout for the job
    public $tries = 3; // Retry 3 times if it fails

    public function __construct(
        public EmailAccount $emailAccount,
        public ?int $userId = null
    ) {}

    public function handle(OutlookSubscriptionService $service): void
    {
        Log::info("Starting Outlook subscription for email: {$this->emailAccount->email}");

        try {
            $result = $service->subscribe($this->emailAccount);

            // Update the email account record if needed
            if ($result['success'] && isset($result['subscription_id'])) {
                $this->emailAccount->update([
                    'subscription_id' => $result['subscription_id'],
                    'has_active_subscription' => true,
                    'last_subscription_attempt' => now(),
                ]);
            }

            // Send notification to the user who triggered the action
            $this->sendNotificationToUser($result);

            Log::info("Outlook subscription completed successfully for: {$this->emailAccount->email}");
        } catch (Exception $e) {
            Log::error("Outlook subscription failed for {$this->emailAccount->email}: " . $e->getMessage());

            $this->sendNotificationToUser([
                'success' => false,
                'message' => 'Subscription failed: ' . $e->getMessage(),
            ]);

            // Re-throw the exception to mark the job as failed
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Outlook subscription job failed permanently for {$this->emailAccount->email}: " . $exception->getMessage());

        // Send failure notification
        $this->sendNotificationToUser([
            'success' => false,
            'message' => 'Subscription failed permanently after multiple retries',
        ]);
    }

    private function sendNotificationToUser(array $result): void
    {
        if (! $this->userId) {
            return;
        }

        $user = \App\Models\User::find($this->userId);
        if (! $user) {
            return;
        }

        $notification = Notification::make();

        if ($result['success']) {
            $notification
                ->title('Outlook Subscription Successful')
                ->body("Successfully subscribed to webhook for {$this->emailAccount->email}")
                ->success()
                ->icon('heroicon-o-check-circle')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->button()
                        ->url('/admin/email-accounts'),
                ]);
        } else {
            $notification
                ->title('Outlook Subscription Failed')
                ->body("Failed to subscribe for {$this->emailAccount->email}: " . ($result['message'] ?? 'Unknown error'))
                ->danger()
                ->icon('heroicon-o-exclamation-triangle')
                ->persistent();
        }

        $notification->sendToDatabase($user);
        $notification->broadcast($user);

        Log::info("Notification sent to user ID {$this->userId} regarding subscription for {$this->emailAccount->email}");
    }
}
