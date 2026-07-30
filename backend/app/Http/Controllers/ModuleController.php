<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function show(Request $request, Module $module)
    {
        $this->authorize('view', $module->project);

        return $module->load('items');
    }

    public function update(Request $request, Module $module)
    {
        $this->authorize('act', $module->project);

        $data = $request->validate([
            'status' => ['required', 'in:not_started,in_progress,completed'],
        ]);

        $module->update($data);

        return $module;
    }
}
