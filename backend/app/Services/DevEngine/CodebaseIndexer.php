<?php

namespace App\Services\DevEngine;

use App\Models\FileDependency;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\AiTextGenerator;

class CodebaseIndexer
{
    private const LANGUAGE_MAP = [
        'js' => 'javascript', 'jsx' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
        'ts' => 'typescript', 'tsx' => 'typescript',
        'php' => 'php', 'py' => 'python',
        'json' => 'json', 'css' => 'css', 'scss' => 'css', 'md' => 'markdown',
        'yml' => 'yaml', 'yaml' => 'yaml', 'html' => 'html', 'blade.php' => 'blade',
    ];

    // Everything else (json, css, markdown, yaml, ...) is indexed for the
    // dependency/file-list view but isn't worth spending an AI call summarizing.
    private const SUMMARIZABLE_LANGUAGES = ['javascript', 'typescript', 'php', 'python'];

    private const MAX_CONTENT_CHARS = 2500;

    private const CHUNK_SIZE = 8;

    public function __construct(private AiTextGenerator $ai) {}

    /**
     * Compares an incoming {path, hash} listing (sourced from Companion via the
     * frontend) against what this project already has indexed. Files whose hash
     * changed or that are entirely new go into `needsContent` — the frontend
     * only has to fetch and upload the actual content for those. Files that
     * disappeared from disk are removed from the index immediately.
     */
    public function diff(Project $project, array $incomingFiles): array
    {
        $known = ProjectFile::where('project_id', $project->id)->pluck('content_hash', 'path');

        $incomingPaths = [];
        $needsContent = [];

        foreach ($incomingFiles as $file) {
            $path = $file['path'];
            $incomingPaths[] = $path;
            $hash = $file['hash'] ?? null;

            if (! $known->has($path) || $known->get($path) !== $hash) {
                $needsContent[] = $path;
            }
        }

        $deletedPaths = $known->keys()->diff($incomingPaths)->values()->all();

        if ($deletedPaths) {
            ProjectFile::where('project_id', $project->id)->whereIn('path', $deletedPaths)->delete();
        }

        return [
            'needsContent' => $needsContent,
            'deleted' => $deletedPaths,
            'unchanged' => count($incomingFiles) - count($needsContent),
        ];
    }

    /**
     * @param  array<int, array{path: string, hash: ?string, content: string}>  $filesWithContent
     */
    public function indexFiles(Project $project, array $filesWithContent): array
    {
        $indexedImports = [];

        foreach (array_chunk($filesWithContent, self::CHUNK_SIZE) as $chunk) {
            $summaries = $this->summarizeChunk($chunk);

            foreach ($chunk as $file) {
                $path = $file['path'];
                $language = $this->detectLanguage($path);

                // Via the project's own relation so the foreign key is set
                // directly by Eloquent rather than needing to be mass-assignable.
                $project->files()->updateOrCreate(
                    ['path' => $path],
                    [
                        'language' => $language,
                        'content_hash' => $file['hash'] ?? null,
                        'summary' => $summaries[$path]['summary'] ?? null,
                        'symbols' => $summaries[$path]['symbols'] ?? [],
                        'last_scanned_at' => now(),
                    ]
                );

                $indexedImports[$path] = $this->extractImports($file['content'], $language);
            }
        }

        $this->rebuildDependencies($project, $indexedImports);

        return ['indexed' => array_keys($indexedImports)];
    }

