<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OidcService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class OidcController extends Controller
{
    public function __construct(
        private OidcService $oidc,
        private TeamService $teams,
        private AuditLogService $audit,
    ) {}

    /**
     * Mirrors OAuthController::redirect() — JSON with a URL, not an HTTP
     * redirect, since the frontend is a separate SPA origin that navigates
     * the browser itself. Unlike GitHub's stateless() flow, OIDC's
     * authorization code is bound to a `state` value stashed in the session
     * here and checked back in callback() — genuine CSRF protection for a
     * login flow, not optional for an enterprise SSO integration.
     */
    public function redirect(Request $request)
    {
        if (! $this->oidc->isConfigured()) {
            return response()->json(['configured' => false], 503);
        }

        $state = Str::random(40);
        $request->session()->put('oidc_state', $state);

        return ['url' => $this->oidc->authorizationUrl($state)];
    }

    /**
     * The IdP redirects the browser here directly — a real redirect back to
     * the frontend either way, including on failure, since the user is
     * mid-navigation with no SPA JavaScript context to hand a JSON error to.
     */
    public function callback(Request $request)
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $expectedState = $request->session()->pull('oidc_state');

        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect()->away("{$frontendUrl}/login?sso_error=state_mismatch");
        }

        if (! $request->query('code')) {
            return redirect()->away("{$frontendUrl}/login?sso_error=missing_code");
        }

        try {
            $claims = $this->oidc->resolveClaims($request->query('code'));
        } catch (RuntimeException) {
            return redirect()->away("{$frontendUrl}/login?sso_error=identity_provider");
        }

        $user = User::where('oauth_provider', 'oidc')->where('oauth_id', $claims['sub'])->first();

        if (! $user) {
            // A verified email already registered the normal way links to
            // this IdP identity instead of creating a duplicate account.
            $user = $claims['email'] ? User::where('email', $claims['email'])->first() : null;

            if ($user) {
                $user->forceFill(['oauth_provider' => 'oidc', 'oauth_id' => $claims['sub']])->save();
            } elseif ($claims['email']) {
                $user = User::create([
                    'name' => $claims['name'] ?: $claims['email'],
                    'email' => $claims['email'],
                    'password' => Hash::make(Str::random(64)),
                    'oauth_provider' => 'oidc',
                    'oauth_id' => $claims['sub'],
                ]);
                $this->teams->ensurePersonalTeam($user);
            } else {
                return redirect()->away("{$frontendUrl}/login?sso_error=missing_email");
            }
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record($user, 'auth.login', ['via' => 'oidc']);

        return redirect()->away("{$frontendUrl}/dashboard");
    }
}
