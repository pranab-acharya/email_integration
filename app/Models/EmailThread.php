<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends Model
{
    protected $fillable = [
        'email_account_id',
        'provider',
        'external_thread_id',
        'originated_via_app',
        'subject',
        'participants',
        'last_message_at',
    ];

    protected $casts = [
        'participants' => 'array',
        'originated_via_app' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'email_thread_id');
    }
}