    private function detectLanguage(string $path): ?string
    {
        if (str_ends_with($path, '.blade.php')) {
            return 'blade';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::LANGUAGE_MAP[$ext] ?? null;
    }

    /**
     * One AI call summarizes a small batch of files at once (cheaper and faster
     * than one call per file). Imports are deliberately NOT asked of the model —
     * they're extracted mechanically in extractImports() instead, since regex
     * extraction of `import`/`require`/`use` statements is both more reliable
     * and free compared to asking a language model to enumerate them.
     */
    private function summarizeChunk(array $chunk): array
    {
        $summarizable = array_values(array_filter(
            $chunk,
            fn (array $file) => in_array($this->detectLanguage($file['path']), self::SUMMARIZABLE_LANGUAGES, true)
        ));

        if (! $summarizable) {
            return [];
        }

        $blocks = [];
        foreach ($summarizable as $file) {
            $snippet = mb_substr($file['content'], 0, self::MAX_CONTENT_CHARS);
            $blocks[] = sprintf("### %s\n```\n%s\n```", $file['path'], $snippet);
        }

        $systemPrompt = 'You are a senior software engineer producing a short technical index of a codebase. '
            .'For each file given, respond with ONLY a JSON object mapping each file path to '
            .'{"summary": "one sentence describing what this file does", "symbols": ["exported or top-level names"]}. '
            .'No prose outside the JSON.';

        $userPrompt = implode("\n\n", $blocks);

        $raw = $this->ai->generate($systemPrompt, $userPrompt, 1500);
        $parsed = $this->parseJson($raw);

        return is_array($parsed) ? $parsed : [];
    }

    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Mechanical (non-AI) extraction of raw import/require/use specifiers,
     * exactly as written in the source — resolution to actual project files
     * happens separately in resolveImport().
     */
    private function extractImports(string $content, ?string $language): array
    {
        $specifiers = [];

        if (in_array($language, ['javascript', 'typescript'], true)) {
            if (preg_match_all('/(?:from|import)\s+[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $specifiers = array_merge($specifiers, $m[1]);
            }
            if (preg_match_all('/require\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) {
                $specifiers = array_merge($specifiers, $m[1]);
            }
        } elseif ($language === 'php') {
            if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)\s*;/m', $content, $m)) {
                $specifiers = array_merge($specifiers, $m[1]);
            }
        } elseif ($language === 'python') {
            if (preg_match_all('/^\s*from\s+([\w.]+)\s+import/m', $content, $m)) {
                $specifiers = array_merge($specifiers, $m[1]);
            }
            if (preg_match_all('/^\s*import\s+([\w.]+)/m', $content, $m)) {
                $specifiers = array_merge($specifiers, $m[1]);
            }
        }

        return array_values(array_unique($specifiers));
    }

    /**
     * Heuristic specifier -> project-file resolution. Deliberately conservative:
     * an import that can't be confidently resolved to a file this project
     * actually indexed is simply dropped rather than guessed at, so the
     * dependency graph never contains a wrong edge, only a missing one.
     */
    private function resolveImport(string $specifier, string $fromPath, array $allPaths): ?string
    {
        $candidates = [];

        if (str_starts_with($specifier, '.')) {
            $baseDir = dirname($fromPath);
            $resolved = $this->normalizePath($baseDir.'/'.$specifier);
            $candidates[] = $resolved;
        } elseif (str_starts_with($specifier, '@/')) {
            // Convention used throughout DevPlan's own generated Next.js scaffolds:
            // "@/x" maps to the nearest "src/" ancestor directory of the importing file.
            if (preg_match('#^((?:.*/)?src)/#', $fromPath, $m)) {
                $candidates[] = $m[1].'/'.substr($specifier, 2);
            }
        } elseif (str_starts_with($specifier, 'App\\')) {
            // Laravel's default PSR-4 convention: the "App\" namespace root maps
            // to the "app/" directory sibling of wherever this file itself lives
            // under its own "app/" tree.
            if (preg_match('#^((?:.*/)?app)/#', $fromPath, $m)) {
                $relative = str_replace('\\', '/', substr($specifier, 4));
                $candidates[] = $m[1].'/'.$relative.'.php';
            }
        } else {
            // Bare package name (npm package, Composer vendor namespace, stdlib
            // module) — external to this project, not something we can resolve
            // to one of its own files.
            return null;
        }

        $extensionless = ['.js', '.jsx', '.ts', '.tsx', ''];

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, '/');

            foreach ($extensionless as $suffix) {
                if (in_array($candidate.$suffix, $allPaths, true)) {
                    return $candidate.$suffix;
                }
            }

            foreach (['index.js', 'index.jsx', 'index.ts', 'index.tsx'] as $indexFile) {
                if (in_array($candidate.'/'.$indexFile, $allPaths, true)) {
                    return $candidate.'/'.$indexFile;
                }
            }

            if (in_array($candidate, $allPaths, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /**
     * @param  array<string, array<int, string>>  $importsByPath  path => raw specifiers found in that file
     */
    private function rebuildDependencies(Project $project, array $importsByPath): void
    {
        if (! $importsByPath) {
            return;
        }

        $fileIdsByPath = ProjectFile::where('project_id', $project->id)->pluck('id', 'path');
        $allPaths = $fileIdsByPath->keys()->all();

        $fromFileIds = [];
        foreach (array_keys($importsByPath) as $path) {
            if ($fileIdsByPath->has($path)) {
                $fromFileIds[] = $fileIdsByPath->get($path);
            }
        }

        if ($fromFileIds) {
            FileDependency::where('project_id', $project->id)
                ->whereIn('from_file_id', $fromFileIds)
                ->delete();
        }

        $rows = [];
        $unresolvedByPath = [];

        foreach ($importsByPath as $fromPath => $specifiers) {
            if (! $fileIdsByPath->has($fromPath)) {
                continue;
            }
            $fromFileId = $fileIdsByPath->get($fromPath);

            foreach ($specifiers as $specifier) {
                $resolvedPath = $this->resolveImport($specifier, $fromPath, $allPaths);

                if ($resolvedPath && $resolvedPath !== $fromPath) {
                    $rows[] = [
                        'project_id' => $project->id,
                        'from_file_id' => $fromFileId,
                        'to_file_id' => $fileIdsByPath->get($resolvedPath),
                        'kind' => 'import',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    continue;
                }

                // A bare package name (npm/composer/stdlib) is *expected* to
                // resolve to nothing — only flag specifiers that looked like
                // they should point at one of this project's own files.
                if ($this->looksInternal($specifier)) {
                    $unresolvedByPath[$fromPath][] = $specifier;
                }
            }
        }

        if ($rows) {
            // Chunked insert avoids one gigantic query for very large scans.
            foreach (array_chunk($rows, 200) as $batch) {
                FileDependency::insertOrIgnore($batch);
            }
        }

        // Persist (or clear) the unresolved-import list per file so the file
        // explorer can show a warning badge without re-parsing anything.
        // Goes through model instances (not a raw query-builder update) so
        // the array->jsonb cast is actually applied.
        $touchedPaths = array_values(array_filter(array_keys($importsByPath), fn ($p) => $fileIdsByPath->has($p)));
        if ($touchedPaths) {
            $files = $project->files()->whereIn('path', $touchedPaths)->get()->keyBy('path');
            foreach ($touchedPaths as $path) {
                $specifiers = isset($unresolvedByPath[$path]) ? array_values(array_unique($unresolvedByPath[$path])) : null;
                $files->get($path)?->update(['unresolved_imports' => $specifiers]);
            }
        }
    }

    private function looksInternal(string $specifier): bool
    {
        return str_starts_with($specifier, '.')
            || str_starts_with($specifier, '@/')
            || str_starts_with($specifier, 'App\\');
    }
}
