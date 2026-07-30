<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor, private AuditLogService $audit) {}

    public function show(Request $request)
    {
        return ['enabled' => $request->user()->hasMfaEnabled()];
    }

    /**
     * Generates (or regenerates, if called again before confirm()) a
     * pending secret — MFA isn't actually required at login until confirm()
     * succeeds with a real code from it.
     */
    public function generate(Request $request)
    {
        return $this->twoFactor->generateSecret($request->user());
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $codes = $this->twoFactor->confirm($request->user(), $data['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => ['That code is invalid.']]);
        }

        $this->audit->record($request->user(), 'auth.mfa_enabled');

        return response()->json(['recovery_codes' => $codes], 201);
    }

    public function disable(Request $request)
    {
        $this->twoFactor->disable($request->user());
        $this->audit->record($request->user(), 'auth.mfa_disabled');

        return response()->noContent();
    }
}
