<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // password stays NOT NULL — OAuth-only accounts get a random,
            // never-surfaced, unusable hash instead (Hash::make(Str::random()))
            // rather than a nullable column. Avoids a ->change() migration,
            // which needs doctrine/dbal (not installed) and isn't portable to
            // SQLite (used by the test suite) without a full table rebuild.
            $table->string('oauth_provider')->nullable()->after('password');
            $table->string('oauth_id')->nullable()->after('oauth_provider');
            $table->text('two_factor_secret')->nullable()->after('oauth_id');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->unique(['oauth_provider', 'oauth_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oauth_provider', 'oauth_id']);
            $table->dropColumn(['oauth_provider', 'oauth_id', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
