<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function show(Request $request, Module $module)
    {
        $this->authorizeAccess($request, $module);

        return $module->load('items');
    }

    public function update(Request $request, Module $module)
    {
        $this->authorizeAccess($request, $module);

        $data = $request->validate([
            'status' => ['required', 'in:not_started,in_progress,completed'],
        ]);

        $module->update($data);

        return $module;
    }

    private function authorizeAccess(Request $request, Module $module): void
    {
        abort_unless($module->project()->value('user_id') === $request->user()->id, 403);
    }
}
