<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * Marks the one-time welcome walkthrough as seen — server-side (not
     * just a localStorage flag) so it doesn't reappear on a different
     * device/browser, and survives clearing site data.
     */
    public function complete(Request $request)
    {
        if (! $request->user()->onboarding_completed_at) {
            $request->user()->forceFill(['onboarding_completed_at' => now()])->save();
        }

        return response()->json($request->user());
    }
}
