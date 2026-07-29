<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_set_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('action');
            $table->text('reason')->nullable();
            $table->text('diff')->nullable();
            $table->longText('new_content')->nullable();
            $table->boolean('plan_approved')->default(false);
            $table->boolean('diff_approved')->default(false);
            $table->boolean('applied')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_set_files');
    }
};
