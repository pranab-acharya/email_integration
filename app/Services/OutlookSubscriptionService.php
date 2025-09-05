<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\EmailWebhookSubscription;
use App\Services\Mail\Providers\OutlookProvider;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Microsoft\Kiota\Abstractions\ApiException;

class OutlookSubscriptionService
{
    public function subscribe(EmailAccount $emailAccount): array
    {
        try {
            // Check if there's already an active subscription
            $existingSubscription = $emailAccount->emailWebhookSubscriptions()
                ->where('provider', 'outlook')
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->first();

            if ($existingSubscription) {
                return [
                    'success' => true,
                    'message' => 'Active subscription already exists',
                    'subscription' => $existingSubscription,
                ];
            }

            // Get user's access token
            $token = (new OutlookProvider)->ensureValidOutlookToken($emailAccount);

            // Generate secure client state
            $clientState = $this->generateClientState($emailAccount);

            // Validate notification URL before creating subscription
            $notificationUrl = config('services.azure.notification_url');

            if (! $notificationUrl) {
                throw new Exception('Notification URL not configured in services.azure.notification_url');
            }

            // Create subscription with proper configuration
            $response = Http::withToken($token)
                ->timeout(60)
                ->post('https://graph.microsoft.com/v1.0/subscriptions', [
                    'changeType' => 'created',
                    'notificationUrl' => $notificationUrl,
                    'resource' => "/me/mailfolders('inbox')/messages",
                    'expirationDateTime' => now()->addDays(2)->toIso8601String(),
                    'clientState' => $clientState,
                ]);

            Log::info('Outlook subscription response', [
                'email_account_id' => $emailAccount->id,
                'status' => $response->status(),
                'body' => $response->body(),
                'client_state' => $clientState,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Outlook subscription created', $data);
                // Deactivate any existing subscriptions for this account
                $emailAccount->emailWebhookSubscriptions()
                    ->where('provider', 'outlook')
                    ->update(['is_active' => false]);

                // Create new subscription record
                $subscription = EmailWebhookSubscription::create([
                    'email_account_id' => $emailAccount->id,
                    'provider' => 'outlook',
                    'subscription_id' => $data['id'],
                    'resource' => $data['resource'],
                    'change_types' => explode(',', $data['changeType']),
                    'notification_url' => $data['notificationUrl'],
                    'expires_at' => $data['expirationDateTime'],
                    'client_state' => $clientState,
                    'is_active' => true,
                ]);

                return [
                    'success' => true,
                    'subscription' => $subscription,
                    'message' => 'Subscription created successfully',
                ];
            }

            $error = $response->json();

            Log::error('Failed to create Outlook subscription', [
                'email_account_id' => $emailAccount->id,
                'error' => $error,
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => $error['error']['message'] ?? 'Unknown error',
                'code' => $error['error']['code'] ?? null,
            ];
        } catch (ApiException $e) {
            Log::error('Microsoft Graph API exception', [
                'email_account_id' => $emailAccount->id,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return [
                'success' => false,
                'message' => 'Graph API error: ' . $e->getMessage(),
            ];
        } catch (Exception $e) {
            Log::error('Subscription creation failed', [
                'email_account_id' => $emailAccount->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate client state from webhook notification
     */
    public function validateClientState(string $receivedState, EmailAccount $emailAccount): bool
    {
        $expectedState = $this->generateClientState($emailAccount);

        return hash_equals($expectedState, $receivedState);
    }

    // ... rest of your existing methods remain the same
    public function renewSubscription(EmailWebhookSubscription $subscription): array
    {
        try {
            $token = (new OutlookProvider)->ensureValidOutlookToken($subscription->emailAccount);

            $response = Http::withToken($token)
                ->timeout(30)
                ->patch("https://graph.microsoft.com/v1.0/subscriptions/{$subscription->subscription_id}", [
                    'expirationDateTime' => now()->addDays(3)->toIso8601String(),
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $subscription->update([
                    'expires_at' => $data['expirationDateTime'],
                ]);

                Log::info('Subscription renewed successfully', [
                    'subscription_id' => $subscription->subscription_id,
                    'new_expiry' => $data['expirationDateTime'],
                ]);

                return [
                    'success' => true,
                    'subscription' => $subscription->fresh(),
                    'message' => 'Subscription renewed successfully',
                ];
            }

            $error = $response->json();
            Log::error('Failed to renew subscription', [
                'subscription_id' => $subscription->subscription_id,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => $error['error']['message'] ?? 'Renewal failed',
            ];
        } catch (Exception $e) {
            Log::error('Subscription renewal failed', [
                'subscription_id' => $subscription->subscription_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Renewal error: ' . $e->getMessage(),
            ];
        }
    }

    public function deleteSubscription(EmailWebhookSubscription $subscription): array
    {
        try {
            $token = (new OutlookProvider)->ensureValidOutlookToken($subscription->emailAccount);

            $response = Http::withToken($token)
                ->timeout(30)
                ->delete("https://graph.microsoft.com/v1.0/subscriptions/{$subscription->subscription_id}");

            if ($response->successful() || $response->status() === 404) {
                $subscription->update(['is_active' => false]);

                Log::info('Subscription deleted successfully', [
                    'subscription_id' => $subscription->subscription_id,
                ]);

                return [
                    'success' => true,
                    'message' => 'Subscription deleted successfully',
                ];
            }

            Log::error('Failed to delete subscription', [
                'subscription_id' => $subscription->subscription_id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to delete subscription',
            ];
        } catch (Exception $e) {
            Log::error('Subscription deletion failed', [
                'subscription_id' => $subscription->subscription_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Deletion error: ' . $e->getMessage(),
            ];
        }
    }

    public function getSubscriptionsForRenewal(int $hoursBeforeExpiry = 24): \Illuminate\Database\Eloquent\Collection
    {
        return EmailWebhookSubscription::where('provider', 'outlook')
            ->where('is_active', true)
            ->where('expires_at', '<=', now()->addHours($hoursBeforeExpiry))
            ->where('expires_at', '>', now())
            ->with('emailAccount')
            ->get();
    }

    public function cleanupExpiredSubscriptions(): int
    {
        $expiredCount = EmailWebhookSubscription::where('provider', 'outlook')
            ->where('is_active', true)
            ->where('expires_at', '<', now())
            ->update(['is_active' => false]);

        if ($expiredCount > 0) {
            Log::info("Deactivated {$expiredCount} expired Outlook subscriptions");
        }

        return $expiredCount;
    }

    public function deleteAllSubscriptions(string $accessToken): void
    {
        $response = Http::withToken($accessToken)
            ->get('https://graph.microsoft.com/v1.0/subscriptions');

        $subscriptions = $response->json('value') ?? [];

        foreach ($subscriptions as $subscription) {
            $id = $subscription['id'];

            Http::withToken($accessToken)
                ->delete("https://graph.microsoft.com/v1.0/subscriptions/{$id}");

            Log::info("Deleted subscription: {$id}");
        }
    }

    /**
     * Generate consistent client state for an email account
     */
    private function generateClientState(EmailAccount $emailAccount): string
    {
        // Option 1: Simple hash-based (recommended)
        $data = [
            'account_id' => $emailAccount->id,
            'email' => $emailAccount->email,
            'provider' => 'outlook',
        ];

        return hash_hmac('sha256', json_encode($data), config('app.key'));
    }
}
