<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('email_accounts')->onDelete('cascade');
            $table->unsignedBigInteger('email_thread_id');
            $table->index('email_thread_id');
            $table->string('provider')->index();
            $table->string('external_message_id')->index(); // e.g., Gmail message id
            $table->string('external_thread_id')->index(); // duplicate for quick filtering
            $table->enum('direction', ['outgoing', 'incoming']);
            $table->boolean('sent_via_app')->default(false);
            $table->string('subject')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->text('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('headers')->nullable();
            $table->text('snippet')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['email_account_id', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
