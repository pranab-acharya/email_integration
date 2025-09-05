<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailWebhookSubscription extends Model
{
    protected $fillable = [
        'email_account_id',
        'provider',
        'subscription_id',
        'resource',
        'change_types',
        'notification_url',
        'expires_at',
        'client_state',
        'is_active',
    ];

    protected $casts = [
        'change_types' => 'array',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }
}
