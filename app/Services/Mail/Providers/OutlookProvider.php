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
            $token = decrypt($account->access_token);

            $response = Http::withToken($token)
                ->post('https://graph.microsoft.com/v1.0/me/sendMail', [
                    'message' => [
                        'subject' => $data['subject'] ?? '',
                        'body' => [
                            'contentType' => 'HTML',
                            'content' => $data['body'] ?? '',
                        ],
                        'toRecipients' => array_map(fn ($email) => [
                            'emailAddress' => ['address' => $email],
                        ], $data['to'] ?? []),
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('Outlook send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return SendResult::failed($this->key(), $response->status(), $response->body());
            }

            // Graph sendMail usually returns 202 with no content and no IDs.
            return SendResult::ok($this->key(), null, null, null, $response->status());
        } catch (Throwable $e) {
            Log::error('OutlookProvider::send exception: ' . $e->getMessage());

            return SendResult::failed($this->key(), null, $e->getMessage());
        }
    }
}
