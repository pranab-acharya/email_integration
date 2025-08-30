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
use Throwable;

class SyncOutlookThread implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $accountId,
        public string $conversationId
    ) {}

    public function handle(): void
    {
        try {
            $account = EmailAccount::find($this->accountId);
            if (! $account) {
                Log::warning('SyncOutlookThread: Account not found', ['account_id' => $this->accountId]);

                return;
            }

            $token = $this->ensureValidToken($account);
            if (! $token) {
                Log::warning('SyncOutlookThread: Could not get valid token', ['account_id' => $this->accountId]);

                return;
            }

            $this->syncConversationMessages($account, $token);
        } catch (Throwable $e) {
            Log::error('SyncOutlookThread failed: ' . $e->getMessage(), [
                'account_id' => $this->accountId,
                'conversation_id' => $this->conversationId,
            ]);
            throw $e;
        }
    }

    private function ensureValidToken(EmailAccount $account): ?string
    {
        try {
            if (! $account->isExpired()) {
                return decrypt($account->access_token);
            }

            if (! $account->refresh_token) {
                return null;
            }

            if ($this->refreshOutlookToken($account)) {
                return decrypt($account->access_token);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('SyncOutlookThread token validation error: ' . $e->getMessage());

            return null;
        }
    }

    private function refreshOutlookToken(EmailAccount $account): bool
    {
        try {
            $refreshToken = decrypt($account->refresh_token);
        } catch (Throwable $e) {
            Log::error('SyncOutlookThread refresh token decrypt failed: ' . $e->getMessage());

            return false;
        }

        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.azure.client_id'),
            'client_secret' => config('services.azure.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Mail.Read offline_access',
        ]);

        if (! $response->successful()) {
            Log::error('SyncOutlookThread token refresh failed', [
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

        return true;
    }

    private function syncConversationMessages(EmailAccount $account, string $token): void
    {
        $inboxMessages = $this->getMessagesFromFolder($token, 'inbox');

        // Only keep incoming messages from this conversation
        $incomingMessages = collect($inboxMessages)
            ->filter(fn ($msg) => ($msg['conversationId'] ?? null) === $this->conversationId)
            ->filter(fn ($msg) => $this->determineDirection($msg, $account->email) === 'incoming')
            ->keyBy('id')
            ->sortByDesc('receivedDateTime')
            ->values()
            ->toArray();

        if (empty($incomingMessages)) {
            Log::info('SyncOutlookThread: No incoming messages found', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        // Ensure thread exists
        $thread = $this->ensureThreadExists($account, $incomingMessages[0]);

        // Process each incoming message
        foreach ($incomingMessages as $messageData) {
            $this->processIncomingMessage($account, $thread, $messageData);
        }

        // Update thread's last message timestamp
        $latestMessage = collect($incomingMessages)->sortByDesc('receivedDateTime')->first();
        if ($latestMessage) {
            $thread->last_message_at = Carbon::parse($latestMessage['receivedDateTime']);
            $thread->save();
        }

        Log::info('SyncOutlookThread completed', [
            'conversation_id' => $this->conversationId,
            'incoming_messages_processed' => count($incomingMessages),
        ]);
    }

    private function getMessagesFromFolder(string $token, string $folder): array
    {
        try {
            $twoWeeksAgo = Carbon::now()->subDays(14)->format('Y-m-d\TH:i:s\Z');

            $response = Http::withToken($token)
                ->get("https://graph.microsoft.com/v1.0/me/mailFolders/{$folder}/messages", [
                    '$filter' => "receivedDateTime ge {$twoWeeksAgo}",
                    '$orderby' => 'receivedDateTime desc',
                    '$top' => 200,
                    '$select' => 'id,conversationId,subject,receivedDateTime,from,toRecipients,ccRecipients,bccRecipients,body,internetMessageHeaders,isRead,isDraft',
                ]);

            if (! $response->successful()) {
                Log::warning("SyncOutlookThread: Failed to fetch from {$folder}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'folder' => $folder,
                ]);

                return [];
            }

            return $response->json('value') ?? [];
        } catch (Throwable $e) {
            Log::error("SyncOutlookThread: Exception fetching from {$folder}: " . $e->getMessage());

            return [];
        }
    }

    private function ensureThreadExists(EmailAccount $account, array $firstMessage): EmailThread
    {
        $participants = $this->extractParticipants($firstMessage, $account->email);

        return EmailThread::firstOrCreate(
            [
                'email_account_id' => $account->id,
                'external_thread_id' => $this->conversationId,
            ],
            [
                'provider' => 'outlook',
                'originated_via_app' => false,
                'subject' => $firstMessage['subject'] ?? null,
                'participants' => $participants,
                'last_message_at' => Carbon::parse($firstMessage['receivedDateTime']),
            ]
        );
    }

    private function processIncomingMessage(EmailAccount $account, EmailThread $thread, array $messageData): void
    {
        $receivedAt = $messageData['receivedDateTime']
            ? Carbon::parse($messageData['receivedDateTime'])
            : null;

        EmailMessage::updateOrCreate(
            [
                'email_account_id' => $account->id,
                'external_message_id' => $messageData['id'],
            ],
            [
                'email_thread_id' => $thread->id,
                'provider' => 'outlook',
                'external_thread_id' => $this->conversationId,
                'direction' => 'incoming',
                'sent_via_app' => false,
                'subject' => $messageData['subject'] ?? null,
                'from_email' => $messageData['from']['emailAddress']['address'] ?? null,
                'from_name' => $messageData['from']['emailAddress']['name'] ?? null,
                'to' => $this->extractEmailAddresses($messageData['toRecipients'] ?? []),
                'cc' => $this->extractEmailAddresses($messageData['ccRecipients'] ?? []),
                'bcc' => $this->extractEmailAddresses($messageData['bccRecipients'] ?? []),
                'body_text' => $messageData['body']['contentType'] === 'text' ? $messageData['body']['content'] : null,
                'body_html' => $messageData['body']['contentType'] === 'html' ? $messageData['body']['content'] : null,
                'headers' => $this->extractHeaders($messageData['internetMessageHeaders'] ?? []),
                'snippet' => $this->createSnippet($messageData['body']['content'] ?? ''),
                'received_at' => $receivedAt,
                'sent_at' => null,
                'is_read' => $messageData['isRead'] ?? false,
                'is_draft' => $messageData['isDraft'] ?? false,
            ]
        );
    }

    private function extractParticipants(array $messageData, string $accountEmail): array
    {
        $participants = [$accountEmail];

        if (! empty($messageData['from']['emailAddress']['address'])) {
            $participants[] = $messageData['from']['emailAddress']['address'];
        }

        foreach (['toRecipients', 'ccRecipients'] as $recipientType) {
            foreach ($messageData[$recipientType] ?? [] as $recipient) {
                if (! empty($recipient['emailAddress']['address'])) {
                    $participants[] = $recipient['emailAddress']['address'];
                }
            }
        }

        return array_values(array_unique(array_filter($participants)));
    }

    private function extractEmailAddresses(array $recipients): array
    {
        return array_map(
            fn ($recipient) => $recipient['emailAddress']['address'] ?? '',
            $recipients
        );
    }

    private function extractHeaders(array $internetHeaders): array
    {
        $headers = [];
        foreach ($internetHeaders as $header) {
            $headers[$header['name']] = $header['value'];
        }

        return $headers;
    }

    private function determineDirection(array $messageData, string $accountEmail): string
    {
        $fromEmail = $messageData['from']['emailAddress']['address'] ?? '';

        return $fromEmail === $accountEmail ? 'outgoing' : 'incoming';
    }

    private function createSnippet(string $content): string
    {
        $text = strip_tags($content);

        return strlen($text) > 150 ? substr($text, 0, 150) . '...' : $text;
    }
}
