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
