<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRecentGmailThreads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        EmailAccount::query()
            ->where('provider', 'google')
            ->chunk(50, function ($accounts) {
                foreach ($accounts as $account) {
                    $this->syncAccountRecentThreads($account);
                }
            });
    }

    private function syncAccountRecentThreads(\App\Models\EmailAccount $account): void
    {
        $token = $this->ensureValidGoogleToken($account);
        if (! $token) {
            Log::warning('Skip SyncRecentGmailThreads: invalid token', ['email' => $account->email]);

            return;
        }

        $resp = Http::withToken($token)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/threads', [
                'maxResults' => 50,
                'q' => 'newer_than:7d',
            ]);

        if (! $resp->successful()) {
            Log::error('Failed listing Gmail threads', [
                'email' => $account->email,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return;
        }

        foreach ($resp->json('threads') ?? [] as $t) {
            $threadId = $t['id'] ?? null;
            if ($threadId) {
                SyncGmailThread::dispatch($account->id, $threadId);
            }
        }
    }

    private function ensureValidGoogleToken(\App\Models\EmailAccount $account): ?string
    {
        try {
            if (! $account->isExpired()) {
                return decrypt($account->access_token);
            }
            if (! $account->refresh_token) {
                return null;
            }

            $refreshToken = decrypt($account->refresh_token);
            $resp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (! $resp->successful()) {
                Log::error('Token refresh failed in SyncRecentGmailThreads', [
                    'email' => $account->email,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

                return null;
            }

            $data = $resp->json();
            $account->access_token = encrypt($data['access_token']);
            if (! empty($data['refresh_token'])) {
                $account->refresh_token = encrypt($data['refresh_token']);
            }
            if (! empty($data['expires_in'])) {
                $account->expires_at = now()->addSeconds($data['expires_in']);
            }
            $account->save();

            return decrypt($account->access_token);
        } catch (Throwable $e) {
            Log::error('ensureValidGoogleToken error in SyncRecentGmailThreads: ' . $e->getMessage());

            return null;
        }
    }
}
