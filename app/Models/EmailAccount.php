<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'email',
        'name',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all webhook subscriptions for this email account
     */
    public function emailWebhookSubscriptions(): HasMany
    {
        return $this->hasMany(EmailWebhookSubscription::class);
    }

    /**
     * Get active webhook subscriptions
     */
    public function activeWebhookSubscriptions(): HasMany
    {
        return $this->hasMany(EmailWebhookSubscription::class)
            ->where('is_active', true)
            ->where('expires_at', '>', now());
    }

    public function getHasActiveSubscriptionAttribute(): bool
    {
        return $this->activeWebhookSubscriptions()->exists();
    }

    /**
     * Get active Outlook webhook subscription
     */
    public function activeOutlookSubscription()
    {
        return $this->emailWebhookSubscriptions()
            ->where('provider', 'outlook')
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function getDecryptedAccessToken()
    {
        return decrypt($this->access_token);
    }

    public function getDecryptedRefreshToken()
    {
        return $this->refresh_token ? decrypt($this->refresh_token) : null;
    }

    public function isTokenExpired()
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class, 'email_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'email_account_id');
    }
}
