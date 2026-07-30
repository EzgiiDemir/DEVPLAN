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
        Schema::table('change_set_files', function (Blueprint $table) {
            $table->string('base_content_hash', 64)->nullable()->after('security_findings');
            $table->json('conflict_warning')->nullable()->after('base_content_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_set_files', function (Blueprint $table) {
            $table->dropColumn(['base_content_hash', 'conflict_warning']);
        });
    }
};
