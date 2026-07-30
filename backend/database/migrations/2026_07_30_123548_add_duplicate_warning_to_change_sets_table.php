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
        Schema::table('change_sets', function (Blueprint $table) {
            $table->json('duplicate_warning')->nullable()->after('confidence_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_sets', function (Blueprint $table) {
            $table->dropColumn('duplicate_warning');
        });
    }
};
