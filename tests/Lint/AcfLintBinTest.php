<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Lint;

use PHPUnit\Framework\TestCase;

/** End-to-end tests running bin/acf-lint as a real process. */
final class AcfLintBinTest extends TestCase {

    private const BIN = __DIR__ . '/../../bin/acf-lint';

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function runBin(string ...$args): array {
        $cmd = [PHP_BINARY, self::BIN, ...array_values($args)];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function test_unknown_option_exits_1_with_message(): void {
        $r = $this->runBin('--stric', __DIR__);
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('unknown option: --stric', $r['stderr']);
    }

    public function test_version_prints_package_version(): void {
        $r = $this->runBin('--version');
        $this->assertSame(0, $r['exit']);
        $this->assertMatchesRegularExpression('/^acf-lint \S+\n$/', $r['stdout']);
    }

    public function test_no_paths_prints_usage_and_exits_1(): void {
        $r = $this->runBin();
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('Usage:', $r['stderr']);
    }

    public function test_help_exits_0(): void {
        $r = $this->runBin('--help');
        $this->assertSame(0, $r['exit']);
        $this->assertStringContainsString('Usage:', $r['stderr']);
    }

    public function test_double_dash_path_is_linted_not_treated_as_flag(): void {
        $dir = sys_get_temp_dir() . '/acf-lint-bin-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/-leading-dash.json';
        file_put_contents($file, "{\"unrelated\": true}\n");
        try {
            $r = $this->runBin('--strict', '--', $file);
            $this->assertSame(0, $r['exit']);
            $this->assertStringContainsString('1 files scanned', $r['stdout']);
            $this->assertStringContainsString('1 skipped', $r['stdout']);
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }
}
