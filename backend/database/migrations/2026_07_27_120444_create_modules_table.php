<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('module_type', [
                'idea', 'research', 'requirements', 'mvp_scope', 'design', 'tech_stack',
                'api_design', 'folder_structure', 'task_plan', 'environment',
                'prompt_engineering', 'ai_resources',
            ]);
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->unsignedTinyInteger('order_index');
            $table->timestamps();

            $table->unique(['project_id', 'module_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
