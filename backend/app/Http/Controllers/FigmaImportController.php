<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FigmaImportController extends Controller
{
    public function import(Request $request)
    {
        $data = $request->validate([
            'file_url' => ['required', 'string'],
            'figma_token' => ['required', 'string'],
        ]);

        if (! preg_match('#figma\.com/(?:file|design)/([a-zA-Z0-9]+)#', $data['file_url'], $matches)) {
            return response()->json(['message' => trans('messages.figma_invalid_url')], 422);
        }

        $fileKey = $matches[1];

        $response = Http::withHeaders(['X-Figma-Token' => $data['figma_token']])
            ->get("https://api.figma.com/v1/files/{$fileKey}", ['depth' => 2]);

        if ($response->status() === 403 || $response->status() === 401) {
            return response()->json(['message' => trans('messages.figma_unauthorized')], 502);
        }

        if ($response->failed()) {
            return response()->json(['message' => trans('messages.figma_request_failed')], 502);
        }

        $document = $response->json('document');
        $canvases = $document['children'] ?? [];

        $pages = array_map(function ($canvas) {
            $frames = array_filter(
                $canvas['children'] ?? [],
                fn ($node) => in_array($node['type'] ?? null, ['FRAME', 'COMPONENT', 'COMPONENT_SET'], true),
            );

            return [
                'name' => $canvas['name'] ?? '',
                'frames' => array_values(array_map(fn ($f) => $f['name'], $frames)),
            ];
        }, $canvases);

        return response()->json([
            'file_name' => $response->json('name'),
            'pages' => array_values($pages),
        ]);
    }
}
