<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // Every existing project was single-owner; give each of those owners
        // a personal team (if they don't already have one) and backfill
        // their projects onto it, as that owner. This must leave every
        // pre-existing project's effective access unchanged: the same user
        // who could reach it before still can, at 'owner' level.
        $ownerIds = DB::table('projects')->whereNull('team_id')->distinct()->pluck('user_id');

        foreach ($ownerIds as $userId) {
            $user = DB::table('users')->find($userId);
            if (! $user) {
                continue;
            }

            $teamId = DB::table('team_members')
                ->join('teams', 'teams.id', '=', 'team_members.team_id')
                ->where('team_members.user_id', $userId)
                ->where('teams.personal', true)
                ->value('teams.id');

            if (! $teamId) {
                $teamId = DB::table('teams')->insertGetId([
                    'name' => $user->name."'s Team",
                    'personal' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('team_members')->insert([
                    'team_id' => $teamId,
                    'user_id' => $userId,
                    'role' => 'owner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('projects')->where('user_id', $userId)->whereNull('team_id')->update(['team_id' => $teamId]);
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
