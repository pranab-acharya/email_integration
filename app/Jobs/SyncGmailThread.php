<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncGmailThread implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $emailAccountId,
        public string $threadId
    ) {}

    public function handle(): void
    {
        $account = EmailAccount::find($this->emailAccountId);
        if (! $account || $account->provider !== 'google') {
            return;
        }

        $token = $this->ensureValidGoogleToken($account);
        if (! $token) {
            Log::warning('SyncGmailThread aborted: invalid token', ['email' => $account->email]);

            return;
        }

        $resp = Http::withToken($token)
            ->get("https://gmail.googleapis.com/gmail/v1/users/me/threads/{$this->threadId}", [
                'format' => 'full',
            ]);

        if (! $resp->successful()) {
            Log::error('Failed to fetch Gmail thread', [
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return;
        }

        $threadJson = $resp->json();

        // Determine if this thread should be persisted (app-originated)
        $existingThread = EmailThread::where('email_account_id', $account->id)
            ->where('external_thread_id', $threadJson['id'])
            ->first();

        $shouldPersist = (bool) ($existingThread?->originated_via_app);
        $hasAppSent = false;

        foreach ($threadJson['messages'] ?? [] as $msg) {
            $headers = $this->headersToAssoc($msg['payload']['headers'] ?? []);
            $xAppSent = strtolower(trim((string) ($headers['x-app-sent'] ?? '')));
            if ($xAppSent === '1' || $xAppSent === 'true' || $xAppSent === 'yes') {
                $hasAppSent = true;
                break; // header found, no need to scan further for decision
            }
        }

        $shouldPersist = $shouldPersist || $hasAppSent;
        if (! $shouldPersist) {
            // Do not persist threads that didn't originate from the app
            return;
        }

        // Ensure thread exists (create if needed) and mark origin
        $thread = $existingThread ?: EmailThread::create([
            'email_account_id' => $account->id,
            'external_thread_id' => $threadJson['id'],
            'provider' => 'google',
        ]);
        if ($hasAppSent && ! $thread->originated_via_app) {
            $thread->originated_via_app = true;
            $thread->save();
        }

        $lastMessageAt = $thread->last_message_at;
        $participants = collect($thread->participants ?? []);

        foreach ($threadJson['messages'] ?? [] as $msg) {
            $headers = $this->headersToAssoc($msg['payload']['headers'] ?? []);
            $subject = $headers['subject'] ?? null;
            $from = $this->parseAddress($headers['from'] ?? '');
            $to = $this->parseAddresses($headers['to'] ?? '');
            $cc = $this->parseAddresses($headers['cc'] ?? '');
            $bcc = $this->parseAddresses($headers['bcc'] ?? '');
            $snippet = $msg['snippet'] ?? null;
            $internalDateMs = isset($msg['internalDate']) ? (int) $msg['internalDate'] : null;
            $occurredAt = $internalDateMs ? Carbon::createFromTimestampMs($internalDateMs) : null;

            $bodyParts = $this->extractBody($msg['payload'] ?? []);

            // Merge participants
            if ($from['email']) {
                $participants->push($from['email']);
            }
            foreach ($to as $addr) {
                if ($addr['email']) {
                    $participants->push($addr['email']);
                }
            }

            EmailMessage::updateOrCreate(
                [
                    'email_account_id' => $account->id,
                    'external_message_id' => $msg['id'],
                ],
                [
                    'email_thread_id' => $thread->id,
                    'provider' => 'google',
                    'external_thread_id' => $threadJson['id'],
                    'direction' => strcasecmp($from['email'] ?? '', $account->email) === 0 ? 'outgoing' : 'incoming',
                    'sent_via_app' => (function () use ($headers) {
                        $x = strtolower(trim((string) ($headers['x-app-sent'] ?? '')));

                        return $x === '1' || $x === 'true' || $x === 'yes';
                    })(),
                    'subject' => $subject,
                    'from_email' => $from['email'] ?? null,
                    'from_name' => $from['name'] ?? null,
                    'to' => array_values(array_unique(array_map(fn ($a) => $a['email'], $to))),
                    'cc' => array_values(array_unique(array_map(fn ($a) => $a['email'], $cc))),
                    'bcc' => array_values(array_unique(array_map(fn ($a) => $a['email'], $bcc))),
                    'body_text' => $bodyParts['text'] ?? null,
                    'body_html' => $bodyParts['html'] ?? null,
                    'headers' => $headers,
                    'snippet' => $snippet,
                    'sent_at' => strcasecmp($from['email'] ?? '', $account->email) === 0 ? $occurredAt : null,
                    'received_at' => strcasecmp($from['email'] ?? '', $account->email) !== 0 ? $occurredAt : null,
                ]
            );

            if (! $lastMessageAt || ($occurredAt && $occurredAt->gt($lastMessageAt))) {
                $lastMessageAt = $occurredAt;
            }

            if ($subject && ! $thread->subject) {
                $thread->subject = $subject;
            }
        }

        $thread->participants = array_values($participants->filter()->unique()->all());
        $thread->last_message_at = $lastMessageAt;
        $thread->save();
    }

    private function ensureValidGoogleToken(EmailAccount $account): ?string
    {
        // Minimal inline token ensure to avoid coupling with EmailService private methods
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
            Log::error('Token refresh failed in SyncGmailThread', [
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
    }

    private function headersToAssoc(array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            if (isset($h['name'])) {
                $out[strtolower($h['name'])] = $h['value'] ?? null;
            }
        }

        return $out;
    }

    private function parseAddress(string $value): array
    {
        // Very basic parser: "Name <email@example.com>" or just email
        $matches = [];
        if (preg_match('/^(.*)<([^>]+)>$/', $value, $matches)) {
            return [
                'name' => trim(trim($matches[1], '"')) ?: null,
                'email' => trim($matches[2]),
            ];
        }

        return [
            'name' => null,
            'email' => trim($value) ?: null,
        ];
    }

    private function parseAddresses(?string $value): array
    {
        if (! $value) {
            return [];
        }
        $parts = array_map('trim', explode(',', $value));

        return array_map(fn ($p) => $this->parseAddress($p), $parts);
    }

    private function extractBody(array $payload): array
    {
        $result = ['text' => null, 'html' => null];

        $walker = function ($part) use (&$walker, &$result) {
            $mimeType = $part['mimeType'] ?? '';
            if (! empty($part['body']['data'])) {
                $decoded = $this->base64UrlDecode($part['body']['data']);
                if ($mimeType === 'text/plain' && ! $result['text']) {
                    $result['text'] = $decoded;
                }
                if ($mimeType === 'text/html' && ! $result['html']) {
                    $result['html'] = $decoded;
                }
            }
            foreach ($part['parts'] ?? [] as $sub) {
                $walker($sub);
            }
        };

        $walker($payload);

        return $result;
    }

    private function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $padLen = 4 - (strlen($data) % 4);
        if ($padLen < 4) {
            $data .= str_repeat('=', $padLen);
        }

        return base64_decode($data) ?: '';
    }
}
