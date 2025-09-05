<?php

namespace App\Jobs;

use App\Filament\Resources\EmailThreadResource;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Mail\Providers\OutlookProvider;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessOutlookWebhookEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(protected array $notification, protected EmailAccount $emailAccount) {}

    public function handle(): void
    {
        try {
            // 2. Extract message details
            $messageId = $this->notification['resourceData']['id'] ?? null;
            $userId = $this->extractUserIdFromResource($this->notification['resource'] ?? '');

            if (! $messageId || ! $userId) {
                Log::error('Missing messageId or userId from notification', $this->notification);

                return;
            }

            // 3. Email Account
            $emailAccount = $this->emailAccount;
            if (! $emailAccount) {
                Log::error("Email account not found for user: {$userId}");

                return;
            }

            // 4. Get fresh access token
            $accessToken = $this->getValidToken($emailAccount);
            if (! $accessToken) {
                Log::error("Could not get valid token for: {$emailAccount->email}");

                return;
            }

            // 5. Fetch email from Microsoft Graph
            $emailData = $this->fetchEmail($messageId, $accessToken);
            if (! $emailData) {
                Log::error("Could not fetch email: {$messageId}");

                return;
            }

            // 6. Process the email with filtering logic
            $result = $this->processEmail($emailData, $emailAccount);

            if ($result) {
                Log::info('Email processed and stored', [
                    'message_id' => $result->id,
                    'thread_id' => $result->email_thread_id,
                    'sent_via_app' => $result->sent_via_app,
                ]);

                $emailAccount->load('user');
                $notification = Notification::make()
                    ->title('New email received')
                    ->body('You have a new email in your inbox.')
                    ->actions([
                        Action::make('View Email')
                            ->url(EmailThreadResource::getUrl('view', ['record' => $result->email_thread_id]))
                            ->markAsRead()
                            ->button(),
                    ])
                    ->success();
                $user = $emailAccount->user;
                if ($user) {
                    $notification->sendToDatabase($user);
                    $notification
                        ->persistent()
                        ->broadcast($user);
                }
            } else {
                Log::info('Email ignored - not a reply to app-originated conversation', [
                    'email_id' => $emailData['id'],
                    'conversation_id' => $emailData['conversationId'] ?? null,
                ]);
            }

        } catch (Exception $e) {
            Log::error('Webhook processing failed: ' . $e->getMessage(), [
                'notification' => $this->notification,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function processEmail(array $emailData, EmailAccount $emailAccount): ?EmailMessage
    {
        $messageId = $emailData['internetMessageId'] ?? $emailData['id'];
        $conversationId = $emailData['conversationId'] ?? null;

        if (! $conversationId) {
            Log::warning('No conversationId found in email data');

            return null;
        }

        // Check if we already have this message (from persistOutlookSent)
        $existingMessage = EmailMessage::where('external_message_id', $messageId)
            ->where('email_account_id', $emailAccount->id)
            ->first();

        if ($existingMessage) {
            // Message already exists (probably from persistOutlookSent)
            // Just update it with webhook data and return
            return $this->updateExistingMessage($existingMessage, $emailData);
        }

        // Check if this is a reply to a thread that originated from your app
        $existingThread = EmailThread::where('external_thread_id', $conversationId)
            ->where('email_account_id', $emailAccount->id)
            ->where('originated_via_app', true) // Only threads started by your app
            ->first();

        if (! $existingThread) {
            // This is either:
            // 1. A new email from someone (not replying to your app) - IGNORE
            // 2. A reply to a thread not started by your app - IGNORE
            return null;
        }

        // This is a reply to a conversation your app started - store it
        $sentViaApp = $this->checkIfSentViaApp($emailData['internetMessageHeaders'] ?? []);

        // Create the reply message in the existing thread
        $message = $this->createEmailMessage($emailData, $existingThread, $emailAccount, $sentViaApp);

        // Update thread's last message time and participants
        $existingThread->update([
            'last_message_at' => Carbon::parse($emailData['receivedDateTime']),
            'participants' => $this->mergeParticipants($existingThread->participants, $emailData),
        ]);

        return $message;
    }

    private function updateExistingMessage(EmailMessage $message, array $emailData): EmailMessage
    {
        // Update the existing message with webhook data (like received_at, headers, etc.)
        $message->update([
            'received_at' => Carbon::parse($emailData['receivedDateTime']),
            'headers' => $emailData['internetMessageHeaders'] ?? $message->headers,
            'snippet' => $emailData['bodyPreview'] ?? $message->snippet,
            // Update body if it was null in persistOutlookSent
            'body_text' => $message->body_text ?? strip_tags($emailData['body']['content'] ?? ''),
            'body_html' => $message->body_html ?? ($emailData['body']['content'] ?? ''),
        ]);

        // Update thread's last message time
        if ($message->thread) {
            $message->thread->update([
                'last_message_at' => Carbon::parse($emailData['receivedDateTime']),
            ]);
        }

        return $message;
    }

    private function checkIfSentViaApp(array $headers): bool
    {
        foreach ($headers as $header) {
            if (isset($header['name']) && strtolower($header['name']) === 'x-app-sent') {
                return $header['value'] === '1';
            }
        }

        return false;
    }

    private function createEmailMessage(array $emailData, EmailThread $thread, EmailAccount $emailAccount, bool $sentViaApp): EmailMessage
    {
        // Extract recipients
        $toRecipients = [];
        foreach ($emailData['toRecipients'] ?? [] as $recipient) {
            $toRecipients[] = [
                'name' => $recipient['emailAddress']['name'] ?? '',
                'email' => $recipient['emailAddress']['address'] ?? '',
            ];
        }

        return EmailMessage::create([
            'email_account_id' => $emailAccount->id,
            'email_thread_id' => $thread->id,
            'provider' => 'outlook',
            'external_message_id' => $emailData['internetMessageId'] ?? $emailData['id'],
            'external_thread_id' => $emailData['conversationId'],
            'direction' => $sentViaApp ? 'outgoing' : 'incoming',
            'sent_via_app' => $sentViaApp,
            'subject' => $emailData['subject'] ?? '',
            'from_email' => $emailData['from']['emailAddress']['address'] ?? '',
            'from_name' => $emailData['from']['emailAddress']['name'] ?? '',
            'to' => $toRecipients,
            'cc' => $this->extractCcRecipients($emailData),
            'bcc' => [], // BCC not typically available in webhooks
            'body_text' => strip_tags($emailData['body']['content'] ?? ''),
            'body_html' => $emailData['body']['content'] ?? '',
            'headers' => $emailData['internetMessageHeaders'] ?? [],
            'snippet' => $emailData['bodyPreview'] ?? '',
            'sent_at' => Carbon::parse($emailData['sentDateTime']),
            'received_at' => Carbon::parse($emailData['receivedDateTime']),
        ]);
    }

    private function extractCcRecipients(array $emailData): array
    {
        $ccRecipients = [];
        foreach ($emailData['ccRecipients'] ?? [] as $recipient) {
            $ccRecipients[] = [
                'name' => $recipient['emailAddress']['name'] ?? '',
                'email' => $recipient['emailAddress']['address'] ?? '',
            ];
        }

        return $ccRecipients;
    }

    private function mergeParticipants(array $existingParticipants, array $emailData): array
    {
        $newParticipants = [];

        // Add sender
        if (isset($emailData['from']['emailAddress'])) {
            $newParticipants[] = [
                'name' => $emailData['from']['emailAddress']['name'] ?? '',
                'email' => $emailData['from']['emailAddress']['address'],
            ];
        }

        // Add recipients
        foreach ($emailData['toRecipients'] ?? [] as $recipient) {
            $newParticipants[] = [
                'name' => $recipient['emailAddress']['name'] ?? '',
                'email' => $recipient['emailAddress']['address'],
            ];
        }

        // Add CC recipients
        foreach ($emailData['ccRecipients'] ?? [] as $recipient) {
            $newParticipants[] = [
                'name' => $recipient['emailAddress']['name'] ?? '',
                'email' => $recipient['emailAddress']['address'],
            ];
        }

        // Merge existing and new participants, remove duplicates
        $allParticipants = array_merge($existingParticipants, $newParticipants);

        return collect($allParticipants)
            ->unique('email')
            ->values()
            ->toArray();
    }

    private function isValidNotification(): bool
    {
        // Check if it's a 'created' notification for emails
        if (($this->notification['changeType'] ?? '') !== 'created') {
            return false;
        }

        // Validate client state if configured
        $expectedClientState = config('services.azure.client_state');
        if ($expectedClientState && ($this->notification['clientState'] ?? null) !== $expectedClientState) {
            return false;
        }

        return true;
    }

    private function extractUserIdFromResource(string $resource): ?string
    {
        // Resource format: "Users/{userId}/Messages/{messageId}"
        if (preg_match('/Users\/([^\/]+)\/Messages/', $resource, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getValidToken(EmailAccount $emailAccount): ?string
    {
        // Try current token first
        return (new OutlookProvider)->ensureValidOutlookToken($emailAccount);
    }

    private function refreshToken(EmailAccount $emailAccount): ?string
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.azure.client_id'),
            'client_secret' => config('services.azure.client_secret'),
            'refresh_token' => $emailAccount->refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Mail.Read',
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        // Update stored token
        $emailAccount->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $emailAccount->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    private function fetchEmail(string $messageId, string $accessToken): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Accept' => 'application/json',
        ])->get("https://graph.microsoft.com/v1.0/me/messages/{$messageId}", [
            '$select' => 'id,subject,from,toRecipients,ccRecipients,conversationId,conversationIndex,internetMessageId,bodyPreview,body,receivedDateTime,sentDateTime,internetMessageHeaders',
        ]);

        if (! $response->successful()) {
            Log::error('Graph API error: ' . $response->body());

            return null;
        }

        return $response->json();
    }
}
