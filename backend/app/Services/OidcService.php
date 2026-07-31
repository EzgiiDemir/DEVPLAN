<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Generic OpenID Connect Authorization Code flow against a single,
 * globally-configured enterprise IdP (Okta, Azure AD/Entra ID, Auth0, or any
 * standards-compliant provider) — the SSO counterpart to OAuthController's
 * GitHub login. Hand-rolled against the IdP's published discovery document
 * and JWKS rather than a per-vendor driver, so swapping IdPs is an env var
 * change, not a new integration. The ID token's signature is always verified
 * against the IdP's own published keys — an identity claim is never trusted
 * unverified.
 */
class OidcService
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.oidc.client_id')
            && config('services.oidc.client_secret')
            && config('services.oidc.issuer'));
    }

    public function authorizationUrl(string $state): string
    {
        $discovery = $this->discovery();

        if (empty($discovery['authorization_endpoint'])) {
            throw new RuntimeException('The identity provider did not publish an authorization endpoint.');
        }

        return $discovery['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.oidc.client_id'),
            'redirect_uri' => config('services.oidc.redirect'),
            'scope' => 'openid email profile',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the authorization code for tokens and verifies the ID
     * token's signature before trusting any of its claims.
     *
     * @return array{sub: string, email: ?string, name: ?string}
     */
    public function resolveClaims(string $code): array
    {
        $discovery = $this->discovery();

        if (empty($discovery['token_endpoint']) || empty($discovery['jwks_uri'])) {
            throw new RuntimeException('The identity provider did not publish a token endpoint or JWKS.');
        }

        $tokenResponse = Http::asForm()->post($discovery['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.oidc.redirect'),
            'client_id' => config('services.oidc.client_id'),
            'client_secret' => config('services.oidc.client_secret'),
        ]);

        if (! $tokenResponse->successful() || ! $tokenResponse->json('id_token')) {
            throw new RuntimeException('The identity provider rejected the login attempt.');
        }

        $jwksResponse = Http::get($discovery['jwks_uri']);

        if (! $jwksResponse->successful()) {
            throw new RuntimeException('Could not fetch the identity provider\'s signing keys.');
        }

        try {
            $keys = JWK::parseKeySet($jwksResponse->json());
            $claims = (array) JWT::decode($tokenResponse->json('id_token'), $keys);
        } catch (Throwable $e) {
            throw new RuntimeException('The identity provider\'s ID token could not be verified.', previous: $e);
        }

        if (empty($claims['sub'])) {
            throw new RuntimeException('The identity provider did not return a subject claim.');
        }

        // JWT::decode() verifies the signature and standard time-based
        // claims (exp/nbf/iat) but — per the OIDC spec — audience and issuer
        // are the relying party's own responsibility to check: without this,
        // a valid token issued to a *different* client of the same IdP, or
        // by a different issuer entirely, would otherwise be accepted.
        $expectedIssuer = rtrim((string) config('services.oidc.issuer'), '/');
        $actualIssuer = rtrim((string) ($claims['iss'] ?? ''), '/');
        $audience = (array) ($claims['aud'] ?? []);

        if ($actualIssuer !== $expectedIssuer) {
            throw new RuntimeException('The ID token was issued by an unexpected issuer.');
        }

        if (! in_array((string) config('services.oidc.client_id'), $audience, true)) {
            throw new RuntimeException('The ID token was not issued for this application.');
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => $claims['email'] ?? null,
            'name' => $claims['name'] ?? null,
        ];
    }

    private function discovery(): array
    {
        $issuer = rtrim((string) config('services.oidc.issuer'), '/');
        $response = Http::get("{$issuer}/.well-known/openid-configuration");

        if (! $response->successful()) {
            throw new RuntimeException('Could not reach the identity provider.');
        }

        return $response->json();
    }
}
