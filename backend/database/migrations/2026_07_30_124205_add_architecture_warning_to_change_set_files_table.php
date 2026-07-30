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
            $table->text('architecture_warning')->nullable()->after('new_content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_set_files', function (Blueprint $table) {
            $table->dropColumn('architecture_warning');
        });
    }
};
