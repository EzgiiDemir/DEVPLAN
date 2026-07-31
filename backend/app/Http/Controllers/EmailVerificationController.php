<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * The `signed` route middleware (see routes/api.php) has already
     * rejected this request if the signature/expiry don't check out before
     * this method ever runs — the hash param is a second, independent check
     * that it's genuinely this user's own current email being verified.
     */
    public function verify(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')), 403);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->markEmailAsVerified();
        $this->audit->record($user, 'auth.email_verified');

        return response()->json(['message' => 'Email verified.']);
    }

    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }
}
