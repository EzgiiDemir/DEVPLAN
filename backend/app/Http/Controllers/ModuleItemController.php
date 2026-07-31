<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleItem;
use App\Services\ProjectCache;
use Illuminate\Http\Request;

class ModuleItemController extends Controller
{
    public function __construct(private ProjectCache $cache) {}

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

        // Cheap and always-safe to clear on every module-item write rather
        // than special-casing exactly 'tech_stack'/'folder_structure' item
        // types here — this is an infrequent user action, not a hot path,
        // and any future module type that ContextEngineService starts
        // reading from is automatically covered too.
        $this->cache->forgetProjectContext($module->project);

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
        $this->cache->forgetProjectContext($item->module->project);

        return $item;
    }

    public function destroy(Request $request, ModuleItem $item)
    {
        $this->authorize('act', $item->module->project);

        $project = $item->module->project;
        $item->delete();
        $this->cache->forgetProjectContext($project);

        return response()->noContent();
    }
}
