<?php

namespace App\Services;

use App\Jobs\SyncGmailThread;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Mail\DTOs\SendResult;
use App\Services\Mail\ProviderFactory;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailService
{
    public function sendEmail(EmailAccount $account, array $data)
    {
        try {
            // Strategy + Adapter via provider factory
            $provider = ProviderFactory::make($account->provider);
            $result = $provider->send($account, $data);

            if (! $result->success) {
                Log::warning('Provider send failed', [
                    'provider' => $account->provider,
                    'status' => $result->httpStatus,
                    'error' => $result->error,
                ]);

                return false;
            }

            // Persist as appropriate for the provider
            $this->persistSent($account, $data, $result);

            return true;
        } catch (Exception $e) {
            Log::error('Email send failed: ' . $e->getMessage());

            return false;
        }
    }

    private function persistSent(EmailAccount $account, array $data, SendResult $result): void
    {
        // For providers that return IDs (e.g., Gmail), persist immediately and dispatch sync
        if ($result->provider === 'google' && $result->externalThreadId && $result->externalMessageId) {
            $threadId = $result->externalThreadId;
            $messageId = $result->externalMessageId;

            $participants = array_values(array_unique(array_filter(array_merge([$account->email], $data['to'] ?? []))));
            $thread = EmailThread::firstOrCreate(
                [
                    'email_account_id' => $account->id,
                    'external_thread_id' => $threadId,
                ],
                [
                    'provider' => 'google',
                    'originated_via_app' => true,
                    'subject' => $data['subject'] ?? null,
                    'participants' => $participants,
                    'last_message_at' => now(),
                ]
            );

            if (! $thread->originated_via_app) {
                $thread->originated_via_app = true;
                $thread->save();
            }

            EmailMessage::updateOrCreate(
                [
                    'email_account_id' => $account->id,
                    'external_message_id' => $messageId,
                ],
                [
                    'email_thread_id' => $thread->id,
                    'provider' => 'google',
                    'external_thread_id' => $threadId,
                    'direction' => 'outgoing',
                    'sent_via_app' => true,
                    'subject' => $data['subject'] ?? null,
                    'from_email' => $account->email,
                    'from_name' => $account->name,
                    'to' => $data['to'] ?? [],
                    'cc' => $data['cc'] ?? [],
                    'bcc' => $data['bcc'] ?? [],
                    'body_text' => null,
                    'body_html' => $data['body'] ?? null,
                    'headers' => $result->headers ?? ['x-app-sent' => '1'],
                    'snippet' => null,
                    'sent_at' => now(),
                    'received_at' => null,
                ]
            );

            SyncGmailThread::dispatch($account->id, $threadId);
        }

        // For providers that do not return IDs (e.g., Outlook), skip persistence for now.
    }

    private function sendGmail(EmailAccount $account, array $data)
    {
        // Ensure we have a valid token (refresh if expired)
        $token = $this->ensureValidGoogleToken($account);
        if (! $token) {
            Log::warning('Gmail send aborted due to missing/invalid token');

            return false;
        }

        $message = $this->createGmailMessage($data, $account);

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $message,
            ]);

        // If unauthorized/forbidden, attempt one refresh and retry once
        if (in_array($response->status(), [401, 403], true)) {
            Log::info('Gmail send received unauthorized/forbidden. Attempting token refresh and retry.');
            if ($this->refreshGoogleToken($account)) {
                $token = decrypt($account->access_token);
                $response = Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                        'raw' => $message,
                    ]);
            }
        }

        if (! $response->successful()) {
            Log::error('Gmail send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        // Persist outgoing message and trigger sync when successful
        if ($response->successful()) {
            $payload = $response->json() ?? [];
            $threadId = $payload['threadId'] ?? null;
            $messageId = $payload['id'] ?? null;

            if ($threadId && $messageId) {
                // Create/Update thread quickly; detailed data will be refined by the sync job
                $participants = array_values(array_unique(array_filter(array_merge([$account->email], $data['to'] ?? []))));
                $thread = EmailThread::firstOrCreate(
                    [
                        'email_account_id' => $account->id,
                        'external_thread_id' => $threadId,
                    ],
                    [
                        'provider' => 'google',
                        'originated_via_app' => true,
                        'subject' => $data['subject'] ?? null,
                        'participants' => $participants,
                        'last_message_at' => now(),
                    ]
                );

                // Ensure existing threads are marked as originated via the app
                if (! $thread->originated_via_app) {
                    $thread->originated_via_app = true;
                    $thread->save();
                }

                EmailMessage::updateOrCreate(
                    [
                        'email_account_id' => $account->id,
                        'external_message_id' => $messageId,
                    ],
                    [
                        'email_thread_id' => $thread->id,
                        'provider' => 'google',
                        'external_thread_id' => $threadId,
                        'direction' => 'outgoing',
                        'sent_via_app' => true,
                        'subject' => $data['subject'] ?? null,
                        'from_email' => $account->email,
                        'from_name' => $account->name,
                        'to' => $data['to'] ?? [],
                        'cc' => $data['cc'] ?? [],
                        'bcc' => $data['bcc'] ?? [],
                        'body_text' => null,
                        'body_html' => $data['body'] ?? null,
                        'headers' => ['x-app-sent' => '1'],
                        'snippet' => null,
                        'sent_at' => now(),
                        'received_at' => null,
                    ]
                );

                // Fire background sync to pull full thread and normalize bodies/participants
                SyncGmailThread::dispatch($account->id, $threadId);
            }
        }

        return $response->successful();
    }

    private function sendOutlook(EmailAccount $account, array $data)
    {
        $token = decrypt($account->access_token);

        $response = Http::withToken($token)
            ->post('https://graph.microsoft.com/v1.0/me/sendMail', [
                'message' => [
                    'subject' => $data['subject'],
                    'body' => [
                        'contentType' => 'HTML',
                        'content' => $data['body'],
                    ],
                    'toRecipients' => array_map(fn ($email) => [
                        'emailAddress' => ['address' => $email],
                    ], $data['to']),
                ],
            ]);

        return $response->successful();
    }

    private function createGmailMessage(array $data, EmailAccount $account)
    {
        $headers = [
            "From: {$account->name} <{$account->email}>",
            'To: ' . implode(', ', $data['to']),
            "Subject: {$data['subject']}",
            'X-App-Sent: 1',
            'Content-Type: text/html; charset=utf-8',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $data['body'];

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }

    private function ensureValidGoogleToken(EmailAccount $account): ?string
    {
        try {
            // If not expired, return current token
            if (! $account->isExpired()) {
                return decrypt($account->access_token);
            }

            // Expired but we might have a refresh token
            if (! $account->refresh_token) {
                Log::warning('Access token expired and no refresh token available for account', [
                    'email' => $account->email,
                ]);

                return null;
            }

            if ($this->refreshGoogleToken($account)) {
                return decrypt($account->access_token);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Failed ensuring valid Google token: ' . $e->getMessage());

            return null;
        }
    }

    private function refreshGoogleToken(EmailAccount $account): bool
    {
        try {
            $refreshToken = decrypt($account->refresh_token);
        } catch (Throwable $e) {
            Log::error('Failed to decrypt refresh token: ' . $e->getMessage());

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

        // Persist new tokens and expiry
        $account->access_token = encrypt($data['access_token']);
        if (! empty($data['refresh_token'])) {
            $account->refresh_token = encrypt($data['refresh_token']);
        }
        if (! empty($data['expires_in'])) {
            $account->expires_at = now()->addSeconds($data['expires_in']);
        }
        $account->save();

        Log::info('Google access token refreshed successfully', [
            'email' => $account->email,
        ]);

        return true;
    }
}
