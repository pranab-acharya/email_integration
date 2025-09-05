<?php

namespace App\Services\Mail\Providers;

// referenced by caller for dispatch, not here
use App\Models\EmailAccount;
use App\Services\Mail\Contracts\EmailProvider;
use App\Services\Mail\DTOs\SendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GmailProvider implements EmailProvider
{
    public function key(): string
    {
        return 'google';
    }

    public function send(EmailAccount $account, array $data): SendResult
    {
        try {
            $token = $this->ensureValidGoogleToken($account);
            if (! $token) {
                return SendResult::failed($this->key(), 401, 'Missing or invalid access token');
            }

            $raw = $this->createRawMessage($data, $account);

            $response = Http::withToken($token)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'raw' => $raw,
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                Log::info('Gmail send unauthorized/forbidden; attempting token refresh and retry.');
                if ($this->refreshGoogleToken($account)) {
                    $token = decrypt($account->access_token);
                    $response = Http::withToken($token)
                        ->withHeaders(['Content-Type' => 'application/json'])
                        ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                            'raw' => $raw,
                        ]);
                }
            }

            if (! $response->successful()) {
                Log::error('Gmail send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return SendResult::failed($this->key(), $response->status(), $response->body());
            }

            $payload = $response->json() ?? [];
            $threadId = $payload['threadId'] ?? null;
            $messageId = $payload['id'] ?? null;

            return SendResult::ok($this->key(), $messageId, $threadId, ['x-app-sent' => '1'], $response->status());
        } catch (Throwable $e) {
            Log::error('GmailProvider::send exception: ' . $e->getMessage());

            return SendResult::failed($this->key(), null, $e->getMessage());
        }
    }

    private function createRawMessage(array $data, EmailAccount $account): string
    {
        $headers = [
            "From: {$account->name} <{$account->email}>",
            'To: ' . implode(', ', $data['to'] ?? []),
            "Subject: {$data['subject']}",
            'X-App-Sent: 1',
            'Content-Type: text/html; charset=utf-8',
        ];

        if (! empty($data['cc'])) {
            $headers[] = 'Cc: ' . implode(', ', $data['cc']);
        }
        if (! empty($data['bcc'])) {
            $headers[] = 'Bcc: ' . implode(', ', $data['bcc']);
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . ($data['body'] ?? '');

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }

    private function ensureValidGoogleToken(EmailAccount $account): ?string
    {
        try {
            if (! $account->isExpired()) {
                return decrypt($account->access_token);
            }
            if (! $account->refresh_token) {
                Log::warning('Gmail token expired and no refresh token available', ['email' => $account->email]);

                return null;
            }

            if ($this->refreshGoogleToken($account)) {
                return decrypt($account->access_token);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Gmail ensureValidGoogleToken error: ' . $e->getMessage());

            return null;
        }
    }

    private function refreshGoogleToken(EmailAccount $account): bool
    {
        try {
            $refreshToken = decrypt($account->refresh_token);
        } catch (Throwable $e) {
            Log::error('Gmail refresh token decrypt failed: ' . $e->getMessage());

            return false;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::error('Google token refresh failed', [
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

        Log::info('Google access token refreshed successfully', ['email' => $account->email]);

        return true;
    }
}
