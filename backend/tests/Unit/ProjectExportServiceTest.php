<?php

namespace Tests\Unit;

use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ProjectExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Export Test Project']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_the_export_zip_contains_every_expected_file(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $module = Module::where('project_id', $project->id)->where('module_type', 'idea')->firstOrFail();
        $module->items()->create(['item_type' => 'idea', 'content' => ['pitch' => 'A great idea.']]);

        $project->tasks()->create(['title' => 'Ship it', 'status' => 'todo']);
        $project->comments()->create([
            'user_id' => $user->id,
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'Looking good.',
        ]);
        $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Add dark mode', 'status' => 'draft']);
        $project->mayaMessages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Hello Maya']);
        $project->deployments()->create(['user_id' => $user->id, 'platform' => 'vercel', 'status' => 'success']);

        $zipPath = app(ProjectExportService::class)->build($project->fresh());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $this->assertNotFalse($zip->locateName('project.json'));
        $this->assertNotFalse($zip->locateName('modules/idea.json'));
        $this->assertNotFalse($zip->locateName('tasks.json'));
        $this->assertNotFalse($zip->locateName('comments.json'));
        $this->assertNotFalse($zip->locateName('feature_requests.json'));
        $this->assertNotFalse($zip->locateName('maya_conversations.json'));
        $this->assertNotFalse($zip->locateName('deployments.json'));
        $this->assertNotFalse($zip->locateName('README.txt'));

        $tasks = json_decode($zip->getFromName('tasks.json'), true);
        $this->assertSame('Ship it', $tasks[0]['title']);

        $ideaModule = json_decode($zip->getFromName('modules/idea.json'), true);
        $this->assertSame('A great idea.', $ideaModule[0]['content']['pitch']);

        $zip->close();
        unlink($zipPath);
    }
}
