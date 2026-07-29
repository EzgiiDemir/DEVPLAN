<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodebaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Codebase Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_diff_reports_new_and_unchanged_files_and_prunes_deleted_ones(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $project->files()->create([
            'path' => 'src/existing.js',
            'language' => 'javascript',
            'content_hash' => 'abc123',
        ]);
        $project->files()->create([
            'path' => 'src/removed.js',
            'language' => 'javascript',
            'content_hash' => 'def456',
        ]);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/codebase/diff", [
            'files' => [
                ['path' => 'src/existing.js', 'hash' => 'abc123'],
                ['path' => 'src/new.js', 'hash' => 'newhash'],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'needsContent' => ['src/new.js'],
                'deleted' => ['src/removed.js'],
                'unchanged' => 1,
            ]);

        $this->assertDatabaseMissing('project_files', ['path' => 'src/removed.js']);
        $this->assertDatabaseHas('project_files', ['path' => 'src/existing.js']);
    }

    public function test_index_summarizes_files_via_ai_and_resolves_import_dependencies(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'src/lib/helper.js' => ['summary' => 'Exports a helper function.', 'symbols' => ['helper']],
                'src/components/Widget.jsx' => ['summary' => 'Renders a widget using the helper.', 'symbols' => ['Widget']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/codebase/index", [
            'files' => [
                [
                    'path' => 'src/lib/helper.js',
                    'hash' => 'hash-helper',
                    'content' => 'export function helper() { return 42; }',
                ],
                [
                    'path' => 'src/components/Widget.jsx',
                    'hash' => 'hash-widget',
                    'content' => "import { helper } from '@/lib/helper';\nexport function Widget() { return helper(); }",
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('project_files', [
            'project_id' => $project->id,
            'path' => 'src/lib/helper.js',
            'summary' => 'Exports a helper function.',
        ]);

        $widget = $project->files()->where('path', 'src/components/Widget.jsx')->firstOrFail();
        $helper = $project->files()->where('path', 'src/lib/helper.js')->firstOrFail();

        $this->assertDatabaseHas('file_dependencies', [
            'project_id' => $project->id,
            'from_file_id' => $widget->id,
            'to_file_id' => $helper->id,
            'kind' => 'import',
        ]);
    }

    public function test_index_flags_unresolved_internal_imports_but_not_external_packages(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'src/components/Broken.jsx' => ['summary' => 'A component with a broken import.', 'symbols' => ['Broken']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->actingAs($user)->postJson("/api/projects/{$project->id}/codebase/index", [
            'files' => [
                [
                    'path' => 'src/components/Broken.jsx',
                    'hash' => 'hash-broken',
                    'content' => "import React from 'react';\n"
                        ."import { helper } from '@/lib/does-not-exist';\n"
                        ."import { other } from './also-missing';\n"
                        ."export function Broken() { return helper() + other(); }",
                ],
            ],
        ])->assertOk();

        $file = $project->files()->where('path', 'src/components/Broken.jsx')->firstOrFail();

        // Internal-looking imports that couldn't be resolved are flagged...
        $this->assertContains('@/lib/does-not-exist', $file->unresolved_imports);
        $this->assertContains('./also-missing', $file->unresolved_imports);
        // ...but a real external package ("react") is never flagged as unresolved.
        $this->assertNotContains('react', $file->unresolved_imports);
        $this->assertCount(2, $file->unresolved_imports);
    }

    public function test_files_endpoint_exposes_unresolved_imports(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'src/Bad.js',
            'language' => 'javascript',
            'content_hash' => 'x',
            'unresolved_imports' => ['./missing'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}/codebase/files");

        $response->assertOk();
        $file = collect($response->json())->firstWhere('path', 'src/Bad.js');
        $this->assertSame(['./missing'], $file['unresolved_imports']);
    }

    public function test_status_and_files_endpoints_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/codebase/status")
            ->assertForbidden();

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/codebase/files")
            ->assertForbidden();
    }
}
