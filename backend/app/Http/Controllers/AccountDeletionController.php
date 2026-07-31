<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use RuntimeException;

class AccountDeletionController extends Controller
{
    public function __construct(private AccountDeletionService $deletion) {}

    public function destroy(Request $request)
    {
        $user = $request->user();

        // OAuth/OIDC-only accounts have no real password to confirm — their
        // authenticated session is the only factor that exists for them.
        if (! $user->oauth_provider) {
            $request->validate(['current_password' => ['required', 'current_password']]);
        }

        try {
            $this->deletion->delete($user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Not Auth::guard('web')->logout() — it calls $user->save() to
        // cycle the remember token, which on a model that Eloquent now
        // considers not-yet-existing (deleted moments ago) performs an
        // INSERT instead of an UPDATE and silently resurrects the row.
        // The service already deleted every session row for this user;
        // invalidating this request's session is what actually matters for
        // the browser holding it.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
