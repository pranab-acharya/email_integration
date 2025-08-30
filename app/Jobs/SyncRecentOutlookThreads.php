<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRecentOutlookThreads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        EmailAccount::query()
            ->where('provider', 'outlook')
            ->chunk(50, function ($accounts) {
                foreach ($accounts as $account) {
                    $this->syncAccountRecentThreads($account);
                }
            });
    }

    private function syncAccountRecentThreads(\App\Models\EmailAccount $account): void
    {
        $token = $this->ensureValidOutlookToken($account);
        if (! $token) {
            Log::warning('Skip SyncRecentOutlookThreads: invalid token', ['email' => $account->email]);

            return;
        }

        // Get messages from the last 7 days
        $sevenDaysAgo = Carbon::now()->subDays(7)->format('Y-m-d\TH:i:s\Z');

        $resp = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/me/messages', [
                '$filter' => "receivedDateTime ge {$sevenDaysAgo}",
                '$orderby' => 'receivedDateTime desc',
                '$top' => 100,
                '$select' => 'conversationId,receivedDateTime',
            ]);

        if (! $resp->successful()) {
            Log::error('Failed listing Outlook messages', [
                'email' => $account->email,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return;
        }

        $messages = $resp->json('value') ?? [];

        // Get unique conversation IDs
        $conversationIds = collect($messages)
            ->pluck('conversationId')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        Log::info('SyncRecentOutlookThreads found conversations', [
            'email' => $account->email,
            'conversation_count' => count($conversationIds),
            'message_count' => count($messages),
        ]);

        // Dispatch sync job for each unique conversation
        foreach ($conversationIds as $conversationId) {
            SyncOutlookThread::dispatch($account->id, $conversationId);
        }
    }

    private function ensureValidOutlookToken(\App\Models\EmailAccount $account): ?string
    {
        try {
            if (! $account->isExpired()) {
                return decrypt($account->access_token);
            }
            if (! $account->refresh_token) {
                return null;
            }

            $refreshToken = decrypt($account->refresh_token);
            $resp = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'client_id' => config('services.azure.client_id'),
                'client_secret' => config('services.azure.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'https://graph.microsoft.com/Mail.Read https://graph.microsoft.com/Mail.Send offline_access',
            ]);

            if (! $resp->successful()) {
                Log::error('Token refresh failed in SyncRecentOutlookThreads', [
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
            Log::error('ensureValidOutlookToken error in SyncRecentOutlookThreads: ' . $e->getMessage());

            return null;
        }
    }
}
