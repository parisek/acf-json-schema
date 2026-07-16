<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Lint;

use Parisek\AcfJsonSchema\Lint\CliOptions;
use PHPUnit\Framework\TestCase;

final class CliOptionsTest extends TestCase {

    public function test_parses_known_flags_and_paths(): void {
        $o = CliOptions::parse(['--strict', '--fix', '--wpml', 'acf-json', 'blocks/foo/block.json']);
        $this->assertTrue($o->strict);
        $this->assertTrue($o->fix);
        $this->assertTrue($o->wpml);
        $this->assertFalse($o->help);
        $this->assertFalse($o->version);
        $this->assertNull($o->error);
        $this->assertSame(['acf-json', 'blocks/foo/block.json'], $o->paths);
    }

    public function test_defaults_are_all_off(): void {
        $o = CliOptions::parse(['some/dir']);
        $this->assertFalse($o->strict);
        $this->assertFalse($o->fix);
        $this->assertFalse($o->wpml);
        $this->assertNull($o->error);
    }

    public function test_unknown_long_option_is_an_error(): void {
        $o = CliOptions::parse(['--stric', 'acf-json']);
        $this->assertSame('unknown option: --stric', $o->error);
    }

    public function test_unknown_short_option_is_an_error(): void {
        $o = CliOptions::parse(['-s', 'acf-json']);
        $this->assertSame('unknown option: -s', $o->error);
    }

    public function test_double_dash_terminates_option_parsing(): void {
        $o = CliOptions::parse(['--strict', '--', '--weird-file.json', '-another.json']);
        $this->assertTrue($o->strict);
        $this->assertNull($o->error);
        $this->assertSame(['--weird-file.json', '-another.json'], $o->paths);
    }

    public function test_help_and_version_flags(): void {
        $this->assertTrue(CliOptions::parse(['--help'])->help);
        $this->assertTrue(CliOptions::parse(['--version'])->version);
    }

    public function test_error_reports_first_unknown_option(): void {
        $o = CliOptions::parse(['--bogus', '--also-bogus']);
        $this->assertSame('unknown option: --bogus', $o->error);
    }

    public function test_format_defaults_to_text(): void {
        $this->assertSame('text', CliOptions::parse(['acf-json'])->format);
    }

    public function test_format_accepts_json_and_github(): void {
        $this->assertSame('json', CliOptions::parse(['--format=json', 'acf-json'])->format);
        $this->assertSame('github', CliOptions::parse(['--format=github', 'acf-json'])->format);
        $this->assertSame('text', CliOptions::parse(['--format=text', 'acf-json'])->format);
    }

    public function test_unknown_format_is_an_error(): void {
        $o = CliOptions::parse(['--format=xml', 'acf-json']);
        $this->assertSame('invalid --format value: xml (expected text, json or github)', $o->error);
    }

    public function test_bare_format_flag_is_an_error(): void {
        $o = CliOptions::parse(['--format', 'acf-json']);
        $this->assertSame('option --format requires a value (--format=<text|json|github>)', $o->error);
    }

    public function test_max_errors_defaults_to_50(): void {
        $this->assertSame(50, CliOptions::parse(['acf-json'])->maxErrors);
    }

    public function test_max_errors_accepts_positive_integer(): void {
        $this->assertSame(7, CliOptions::parse(['--max-errors=7', 'acf-json'])->maxErrors);
    }

    public function test_max_errors_rejects_non_positive_or_garbage(): void {
        $this->assertSame(
            'invalid --max-errors value: 0 (expected a positive integer)',
            CliOptions::parse(['--max-errors=0', 'acf-json'])->error,
        );
        $this->assertSame(
            'invalid --max-errors value: many (expected a positive integer)',
            CliOptions::parse(['--max-errors=many', 'acf-json'])->error,
        );
    }

    public function test_value_options_after_double_dash_are_paths(): void {
        $o = CliOptions::parse(['--', '--format=json']);
        $this->assertSame('text', $o->format);
        $this->assertSame(['--format=json'], $o->paths);
    }
}
