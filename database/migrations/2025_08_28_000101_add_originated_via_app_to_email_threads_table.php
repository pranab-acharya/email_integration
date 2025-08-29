<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_threads')) {
            Schema::table('email_threads', function (Blueprint $table) {
                if (! Schema::hasColumn('email_threads', 'originated_via_app')) {
                    $table->boolean('originated_via_app')->default(false)->after('external_thread_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_threads')) {
            Schema::table('email_threads', function (Blueprint $table) {
                if (Schema::hasColumn('email_threads', 'originated_via_app')) {
                    $table->dropColumn('originated_via_app');
                }
            });
        }
    }
};
