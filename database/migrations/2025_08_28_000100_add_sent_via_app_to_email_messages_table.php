<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_messages')) {
            Schema::table('email_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('email_messages', 'sent_via_app')) {
                    $table->boolean('sent_via_app')->default(false)->after('direction');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_messages')) {
            Schema::table('email_messages', function (Blueprint $table) {
                if (Schema::hasColumn('email_messages', 'sent_via_app')) {
                    $table->dropColumn('sent_via_app');
                }
            });
        }
    }
};
