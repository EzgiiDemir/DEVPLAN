<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TeamService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private TeamService $teams,
        private AuditLogService $audit,
        private TwoFactorService $twoFactor,
    ) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $this->teams->ensurePersonalTeam($user);
        $this->audit->record($user, 'auth.register');
        $user->sendEmailVerificationNotification();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json($user, 201);
    }

    /**
     * Deliberately does NOT call Auth::attempt() — that would fully
     * authenticate the session before an MFA-enabled user has proven the
     * second factor. The password is checked directly against the user
     * record instead (Auth::validate() resolves to Sanctum's RequestGuard
     * in this environment, which expects a `request` credential key, not a
     * password check — not usable here); if MFA is on, the pending user id
     * is stashed and a real TOTP/recovery code (via loginWithMfa()) is
     * required to finish.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            $this->audit->record(null, 'auth.login_failed', ['email' => $credentials['email']]);

            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if ($user->hasMfaEnabled()) {
            $request->session()->put('mfa_pending_user_id', $user->id);

            return response()->json(['requires_mfa' => true]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record($user, 'auth.login');

        return response()->json($request->user());
    }

    public function loginWithMfa(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get('mfa_pending_user_id');
        abort_unless($userId, 422, 'No pending login to verify.');

        $user = User::findOrFail($userId);

        if (! $this->twoFactor->verify($user, $data['code'])) {
            $this->audit->record($user, 'auth.mfa_challenge_failed');

            throw ValidationException::withMessages(['code' => ['That code is invalid.']]);
        }

        $request->session()->forget('mfa_pending_user_id');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record($user, 'auth.login', ['via' => 'mfa']);

        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $this->audit->record($request->user(), 'auth.logout');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
