<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            // Denormalized alongside the polymorphic pair so the whole
            // thread for a project (across Project- and FeatureRequest-
            // scoped comments alike) can be queried and cascade-deleted
            // without joining through the commentable target.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
