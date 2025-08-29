<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends Model
{
    protected $fillable = [
        'email_account_id',
        'email_thread_id',
        'provider',
        'external_message_id',
        'external_thread_id',
        'direction',
        'sent_via_app',
        'subject',
        'from_email',
        'from_name',
        'to',
        'cc',
        'bcc',
        'body_text',
        'body_html',
        'headers',
        'snippet',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'to' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
        'headers' => 'array',
        'sent_via_app' => 'boolean',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }
}
