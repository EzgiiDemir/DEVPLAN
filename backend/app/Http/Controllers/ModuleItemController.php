<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleItem;
use Illuminate\Http\Request;

class ModuleItemController extends Controller
{
    public function index(Request $request, Module $module)
    {
        $this->authorize('view', $module->project);

        return $module->items;
    }

    public function store(Request $request, Module $module)
    {
        $this->authorize('act', $module->project);

        $data = $request->validate([
            'item_type' => ['required', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'is_ai_generated' => ['sometimes', 'boolean'],
        ]);

        $item = $module->items()->create($data);

        return response()->json($item, 201);
    }

    public function show(Request $request, ModuleItem $item)
    {
        $this->authorize('view', $item->module->project);

        return $item;
    }

    public function update(Request $request, ModuleItem $item)
    {
        $this->authorize('act', $item->module->project);

        $data = $request->validate([
            'content' => ['sometimes', 'array'],
            'is_user_edited' => ['sometimes', 'boolean'],
        ]);

        $item->update($data);

        return $item;
    }

    public function destroy(Request $request, ModuleItem $item)
    {
        $this->authorize('act', $item->module->project);

        $item->delete();

        return response()->noContent();
    }
}
