<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestMicrosoftId extends Command
{
    protected $signature = 'outlook:test-id {email}';
    protected $description = 'Test Microsoft Graph API user ID';

    public function handle()
    {
        $email = $this->argument('email');

        $account = EmailAccount::where('email', $email)
            ->where('provider', 'outlook')
            ->first();

        if (! $account) {
            $this->error("No account found for email: {$email}");

            return 1;
        }

        $this->info("Testing account: {$account->email}");
        $this->info("Current microsoft_user_id: {$account->microsoft_user_id}");

        try {
            $token = decrypt($account->access_token);

            $response = Http::withToken($token)
                ->get('https://graph.microsoft.com/v1.0/me');

            $this->info('Graph API Response:');
            $this->info(json_encode($response->json(), JSON_PRETTY_PRINT));

            if ($response->successful()) {
                $userId = $response->json()['id'];
                $this->info("\nCorrect Microsoft user ID should be: {$userId}");

                if ($this->confirm('Do you want to update the stored user ID?')) {
                    $account->update(['microsoft_user_id' => $userId]);
                    $this->info('User ID updated successfully.');
                }
            } else {
                $this->error('API Error: ' . $response->body());
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return 1;
        }

        return 0;
    }
}
