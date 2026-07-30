<?php

namespace App\Services\DevEngine;

/**
 * A deterministic (no AI call), pattern-based check that generated content
 * actually matches the project's own recorded tech stack — catching the
 * concrete failure mode the audit named: the AI generating MySQL-dialect SQL
 * for a Postgres project, or a React import into a Vue codebase. This is
 * pattern matching against a handful of well-known, unambiguous signatures,
 * not a real parser for every language/framework combination — it will miss
 * subtler mismatches, and only runs the checks relevant to whichever stack
 * fields are actually recorded for the project.
 */
class StackConformanceChecker
{
    /**
     * @param  array{frontend: string, backend: string, database: string}  $techStack
     * @return string|null a short, specific reason if inconsistent, else null
     */
    public function check(array $techStack, string $content): ?string
    {
        $database = strtolower($techStack['database'] ?? '');
        $backend = strtolower($techStack['backend'] ?? '');
        $frontend = strtolower($techStack['frontend'] ?? '');

        if ($database && str_contains($database, 'postgres')) {
            if (preg_match('/\bAUTO_INCREMENT\b/i', $content)) {
                return "Uses MySQL's AUTO_INCREMENT syntax, but this project's database is PostgreSQL.";
            }
            if (preg_match('/\bENGINE\s*=\s*InnoDB\b/i', $content)) {
                return "Uses a MySQL-specific ENGINE= clause, but this project's database is PostgreSQL.";
            }
        }

        if ($database && str_contains($database, 'mysql') && ! str_contains($database, 'postgres')) {
            if (preg_match('/\bSERIAL\b/i', $content) && preg_match('/create\s+table/i', $content)) {
                return "Uses PostgreSQL's SERIAL syntax, but this project's database is MySQL.";
            }
        }

        if ($frontend && str_contains($frontend, 'vue')) {
            if (preg_match('/\bimport\s+React\b/', $content) || preg_match('/from\s+[\'"]react[\'"]/', $content)) {
                return "Imports React, but this project's frontend framework is Vue.";
            }
        }

        if ($frontend && (str_contains($frontend, 'react') || str_contains($frontend, 'next'))) {
            if (preg_match('/<template>/i', $content) && preg_match('/export\s+default\s*\{/', $content)) {
                return "Looks like a Vue single-file component, but this project's frontend framework is React.";
            }
        }

        if ($backend && str_contains($backend, 'laravel')) {
            if (preg_match('/^\s*from\s+django/mi', $content) || preg_match('/@app\.route/', $content)) {
                return "Looks like Django/Flask (Python) code, but this project's backend framework is Laravel.";
            }
        }

        if ($backend && str_contains($backend, 'django')) {
            if (preg_match('/^\s*namespace\s+App\\\\/mi', $content)) {
                return "Looks like Laravel (PHP) code, but this project's backend framework is Django.";
            }
        }

        return null;
    }
}
