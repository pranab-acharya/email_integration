<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->onDelete('cascade');
            $table->string('provider'); // outlook, google
            $table->string('subscription_id')->nullable(); // Provider's subscription ID
            $table->string('resource'); // Resource being watched
            $table->json('change_types'); // ['created', 'updated', 'deleted']
            $table->string('notification_url');
            $table->timestamp('expires_at')->nullable();
            $table->string('client_state')->nullable(); // Security token
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider', 'subscription_id']);
            $table->index(['email_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_webhook_subscriptions');
    }
};
