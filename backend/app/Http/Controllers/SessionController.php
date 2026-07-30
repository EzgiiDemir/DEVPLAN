<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    public function index(Request $request)
    {
        $currentId = $request->session()->getId();

        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active_at' => date(DATE_ATOM, $row->last_activity),
                'is_current' => $row->id === $currentId,
            ]);
    }

    public function destroy(Request $request, string $id)
    {
        abort_if($id === $request->session()->getId(), 422, 'Use logout to end your current session.');

        $deleted = DB::table('sessions')->where('id', $id)->where('user_id', $request->user()->id)->delete();
        abort_unless($deleted, 404);

        $this->audit->record($request->user(), 'auth.session_revoked', ['session_id' => $id]);

        return response()->noContent();
    }

    public function destroyOthers(Request $request)
    {
        $currentId = $request->session()->getId();

        $count = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $currentId)
            ->delete();

        $this->audit->record($request->user(), 'auth.session_revoked', ['count' => $count, 'scope' => 'all_others']);

        return response()->json(['revoked' => $count]);
    }
}
