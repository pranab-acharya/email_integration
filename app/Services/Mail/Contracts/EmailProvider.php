<?php

namespace App\Services\Mail\Contracts;

use App\Models\EmailAccount;
use App\Services\Mail\DTOs\SendResult;

interface EmailProvider
{
    /**
     * Send an email via the provider.
     *
     * Expected $data keys:
     * - to: string[]
     * - subject: string
     * - body: string (HTML)
     * - cc?: string[]
     * - bcc?: string[]
     */
    public function send(EmailAccount $account, array $data): SendResult;

    /** Provider key, e.g., 'google', 'outlook' */
    public function key(): string;
}
