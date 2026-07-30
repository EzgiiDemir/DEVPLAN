<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Nullable — some events (a failed login) happen before an
            // identity is confirmed, or the actor's account may later be
            // deleted while the historical record should remain.
            $table->foreignId('user_id')->nullable()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->nullOnDelete();
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
