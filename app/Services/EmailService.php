<?php

namespace App\Services;

use App\Jobs\SyncGmailThread;
use App\Jobs\SyncOutlookThread;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Mail\DTOs\SendResult;
use App\Services\Mail\ProviderFactory;
use Exception;
use Illuminate\Support\Facades\Log;

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

            $this->persistSent($account, $data, $result);

            return true;
        } catch (Exception $e) {
            Log::error('Email send failed: ' . $e->getMessage());

            return false;
        }
    }

    private function persistSent(EmailAccount $account, array $data, SendResult $result): void
    {
        if ($result->provider === 'google' && $result->externalThreadId && $result->externalMessageId) {
            $this->persistGmailSent($account, $data, $result);
        } elseif ($result->provider === 'outlook' && $result->externalMessageId) {
            $this->persistOutlookSent($account, $data, $result);
        }
    }

    private function persistGmailSent(EmailAccount $account, array $data, SendResult $result): void
    {
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

    private function persistOutlookSent(EmailAccount $account, array $data, SendResult $result): void
    {
        $messageId = $result->externalMessageId;
        $conversationId = $result->externalThreadId; // This is conversationId from Graph

        $participants = array_values(array_unique(array_filter(array_merge([$account->email], $data['to'] ?? []))));

        // For Outlook, we use conversationId as the thread identifier
        $thread = EmailThread::firstOrCreate(
            [
                'email_account_id' => $account->id,
                'external_thread_id' => $conversationId,
            ],
            [
                'provider' => 'outlook',
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
                'provider' => 'outlook',
                'external_thread_id' => $conversationId,
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

        // Dispatch job to sync the conversation thread
        // SyncOutlookThread::dispatch($account->id, $conversationId);
    }
}
