<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example.com';
    private const CLIENT_ID = 'test-client-id';

    /**
     * openssl_pkey_new() (used below to generate a real RSA keypair) needs
     * OPENSSL_CONF to point at a real openssl.cnf. Some Windows PHP builds —
     * this WinGet-packaged one included — ship one but don't point
     * OPENSSL_CONF at it, and OpenSSL reads that variable at process start,
     * not per-call — so it can't be fixed from inside the test itself via
     * putenv(). If these tests fail with "openssl_pkey_export(): Cannot get
     * key from parameter 1", set it before invoking PHP, e.g.:
     * OPENSSL_CONF="<php-install-dir>/extras/ssl/openssl.cnf" php artisan test
     */

    /**
     * A real RSA keypair and a real signature, not a fixture — the whole
     * point of resolveClaims() is verifying a genuine cryptographic
     * signature against the IdP's published keys, so the test needs one to
     * verify. Only the network boundary (discovery/token/jwks endpoints) is
     * faked; everything downstream of that fake HTTP response is real.
     */
    private function generateKeyPairAndJwks(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);

        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key-1',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ]],
        ];

        return [$privateKeyPem, $jwks];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function fakeIdp(string $privateKeyPem, array $jwks): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => Http::response($jwks),
            self::ISSUER.'/token' => Http::response([
                'id_token' => JWT::encode($this->idTokenPayload(), $privateKeyPem, 'RS256', 'test-key-1'),
            ]),
        ]);
    }

    private array $claimOverrides = [];

    private function idTokenPayload(): array
    {
        return array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'idp-user-1',
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'iat' => time(),
            'exp' => time() + 300,
        ], $this->claimOverrides);
    }

    private function configureOidc(): void
    {
        config([
            'services.oidc.client_id' => self::CLIENT_ID,
            'services.oidc.client_secret' => 'test-client-secret',
            'services.oidc.issuer' => self::ISSUER,
            'services.oidc.redirect' => 'http://localhost:8010/api/v1/oauth/oidc/callback',
        ]);
    }

    public function test_redirect_reports_unconfigured_when_no_real_idp_credentials_exist(): void
    {
        config(['services.oidc.client_id' => null]);

        $this->getJson('/api/v1/oauth/oidc/redirect')
            ->assertStatus(503)
            ->assertJsonPath('configured', false);
    }

    public function test_redirect_returns_a_real_authorization_url_when_configured(): void
    {
        $this->configureOidc();
        [, $jwks] = $this->generateKeyPairAndJwks();
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
        ]);

        $response = $this->getJson('/api/v1/oauth/oidc/redirect');

        $response->assertOk()->assertJsonStructure(['url']);
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $response->json('url'));
        $this->assertStringContainsString('client_id='.self::CLIENT_ID, $response->json('url'));
    }

    public function test_a_full_login_creates_a_real_user_from_a_genuinely_verified_id_token(): void
    {
        $this->configureOidc();
        [$privateKeyPem, $jwks] = $this->generateKeyPairAndJwks();
        $this->fakeIdp($privateKeyPem, $jwks);

        $state = $this->getJson('/api/v1/oauth/oidc/redirect')->json();
        $authUrl = $state['url'];
        parse_str(parse_url($authUrl, PHP_URL_QUERY), $params);

        $response = $this->get("/api/v1/oauth/oidc/callback?code=real-code&state={$params['state']}");

        $response->assertRedirect();
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame('oidc', $user->oauth_provider);
        $this->assertSame('idp-user-1', $user->oauth_id);
        $this->assertTrue($user->teams()->where('personal', true)->exists());
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
    }

    public function test_a_mismatched_state_is_rejected_without_logging_anyone_in(): void
    {
        $this->configureOidc();
        [$privateKeyPem, $jwks] = $this->generateKeyPairAndJwks();
        $this->fakeIdp($privateKeyPem, $jwks);

        $this->getJson('/api/v1/oauth/oidc/redirect');

        $response = $this->get('/api/v1/oauth/oidc/callback?code=real-code&state=forged-state');

        $response->assertRedirect();
        $this->assertStringContainsString('sso_error=state_mismatch', $response->headers->get('Location'));
        $this->assertSame(0, User::count());
    }

    public function test_a_token_signed_by_a_different_key_than_the_idps_published_jwks_is_rejected(): void
    {
        $this->configureOidc();
        [, $realJwks] = $this->generateKeyPairAndJwks();
        [$attackerPrivateKeyPem] = $this->generateKeyPairAndJwks();

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => Http::response($realJwks),
            self::ISSUER.'/token' => Http::response([
                'id_token' => JWT::encode($this->idTokenPayload(), $attackerPrivateKeyPem, 'RS256', 'test-key-1'),
            ]),
        ]);

        $state = $this->getJson('/api/v1/oauth/oidc/redirect')->json();
        parse_str(parse_url($state['url'], PHP_URL_QUERY), $params);

        $response = $this->get("/api/v1/oauth/oidc/callback?code=real-code&state={$params['state']}");

        $response->assertRedirect();
        $this->assertStringContainsString('sso_error=identity_provider', $response->headers->get('Location'));
        $this->assertSame(0, User::count());
    }

    public function test_a_token_issued_for_a_different_audience_is_rejected(): void
    {
        $this->configureOidc();
        [$privateKeyPem, $jwks] = $this->generateKeyPairAndJwks();
        $this->claimOverrides = ['aud' => 'someone-elses-client-id'];
        $this->fakeIdp($privateKeyPem, $jwks);

        $state = $this->getJson('/api/v1/oauth/oidc/redirect')->json();
        parse_str(parse_url($state['url'], PHP_URL_QUERY), $params);

        $response = $this->get("/api/v1/oauth/oidc/callback?code=real-code&state={$params['state']}");

        $response->assertRedirect();
        $this->assertStringContainsString('sso_error=identity_provider', $response->headers->get('Location'));
        $this->assertSame(0, User::count());
    }

    public function test_an_sso_email_matching_an_existing_password_account_links_instead_of_duplicating(): void
    {
        $existing = User::factory()->create(['email' => 'ada@example.com']);

        $this->configureOidc();
        [$privateKeyPem, $jwks] = $this->generateKeyPairAndJwks();
        $this->fakeIdp($privateKeyPem, $jwks);

        $state = $this->getJson('/api/v1/oauth/oidc/redirect')->json();
        parse_str(parse_url($state['url'], PHP_URL_QUERY), $params);

        $this->get("/api/v1/oauth/oidc/callback?code=real-code&state={$params['state']}");

        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
        $this->assertSame('oidc', $existing->fresh()->oauth_provider);
        $this->assertSame('idp-user-1', $existing->fresh()->oauth_id);
    }

    public function test_a_second_login_with_the_same_subject_reuses_the_same_user(): void
    {
        $this->configureOidc();
        [$privateKeyPem, $jwks] = $this->generateKeyPairAndJwks();
        $this->fakeIdp($privateKeyPem, $jwks);

        $state = $this->getJson('/api/v1/oauth/oidc/redirect')->json();
        parse_str(parse_url($state['url'], PHP_URL_QUERY), $params);
        $this->get("/api/v1/oauth/oidc/callback?code=real-code&state={$params['state']}");
        $firstCount = User::count();

        $this->fakeIdp($privateKeyPem, $jwks);
        $state2 = $this->getJson('/api/v1/oauth/oidc/redirect')->json();
        parse_str(parse_url($state2['url'], PHP_URL_QUERY), $params2);
        $this->get("/api/v1/oauth/oidc/callback?code=real-code&state={$params2['state']}");

        $this->assertSame($firstCount, User::count());
    }
}
