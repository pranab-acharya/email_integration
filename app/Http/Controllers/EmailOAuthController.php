<?php

namespace App\Http\Controllers;

use App\Filament\Resources\EmailAccountResource;
use App\Jobs\ProcessOutlookWebhookEmail;
use App\Models\EmailAccount;
use App\Models\EmailWebhookSubscription;
use App\Services\OutlookSubscriptionService;
use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class EmailOAuthController extends Controller
{
    public function connect(string $provider)
    {
        // Map microsoft to azure driver
        $socialiteProvider = $provider === 'microsoft' ? 'azure' : $provider;

        if (! in_array($provider, ['google', 'microsoft'])) {
            return redirect()->back()->with('error', 'Invalid provider');
        }

        $scopes = $provider === 'google'
            ? ['https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.readonly']
            : [
                'openid',
                'profile',
                'offline_access',
                'email',
                'https://graph.microsoft.com/Mail.Send',
                'https://graph.microsoft.com/Mail.ReadWrite',
            ];

        $driver = Socialite::driver($socialiteProvider)->scopes($scopes);

        if ($provider === 'google') {
            return $driver->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])->redirect();
        }

        // For Microsoft/Azure
        return $driver->with([
            'prompt' => 'consent',
            'response_type' => 'code',
        ])->redirect();
    }

    public function callback(string $provider)
    {
        try {
            // Map microsoft to azure driver for callback too
            $socialiteProvider = $provider === 'microsoft' ? 'azure' : $provider;

            $socialiteUser = Socialite::driver($socialiteProvider)->stateless()->user();

            Log::info('Socialite user retrieved', ['user' => $socialiteUser]);

            EmailAccount::updateOrCreate(
                [
                    'user_id' => Filament::auth()->user()->id,
                    'email' => $socialiteUser->getEmail(),
                    'provider' => $provider === 'microsoft' ? 'outlook' : $provider,
                ],
                [
                    'name' => $socialiteUser->getName(),
                    'microsoft_user_id' => $provider === 'microsoft' ? $socialiteUser->id : null,
                    'access_token' => encrypt($socialiteUser->token),
                    'refresh_token' => $socialiteUser->refreshToken
                        ? encrypt($socialiteUser->refreshToken)
                        : null,
                    'expires_at' => $socialiteUser->expiresIn
                        ? now()->addSeconds($socialiteUser->expiresIn)
                        : null,
                ]
            );

            Notification::make()
                ->success()
                ->title('Email account connected successfully.')
                ->send();

            return redirect(EmailAccountResource::getUrl('index'));
        } catch (Exception $e) {
            return redirect(EmailAccountResource::getUrl('index'))
                ->with('error', 'Connection failed: ' . $e->getMessage());
        }
    }

    /**
        📧 New Email Arrives
            ↓
        🔔 Microsoft sends webhook → Your App
            ↓
        🎯 Controller receives notification
            ↓
        📦 Job dispatched to queue
            ↓ (Background processing)
        🔍 Job extracts messageId & userId
            ↓
        💾 Find email account in database
            ↓
        🔑 Get/refresh access token
            ↓
        📨 Fetch full email from Microsoft Graph
            ↓
        ⚙️ Process email (save, notify, etc.)
     */
    public function handleNotification(Request $request)
    {
        Log::info('Webhook received', [
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        $subscriptionService = new OutlookSubscriptionService;

        // Handle validation token
        $validationToken = $request->query('validationToken') ?? $request->input('validationToken');
        if ($validationToken) {
            return response($validationToken, 200, ['Content-Type' => 'text/plain']);
        }

        $notifications = $request->input('value', []);

        foreach ($notifications as $notification) {
            $clientState = $notification['clientState'] ?? null;
            $subscriptionId = $notification['subscriptionId'] ?? null;

            if (! $clientState || ! $subscriptionId) {
                Log::warning('Missing client state or subscription ID');

                continue;
            }

            // Try to find account by client state first
            $subscription = EmailWebhookSubscription::with(['emailAccount'])->where('subscription_id', $subscriptionId)
                ->where('client_state', $clientState)
                ->first();
            if (! $subscription) {
                Log::warning('No subscription found for notification', [
                    'client_state' => $clientState,
                    'subscription_id' => $subscriptionId,
                ]);

                continue;
            }
            $emailAccount = $subscription->emailAccount;

            if (! $emailAccount) {
                Log::warning('No email account found for notification', [
                    'client_state' => $clientState,
                    'subscription_id' => $subscriptionId,
                ]);

                continue;
            }

            ProcessOutlookWebhookEmail::dispatch($notification, $emailAccount);
        }

        return response()->json(['status' => 'ok']);
    }
}
