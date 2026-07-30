<?php

namespace App\Services;

/**
 * Deterministic (no AI call), regex/heuristic scan run against every file
 * FeatureAgentService::generateContent() produces, right after the AI
 * returns it — surfacing common insecure-code patterns before a human
 * approves the diff. This is pattern matching against well-known signatures,
 * not a real static-analysis engine (no AST, no data-flow/taint tracking):
 * it will miss subtler cases and can occasionally flag safe code (e.g. a
 * string that merely mentions a SQL keyword near a variable), which is why
 * findings are shown to a human before approval rather than blocking
 * generation outright.
 */
class ContentSecurityScanner
{
    /**
     * @return array<int, array{category: string, message: string}>
     */
    public function scan(string $content): array
    {
        return array_merge(
            $this->checkSqlInjection($content),
            $this->checkXss($content),
            $this->checkHardcodedSecrets($content),
            $this->checkUnsafeEval($content),
            $this->checkCommandExecution($content),
            $this->checkUnsafeFilesystem($content),
        );
    }

    /**
     * Flags a SQL keyword string built by concatenating or interpolating a
     * variable directly into it — as opposed to Eloquent/query-builder calls
     * like `where('id', $id)` or parameter-bound `DB::select($sql, [$id])`,
     * which this deliberately does not match.
     */
    private function checkSqlInjection(string $content): array
    {
        $sqlKeywords = 'SELECT|INSERT INTO|UPDATE|DELETE FROM';

        $hasConcatenation = preg_match(
            '/["\'](?:(?!["\']).)*\b(?:'.$sqlKeywords.')\b(?:(?!["\']).)*["\']\s*\.\s*\$\w+/i',
            $content,
        );
        $hasInterpolation = preg_match(
            '/["\'](?:(?!["\']).)*\b(?:'.$sqlKeywords.')\b(?:(?!["\']).)*\$\{?\w+\}?(?:(?!["\']).)*["\']/i',
            $content,
        );
        $hasTemplateLiteral = preg_match(
            '/`(?:(?!`).)*\b(?:'.$sqlKeywords.')\b(?:(?!`).)*\$\{\w+\}(?:(?!`).)*`/i',
            $content,
        );

        if ($hasConcatenation || $hasInterpolation || $hasTemplateLiteral) {
            return [['category' => 'sql_injection', 'message' => 'A SQL statement appears to be built with string concatenation/interpolation instead of parameter binding — use query bindings or an ORM method instead.']];
        }

        return [];
    }

    private function checkXss(string $content): array
    {
        if (preg_match('/dangerouslySetInnerHTML/', $content)
            || preg_match('/\{!!\s*\$\w+\s*!!\}/', $content)
            || preg_match('/\bv-html\s*=/', $content)) {
            return [['category' => 'xss', 'message' => 'Renders unescaped HTML output (dangerouslySetInnerHTML / Blade {!! !!} / v-html) — make sure the content is sanitized or comes from a trusted source.']];
        }

        return [];
    }

    private function checkHardcodedSecrets(string $content): array
    {
        if (preg_match('/\b(api[_-]?key|secret|password|passwd|token)\b\s*[:=]\s*["\'][A-Za-z0-9\-_\/+=]{12,}["\']/i', $content)) {
            return [['category' => 'hardcoded_secret', 'message' => 'A hardcoded credential-shaped string literal was found — secrets belong in environment variables, not source code.']];
        }

        return [];
    }

    private function checkUnsafeEval(string $content): array
    {
        if (preg_match('/\beval\s*\(/', $content) || preg_match('/\bnew\s+Function\s*\(/', $content)) {
            return [['category' => 'unsafe_eval', 'message' => 'Uses eval()/Function() to execute dynamically built code.']];
        }

        return [];
    }

    private function checkCommandExecution(string $content): array
    {
        if (preg_match('/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i', $content)
            || preg_match('/\brequire\([\'"]child_process[\'"]\)/', $content)
            || preg_match('/from\s+[\'"]child_process[\'"]/', $content)) {
            return [['category' => 'command_execution', 'message' => 'Executes shell commands directly — make sure any interpolated input is validated, never passed straight to a shell.']];
        }

        return [];
    }

    private function checkUnsafeFilesystem(string $content): array
    {
        if (preg_match('/\b(?:unlink|rmdir|fopen|file_put_contents|readFileSync|writeFileSync)\s*\([^)]*\$_(GET|POST|REQUEST)/i', $content)
            || preg_match('/\b(?:unlink|rmdir|fopen|file_put_contents)\s*\([^)]*\brequest\(/i', $content)) {
            return [['category' => 'unsafe_filesystem', 'message' => 'A filesystem operation is built directly from unvalidated request input — validate/allowlist the path first.']];
        }

        return [];
    }
}
