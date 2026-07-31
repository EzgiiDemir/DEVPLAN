<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Services\AccountExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class AccountExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_downloading_the_account_export_returns_a_real_zip(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api/v1/account/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('devplan-account-export.zip', $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'account.data_exported']);
    }

    public function test_the_export_contains_the_users_profile_teams_and_every_accessible_project(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);
        $project = $user->projects()->create(['team_id' => $team->id, 'title' => 'Data Portability Project']);
        $user->notify(new MentionNotification(
            $project->comments()->create([
                'user_id' => $user->id,
                'commentable_type' => 'project',
                'commentable_id' => $project->id,
                'body' => 'hello',
            ]),
            $user,
            $project,
        ));

        $zipPath = app(AccountExportService::class)->build($user->fresh());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $account = json_decode($zip->getFromName('account.json'), true);
        $this->assertSame('Ada Lovelace', $account['name']);

        $teams = json_decode($zip->getFromName('teams.json'), true);
        $this->assertSame('Acme Inc', $teams[0]['name']);
        $this->assertSame('owner', $teams[0]['role']);

        $notifications = json_decode($zip->getFromName('notifications.json'), true);
        $this->assertCount(1, $notifications);
        $this->assertSame('mention', $notifications[0]['type']);

        $expectedFolder = "projects/{$project->id}-data-portability-project/project.json";
        $this->assertNotFalse($zip->locateName($expectedFolder));
        $projectJson = json_decode($zip->getFromName($expectedFolder), true);
        $this->assertSame('Data Portability Project', $projectJson['title']);

        $zip->close();
        unlink($zipPath);
    }

    public function test_a_project_from_a_team_the_user_does_not_belong_to_is_not_included(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create(['name' => 'Not Mine', 'personal' => false]);
        TeamMember::create(['team_id' => $otherTeam->id, 'user_id' => $otherOwner->id, 'role' => 'owner']);
        $otherOwner->projects()->create(['team_id' => $otherTeam->id, 'title' => 'Should Not Appear']);

        $zipPath = app(AccountExportService::class)->build($user->fresh());

        $zip = new ZipArchive();
        $zip->open($zipPath);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('Should Not Appear', $zip->getNameIndex($i));
        }

        $zip->close();
        unlink($zipPath);
    }
}
