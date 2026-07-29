<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->foreignId('to_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->string('kind');
            $table->timestamps();

            $table->unique(['from_file_id', 'to_file_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_dependencies');
    }
};
