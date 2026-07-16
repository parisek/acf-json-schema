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
}
