<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('previous_hash', 64)->nullable()->after('ip_address');
            $table->string('hash', 64)->nullable()->after('previous_hash');
        });

        // Backfills a real hash chain over whatever rows already exist, in
        // id order, so the table is a verifiable chain from this point
        // backward too — not just for rows created after this migration.
        $previousHash = null;

        DB::table('audit_logs')->orderBy('id')->each(function (object $log) use (&$previousHash) {
            $hash = hash('sha256', implode('|', [
                $previousHash ?? '',
                $log->user_id ?? '',
                $log->project_id ?? '',
                $log->team_id ?? '',
                $log->action,
                $log->metadata ?? '',
                $log->created_at ?? '',
            ]));

            DB::table('audit_logs')->where('id', $log->id)->update([
                'previous_hash' => $previousHash,
                'hash' => $hash,
            ]);

            $previousHash = $hash;
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['previous_hash', 'hash']);
        });
    }
};
