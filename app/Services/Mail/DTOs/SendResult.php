<?php

namespace App\Services\Mail\DTOs;

class SendResult
{
    public function __construct(
        public bool $success,
        public ?string $provider = null,
        public ?string $externalMessageId = null,
        public ?string $externalThreadId = null,
        public ?int $httpStatus = null,
        public ?array $headers = null,
        public ?string $error = null,
    ) {}

    public static function ok(string $provider, ?string $messageId, ?string $threadId, ?array $headers = null, ?int $status = 200): self
    {
        return new self(true, $provider, $messageId, $threadId, $status, $headers);
    }

    public static function failed(string $provider, ?int $status = null, ?string $error = null): self
    {
        return new self(false, $provider, null, null, $status, null, $error);
    }
}
