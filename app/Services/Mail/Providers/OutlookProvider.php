<?php

namespace App\Services\Mail\Providers;

use App\Models\EmailAccount;
use App\Services\Mail\Contracts\EmailProvider;
use App\Services\Mail\DTOs\SendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutlookProvider implements EmailProvider
{
    public function key(): string
    {
        return 'outlook';
    }

    public function send(EmailAccount $account, array $data): SendResult
    {
        try {
            $token = $this->ensureValidOutlookToken($account);
            if (! $token) {
                return SendResult::failed($this->key(), 401, 'Missing or invalid access token');
            }

            // Create draft first to get message ID
            $messageData = $this->createMessageData($data, $account);

            $draftResponse = $this->makeRequestWithTokenRetry($account, function ($token) use ($messageData) {
                return Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://graph.microsoft.com/v1.0/me/messages', $messageData['message']);
            });

            if (! $draftResponse->successful()) {
                Log::error('Outlook draft creation failed', [
                    'status' => $draftResponse->status(),
                    'body' => $draftResponse->body(),
                ]);

                return SendResult::failed($this->key(), $draftResponse->status(), $draftResponse->body());
            }

            $draftData = $draftResponse->json();
            $messageId = $draftData['id'] ?? null;
            $conversationId = $draftData['conversationId'] ?? null;

            // Send the draft with token retry
            $sendResponse = $this->makeRequestWithTokenRetry($account, function ($token) use ($messageId) {
                return Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post("https://graph.microsoft.com/v1.0/me/messages/{$messageId}/send");
            });

            if (! $sendResponse->successful()) {
                Log::error('Outlook send failed', [
                    'status' => $sendResponse->status(),
                    'body' => $sendResponse->body(),
                ]);

                return SendResult::failed($this->key(), $sendResponse->status(), $sendResponse->body());
            }

            return SendResult::ok($this->key(), $messageId, $conversationId, ['x-app-sent' => '1'], $sendResponse->status());
        } catch (Throwable $e) {
            Log::error('OutlookProvider::send exception: ' . $e->getMessage());

            return SendResult::failed($this->key(), null, $e->getMessage());
        }
    }

    public function ensureValidOutlookToken(EmailAccount $account): ?string
    {
        try {
            if (! $account->isExpired()) {
                return decrypt($account->access_token);
            }

            if (! $account->refresh_token) {
                Log::warning('Outlook token expired and no refresh token available', ['email' => $account->email]);

                return null;
            }

            if ($this->refreshOutlookToken($account)) {
                return decrypt($account->access_token);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Outlook ensureValidOutlookToken error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Make a request with automatic token refresh and retry on 401/403
     */
    private function makeRequestWithTokenRetry(EmailAccount $account, callable $requestCallback)
    {
        $token = $this->ensureValidOutlookToken($account);
        if (! $token) {
            // Return a mock failed response
            return new class
            {
                public function successful()
                {
                    return false;
                }

                public function status()
                {
                    return 401;
                }

                public function body()
                {
                    return 'Invalid or missing access token';
                }

                public function json()
                {
                    return [];
                }
            };
        }

        $response = $requestCallback($token);

        // If we get 401/403, try refreshing token and retry once
        if (in_array($response->status(), [401, 403], true)) {
            Log::info('Outlook request unauthorized/forbidden; attempting token refresh and retry.');

            if ($this->refreshOutlookToken($account)) {
                $newToken = decrypt($account->access_token);
                $response = $requestCallback($newToken);

                if ($response->successful()) {
                    Log::info('Outlook request succeeded after token refresh.');
                } else {
                    Log::warning('Outlook request still failed after token refresh', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } else {
                Log::error('Failed to refresh Outlook token');
            }
        }

        return $response;
    }

    private function createMessageData(array $data, EmailAccount $account): array
    {
        $messageData = [
            'message' => [
                'subject' => $data['subject'] ?? '',
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $data['body'] ?? '',
                ],
                'toRecipients' => array_map(fn ($email) => [
                    'emailAddress' => ['address' => $email],
                ], $data['to'] ?? []),
                'from' => [
                    'emailAddress' => [
                        'address' => $account->email,
                        'name' => $account->name,
                    ],
                ],
                'internetMessageHeaders' => [
                    [
                        'name' => 'X-App-Sent',
                        'value' => '1',
                    ],
                ],
            ],
        ];

        // Add CC recipients if provided
        if (! empty($data['cc'])) {
            $messageData['message']['ccRecipients'] = array_map(fn ($email) => [
                'emailAddress' => ['address' => $email],
            ], $data['cc']);
        }

        // Add BCC recipients if provided
        if (! empty($data['bcc'])) {
            $messageData['message']['bccRecipients'] = array_map(fn ($email) => [
                'emailAddress' => ['address' => $email],
            ], $data['bcc']);
        }

        return $messageData;
    }

    private function refreshOutlookToken(EmailAccount $account): bool
    {
        try {
            $refreshToken = decrypt($account->refresh_token);
        } catch (Throwable $e) {
            Log::error('Outlook refresh token decrypt failed: ' . $e->getMessage());

            return false;
        }

        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.azure.client_id'),
            'client_secret' => config('services.azure.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Mail.Send offline_access',
        ]);

        if (! $response->successful()) {
            Log::error('Microsoft token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $data = $response->json();
        $account->access_token = encrypt($data['access_token']);
        if (! empty($data['refresh_token'])) {
            $account->refresh_token = encrypt($data['refresh_token']);
        }
        if (! empty($data['expires_in'])) {
            $account->expires_at = now()->addSeconds($data['expires_in']);
        }
        $account->save();

        Log::info('Microsoft access token refreshed successfully', ['email' => $account->email]);

        return true;
    }
}
