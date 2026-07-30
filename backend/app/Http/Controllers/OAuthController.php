<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    public function __construct(private TeamService $teams, private AuditLogService $audit) {}

    /**
     * Returns the redirect URL as JSON rather than an HTTP redirect itself —
     * the frontend is a separate SPA origin, so it navigates the browser
     * there itself, matching how every other backend call here is a fetch,
     * not a page navigation. Reports configured:false rather than erroring
     * when no real GitHub app credentials exist (true in this environment).
     */
    public function redirect(Request $request)
    {
        if (! config('services.github.client_id')) {
            return response()->json(['configured' => false], 503);
        }

        return ['url' => Socialite::driver('github')->stateless()->redirect()->getTargetUrl()];
    }

    /**
     * The one endpoint GitHub itself redirects the browser to directly —
     * this renders a real redirect back to the frontend, not JSON.
     */
    public function callback(Request $request)
    {
        $oauthUser = Socialite::driver('github')->stateless()->user();

        $user = User::where('oauth_provider', 'github')->where('oauth_id', $oauthUser->getId())->first();

        if (! $user) {
            // A verified email already registered the normal way links to
            // this GitHub identity instead of creating a duplicate account.
            $user = User::where('email', $oauthUser->getEmail())->first();

            if ($user) {
                $user->forceFill(['oauth_provider' => 'github', 'oauth_id' => $oauthUser->getId()])->save();
            } else {
                $user = User::create([
                    'name' => $oauthUser->getName() ?: $oauthUser->getNickname() ?: 'GitHub User',
                    'email' => $oauthUser->getEmail(),
                    'password' => Hash::make(Str::random(64)),
                    'oauth_provider' => 'github',
                    'oauth_id' => $oauthUser->getId(),
                ]);
                $this->teams->ensurePersonalTeam($user);
            }
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record($user, 'auth.login', ['via' => 'oauth_github']);

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/dashboard');
    }
}
