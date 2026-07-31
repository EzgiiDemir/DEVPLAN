<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * Always returns the same generic success response regardless of
     * whether the email actually matches an account — a distinct "no such
     * user" response would let anyone enumerate registered email addresses
     * by trying them here.
     */
    public function sendResetLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink(['email' => $data['email']]);

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) use ($request) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                event(new PasswordReset($user));

                // A reset is a strong signal the account may have been
                // compromised (or the user simply forgot their password) —
                // every existing session (this app authenticates via
                // Sanctum's stateful cookie session, not personal-access
                // tokens — see SessionController) should not silently
                // remain trusted afterward. The reset itself happens
                // unauthenticated (via the emailed token), so there is no
                // "current" session to spare — all of them go.
                DB::table('sessions')->where('user_id', $user->id)->delete();

                $this->audit->record($user, 'auth.password_reset');
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => trans($status)], 422);
        }

        return response()->json(['message' => trans($status)]);
    }
}
