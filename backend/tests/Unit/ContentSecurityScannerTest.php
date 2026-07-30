<?php

namespace Tests\Unit;

use App\Services\ContentSecurityScanner;
use Tests\TestCase;

class ContentSecurityScannerTest extends TestCase
{
    private function categories(array $findings): array
    {
        return array_column($findings, 'category');
    }

    public function test_string_concatenated_sql_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('$rows = DB::select("SELECT * FROM users WHERE id = " . $id);');

        $this->assertContains('sql_injection', $this->categories($findings));
    }

    public function test_interpolated_sql_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('$rows = DB::select("SELECT * FROM users WHERE id = $id");');

        $this->assertContains('sql_injection', $this->categories($findings));
    }

    public function test_template_literal_sql_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('const rows = await db.query(`SELECT * FROM users WHERE id = ${id}`);');

        $this->assertContains('sql_injection', $this->categories($findings));
    }

    public function test_eloquent_where_bindings_are_not_flagged_as_sql_injection(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan("<?php\nclass UserController {\n  public function show(\$id) {\n    return User::where('id', \$id)->first();\n  }\n}\n");

        $this->assertNotContains('sql_injection', $this->categories($findings));
    }

    public function test_parameter_bound_query_is_not_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('$rows = DB::select("SELECT * FROM users WHERE id = ?", [$id]);');

        $this->assertNotContains('sql_injection', $this->categories($findings));
    }

    public function test_dangerously_set_inner_html_is_flagged_as_xss(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('function Comment({ html }) { return <div dangerouslySetInnerHTML={{ __html: html }} />; }');

        $this->assertContains('xss', $this->categories($findings));
    }

    public function test_blade_unescaped_output_is_flagged_as_xss(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('<div>{!! $userComment !!}</div>');

        $this->assertContains('xss', $this->categories($findings));
    }

    public function test_vue_v_html_is_flagged_as_xss(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('<div v-html="userComment"></div>');

        $this->assertContains('xss', $this->categories($findings));
    }

    public function test_normal_escaped_blade_output_is_not_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('<div>{{ $userComment }}</div>');

        $this->assertNotContains('xss', $this->categories($findings));
    }

    public function test_a_hardcoded_api_key_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan("const apiKey = 'sk_live_abcdefgh12345678';");

        $this->assertContains('hardcoded_secret', $this->categories($findings));
    }

    public function test_reading_a_key_from_env_is_not_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan("<?php\n\$apiKey = env('STRIPE_API_KEY');\n");

        $this->assertNotContains('hardcoded_secret', $this->categories($findings));
    }

    public function test_eval_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('eval($userInput);');

        $this->assertContains('unsafe_eval', $this->categories($findings));
    }

    public function test_shell_exec_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('$output = shell_exec("ls " . $userInput);');

        $this->assertContains('command_execution', $this->categories($findings));
    }

    public function test_unlink_from_request_input_is_flagged(): void
    {
        $scanner = new ContentSecurityScanner;
        $findings = $scanner->scan('unlink($_GET["path"]);');

        $this->assertContains('unsafe_filesystem', $this->categories($findings));
    }

    public function test_clean_ordinary_code_produces_no_findings(): void
    {
        $scanner = new ContentSecurityScanner;
        $content = "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\Widget;\n\nclass WidgetController extends Controller\n{\n    public function index()\n    {\n        return Widget::where('active', true)->get();\n    }\n}\n";

        $this->assertSame([], $scanner->scan($content));
    }
}
