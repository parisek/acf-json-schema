<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Lint;

use Parisek\AcfJsonSchema\Lint\FileLintResult;
use Parisek\AcfJsonSchema\Lint\Reporter;
use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase {

    /** @return list<FileLintResult> */
    private static function results(): array {
        return [
            new FileLintResult('a/acf.json', 'acf', true, [], false, false),
            new FileLintResult('b/acf.json', 'acf', false, ['/fields/0' => 'The required properties (type) are missing'], false, false),
            new FileLintResult('c/random.json', null, false, [], false, true),
            new FileLintResult('d/foo.json', 'cpt', true, [], true, false),
        ];
    }

    public function test_text_format_reports_findings_and_summary(): void {
        $out = (new Reporter(color: false))->render(self::results(), 'text');
        $this->assertStringContainsString('b/acf.json (acf)', $out['stderr']);
        $this->assertStringContainsString('/fields/0 — The required properties (type) are missing', $out['stderr']);
        $this->assertStringContainsString('4 files scanned, 2 OK, 1 with errors (1), 1 fixed, 1 skipped', $out['stdout']);
    }

    public function test_text_format_without_color_has_no_ansi_codes(): void {
        $out = (new Reporter(color: false))->render(self::results(), 'text');
        $this->assertStringNotContainsString("\033[", $out['stdout'] . $out['stderr']);
    }

    public function test_text_format_with_color_has_ansi_codes(): void {
        $out = (new Reporter(color: true))->render(self::results(), 'text');
        $this->assertStringContainsString("\033[31m", $out['stderr']);
    }

    public function test_json_format_is_machine_readable_and_colorless(): void {
        $out = (new Reporter(color: true))->render(self::results(), 'json');
        $this->assertSame('', $out['stderr']);
        $this->assertStringNotContainsString("\033[", $out['stdout']);

        $doc = json_decode($out['stdout'], true);
        $this->assertIsArray($doc);
        $this->assertCount(4, $doc['files']);
        $this->assertSame(
            ['path' => 'b/acf.json', 'kind' => 'acf', 'valid' => false, 'skipped' => false, 'fixed' => false,
             'errors' => ['/fields/0' => 'The required properties (type) are missing']],
            $doc['files'][1],
        );
        $this->assertSame(
            ['scanned' => 4, 'ok' => 2, 'filesWithErrors' => 1, 'errors' => 1, 'fixed' => 1, 'skipped' => 1],
            $doc['summary'],
        );
    }

    public function test_github_format_emits_workflow_commands(): void {
        $out = (new Reporter(color: true))->render(self::results(), 'github');
        $this->assertStringContainsString(
            '::error file=b/acf.json::/fields/0 — The required properties (type) are missing',
            $out['stdout'],
        );
        $this->assertStringContainsString('4 files scanned', $out['stdout']);
        $this->assertSame('', $out['stderr']);
        $this->assertStringNotContainsString("\033[", $out['stdout']);
    }

    public function test_github_format_escapes_newlines_in_messages(): void {
        $results = [new FileLintResult('x.json', 'acf', false, ['/a' => "line1\nline2"], false, false)];
        $out = (new Reporter(color: false))->render($results, 'github');
        $this->assertStringContainsString('::error file=x.json::/a — line1%0Aline2', $out['stdout']);
    }

    public function test_non_string_error_messages_are_json_encoded(): void {
        $results = [new FileLintResult('x.json', null, false, [['error' => 'could not read file']], false, false)];
        $out = (new Reporter(color: false))->render($results, 'text');
        $this->assertStringContainsString('{"error":"could not read file"}', $out['stderr']);
    }
}
