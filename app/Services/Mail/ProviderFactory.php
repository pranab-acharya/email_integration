<?php

namespace App\Services\Mail;

use App\Services\Mail\Contracts\EmailProvider;
use App\Services\Mail\Providers\GmailProvider;
use App\Services\Mail\Providers\OutlookProvider;
use InvalidArgumentException;

class ProviderFactory
{
    public static function make(string $key): EmailProvider
    {
        return match ($key) {
            'google' => new GmailProvider,
            'outlook', 'microsoft' => new OutlookProvider,
            default => throw new InvalidArgumentException("Unsupported email provider: {$key}"),
        };
    }
}
