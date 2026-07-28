<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    private const PLANS = ['free', 'pro', 'team'];

    public function show(Request $request)
    {
        $subscription = $request->user()->subscriptions()->firstOrCreate([], ['plan' => 'free']);

        return response()->json($subscription);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'in:' . implode(',', self::PLANS)],
        ]);

        $subscription = $request->user()->subscriptions()->firstOrCreate([], ['plan' => 'free']);
        $subscription->update($data);

        return $subscription;
    }
}
