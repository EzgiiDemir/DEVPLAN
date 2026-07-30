<?php

namespace Tests\Unit;

use App\Services\DevEngine\StackConformanceChecker;
use Tests\TestCase;

class StackConformanceCheckerTest extends TestCase
{
    public function test_mysql_auto_increment_is_flagged_for_a_postgres_project(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => '', 'backend' => '', 'database' => 'PostgreSQL'],
            "CREATE TABLE widgets (id INT AUTO_INCREMENT PRIMARY KEY);",
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('AUTO_INCREMENT', $result);
    }

    public function test_postgres_serial_is_flagged_for_a_mysql_project(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => '', 'backend' => '', 'database' => 'MySQL'],
            "CREATE TABLE widgets (id SERIAL PRIMARY KEY);",
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('SERIAL', $result);
    }

    public function test_matching_dialect_is_not_flagged(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => '', 'backend' => '', 'database' => 'PostgreSQL'],
            "CREATE TABLE widgets (id SERIAL PRIMARY KEY);",
        );

        $this->assertNull($result);
    }

    public function test_a_react_import_is_flagged_for_a_vue_project(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => 'Vue', 'backend' => '', 'database' => ''],
            "import React from 'react';\nexport default function Widget() { return <div />; }",
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('React', $result);
    }

    public function test_a_vue_sfc_is_flagged_for_a_react_project(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => 'React', 'backend' => '', 'database' => ''],
            "<template><div>{{ msg }}</div></template>\n<script>\nexport default {\n  data() { return { msg: 'hi' }; }\n}\n</script>",
        );

        $this->assertNotNull($result);
    }

    public function test_matching_frontend_framework_is_not_flagged(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => 'React', 'backend' => '', 'database' => ''],
            "import React from 'react';\nexport default function Widget() { return <div />; }",
        );

        $this->assertNull($result);
    }

    public function test_django_code_is_flagged_for_a_laravel_backend(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => '', 'backend' => 'Laravel', 'database' => ''],
            "from django.db import models\n\nclass Widget(models.Model):\n    name = models.CharField(max_length=255)",
        );

        $this->assertNotNull($result);
    }

    public function test_laravel_code_is_flagged_for_a_django_backend(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => '', 'backend' => 'Django', 'database' => ''],
            "<?php\n\nnamespace App\\Models;\n\nclass Widget extends Model\n{\n}\n",
        );

        $this->assertNotNull($result);
    }

    public function test_an_empty_tech_stack_never_flags_anything(): void
    {
        $checker = new StackConformanceChecker;

        $result = $checker->check(
            ['frontend' => '', 'backend' => '', 'database' => ''],
            "CREATE TABLE widgets (id INT AUTO_INCREMENT PRIMARY KEY);",
        );

        $this->assertNull($result);
    }
}
