<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Swaps only the HTTP round-trip GitHub itself would normally do —
     * Socialite ships this exact fake-user pattern for testing without a
     * real browser redirect (no real GITHUB_CLIENT_ID/SECRET exist in this
     * environment, so a real round-trip can't be exercised here regardless;
     * everything downstream of "Socialite resolved this user" is real).
     */
    private function fakeGithubUser(array $attributes): void
    {
        $fakeUser = SocialiteUser::fake($attributes);

        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($fakeUser);

        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);
    }

    public function test_redirect_reports_unconfigured_when_no_real_github_credentials_exist(): void
    {
        config(['services.github.client_id' => null]);

        $this->getJson('/api/v1/oauth/github/redirect')
            ->assertStatus(503)
            ->assertJsonPath('configured', false);
    }

    public function test_redirect_returns_a_real_url_when_configured(): void
    {
        config(['services.github.client_id' => 'test-client-id', 'services.github.client_secret' => 'test-secret']);

        $this->getJson('/api/v1/oauth/github/redirect')->assertOk()->assertJsonStructure(['url']);
    }

    public function test_first_time_github_login_creates_a_real_user_and_personal_team(): void
    {
        $this->fakeGithubUser(['id' => '111', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'nickname' => 'ada']);

        $response = $this->get('/api/v1/oauth/github/callback');

        $response->assertRedirect();
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame('github', $user->oauth_provider);
        $this->assertSame('111', $user->oauth_id);
        $this->assertTrue($user->teams()->where('personal', true)->exists());
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
    }

    public function test_a_second_login_with_the_same_provider_id_reuses_the_same_user(): void
    {
        $this->fakeGithubUser(['id' => '222', 'name' => 'Ada Lovelace', 'email' => 'ada2@example.com']);
        $this->get('/api/v1/oauth/github/callback');
        $firstCount = User::count();

        $this->fakeGithubUser(['id' => '222', 'name' => 'Ada Lovelace', 'email' => 'ada2@example.com']);
        $this->get('/api/v1/oauth/github/callback');

        $this->assertSame($firstCount, User::count());
    }

    public function test_a_github_email_matching_an_existing_password_account_links_instead_of_duplicating(): void
    {
        $existing = User::factory()->create(['email' => 'linked@example.com']);

        $this->fakeGithubUser(['id' => '333', 'name' => 'Someone', 'email' => 'linked@example.com']);
        $this->get('/api/v1/oauth/github/callback');

        $this->assertSame(1, User::where('email', 'linked@example.com')->count());
        $this->assertSame('github', $existing->fresh()->oauth_provider);
        $this->assertSame('333', $existing->fresh()->oauth_id);
    }
}
