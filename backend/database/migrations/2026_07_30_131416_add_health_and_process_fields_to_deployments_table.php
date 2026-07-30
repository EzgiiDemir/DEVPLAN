<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('companion_process_id')->nullable()->after('live_url');
            $table->string('health_status')->nullable()->after('companion_process_id'); // unknown|healthy|unhealthy
            $table->timestamp('last_health_checked_at')->nullable()->after('health_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['companion_process_id', 'health_status', 'last_health_checked_at']);
        });
    }
};
