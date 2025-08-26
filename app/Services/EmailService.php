<?php

namespace App\Services;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailService
{
    public function sendEmail(EmailAccount $account, array $data)
    {
        try {
            if ($account->provider === 'google') {
                return $this->sendGmail($account, $data);
            } else {
                return $this->sendOutlook($account, $data);
            }
        } catch (\Exception $e) {
            Log::error("Email send failed: " . $e->getMessage());
            return false;
        }
    }

    private function sendGmail(EmailAccount $account, array $data)
    {
        $token = decrypt($account->access_token);
        $message = $this->createGmailMessage($data, $account);

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $message,
            ]);

        if (! $response->successful()) {
            Log::error('Gmail send failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
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
                        'content' => $data['body']
                    ],
                    'toRecipients' => array_map(fn($email) => [
                        'emailAddress' => ['address' => $email]
                    ], $data['to'])
                ]
            ]);

        return $response->successful();
    }

    private function createGmailMessage(array $data, EmailAccount $account)
    {
        $headers = [
            "From: {$account->name} <{$account->email}>",
            "To: " . implode(', ', $data['to']),
            "Subject: {$data['subject']}",
            "Content-Type: text/html; charset=utf-8"
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $data['body'];
        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }
}
