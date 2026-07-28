<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleItem;
use Illuminate\Http\Request;

class ModuleItemController extends Controller
{
    public function index(Request $request, Module $module)
    {
        $this->authorizeModule($request, $module);

        return $module->items;
    }

    public function store(Request $request, Module $module)
    {
        $this->authorizeModule($request, $module);

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
        $this->authorizeItem($request, $item);

        return $item;
    }

    public function update(Request $request, ModuleItem $item)
    {
        $this->authorizeItem($request, $item);

        $data = $request->validate([
            'content' => ['sometimes', 'array'],
            'is_user_edited' => ['sometimes', 'boolean'],
        ]);

        $item->update($data);

        return $item;
    }

    public function destroy(Request $request, ModuleItem $item)
    {
        $this->authorizeItem($request, $item);

        $item->delete();

        return response()->noContent();
    }

    private function authorizeModule(Request $request, Module $module): void
    {
        abort_unless($module->project()->value('user_id') === $request->user()->id, 403);
    }

    private function authorizeItem(Request $request, ModuleItem $item): void
    {
        $ownerId = Module::whereKey($item->module_id)
            ->join('projects', 'projects.id', '=', 'modules.project_id')
            ->value('projects.user_id');

        abort_unless($ownerId === $request->user()->id, 403);
    }
}
