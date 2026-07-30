<?php

namespace Tests\Unit;

use App\Services\RiskAnalyzer;
use Tests\TestCase;

class RiskAnalyzerTest extends TestCase
{
    public function test_env_files_are_always_high_risk(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame('high', $analyzer->classifyFile('.env', 'modify'));
        $this->assertSame('high', $analyzer->classifyFile('backend/.env.production', 'create'));
    }

    public function test_migrations_are_high_risk(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame('high', $analyzer->classifyFile('database/migrations/2026_01_01_create_users_table.php', 'create'));
    }

    public function test_any_delete_action_is_high_risk_regardless_of_path(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame('high', $analyzer->classifyFile('app/Models/Widget.php', 'delete'));
    }

    public function test_lockfiles_and_config_are_medium_risk(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame('medium', $analyzer->classifyFile('package-lock.json', 'modify'));
        $this->assertSame('medium', $analyzer->classifyFile('config/app.php', 'modify'));
    }

    public function test_an_ordinary_file_is_low_risk(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame('low', $analyzer->classifyFile('app/Models/Widget.php', 'create'));
    }

    /**
     * $content is optional (Subsystem 7 — Static Security Scan); omitting it
     * entirely, as every pre-existing call site does, must classify exactly
     * as before.
     */
    public function test_content_argument_is_optional_and_does_not_change_path_only_classification(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame('low', $analyzer->classifyFile('app/Models/Widget.php', 'create'));
        $this->assertSame('low', $analyzer->classifyFile('app/Models/Widget.php', 'create', null));
    }

    public function test_a_plain_looking_path_is_escalated_to_high_when_its_content_contains_a_hardcoded_secret(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame(
            'high',
            $analyzer->classifyFile('app/Models/Widget.php', 'create', "<?php\n\$apiKey = 'sk_live_abcdefgh12345678';\n"),
        );
    }

    public function test_a_plain_looking_path_stays_low_when_its_content_has_no_findings(): void
    {
        $analyzer = new RiskAnalyzer;
        $this->assertSame(
            'low',
            $analyzer->classifyFile('app/Models/Widget.php', 'create', "<?php\nclass Widget extends Model {}\n"),
        );
    }
}
