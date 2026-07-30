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
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('queued'); // queued|running|succeeded|failed|cancelled
            $table->json('payload');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
