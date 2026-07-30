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
            $table->string('confidence_level')->nullable()->after('summary'); // high|medium|low
            $table->float('confidence_score')->nullable()->after('confidence_level'); // 0..1, null when no signal was available
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_sets', function (Blueprint $table) {
            $table->dropColumn(['confidence_level', 'confidence_score']);
        });
    }
};
