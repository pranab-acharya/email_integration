<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('email_accounts')->onDelete('cascade');
            $table->string('provider')->index();
            $table->string('external_thread_id')->index(); // e.g., Gmail threadId
            $table->boolean('originated_via_app')->default(false);
            $table->string('subject')->nullable();
            $table->json('participants')->nullable(); // ["alice@example.com", ...]
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['email_account_id', 'external_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};
