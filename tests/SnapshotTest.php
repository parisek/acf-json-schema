<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Generator;
use PHPUnit\Framework\TestCase;

final class SnapshotTest extends TestCase {

    private const WP_ROOT_ENV = 'ACF_SCHEMA_TEST_WP_ROOT';
    private const SCHEMAS_DIR = __DIR__ . '/../schemas';

    /** @var list<callable>|null Snapshot of error handlers captured in setUp, or null when test is skipped */
    private ?array $errorHandlersBefore = null;

    protected function setUp(): void {
        if (getenv(self::WP_ROOT_ENV) === false) {
            $this->markTestSkipped(
                'Set ' . self::WP_ROOT_ENV . '=/path/to/wp/install to run snapshot test'
            );
        }

        // Capture the error-handler stack before the test so tearDown can restore
        // it to exactly this state after WordPress bootstrap adds its own handlers.
        // This snapshot is only taken when the test is going to run (not skipped).
        $this->errorHandlersBefore = $this->snapshotErrorHandlers();
    }

    protected function tearDown(): void {
        if ($this->errorHandlersBefore === null) {
            // Test was skipped in setUp — no handlers were captured, nothing to restore.
            return;
        }
        // WordPress bootstrap registers error handlers that it does not restore.
        // PHPUnit 12 marks a test risky when the handler stack differs before vs.
        // after. Restore to the pre-test snapshot: drain to empty, re-push originals.
        $this->drainErrorHandlers();
        foreach ($this->errorHandlersBefore as $handler) {
            set_error_handler($handler);
        }
    }

    /** @return list<callable> */
    private function snapshotErrorHandlers(): array {
        $handlers = [];
        while (true) {
            $prev = set_error_handler(static fn () => false);
            restore_error_handler(); // remove the sentinel
            if ($prev === null) {
                break;
            }
            $handlers[] = $prev;
            restore_error_handler(); // pop the real handler
        }
        $handlers = array_reverse($handlers);
        foreach ($handlers as $h) {
            set_error_handler($h);
        }
        return $handlers;
    }

    private function drainErrorHandlers(): void {
        while (true) {
            $prev = set_error_handler(static fn () => false);
            restore_error_handler();
            if ($prev === null) {
                break;
            }
            restore_error_handler();
        }
    }

    public function test_generator_output_matches_committed_schemas(): void {
        $wpRoot = getenv(self::WP_ROOT_ENV);
        $this->assertNotFalse($wpRoot);

        $tmpOut = sys_get_temp_dir() . '/acf-schema-snapshot-' . uniqid();
        mkdir($tmpOut, 0755, true);
        mkdir("{$tmpOut}/refs", 0755, true);

        try {
            $generator = new Generator($wpRoot, $tmpOut, pretty: true);
            $generator->run();

            $expected = $this->collectSchemaFiles(self::SCHEMAS_DIR);
            $actual = $this->collectSchemaFiles($tmpOut);

            foreach ($expected as $relPath => $expectedContent) {
                $this->assertArrayHasKey($relPath, $actual, "Generator did not produce {$relPath}");
                $this->assertJsonStringEqualsJsonString(
                    $expectedContent,
                    $actual[$relPath],
                    "Generator output for {$relPath} differs from committed schema. Run bin/acf-schema-gen + commit if intentional."
                );
            }
        } finally {
            $this->rmRecursive($tmpOut);
        }
    }

    /** @return array<string, string> */
    private function collectSchemaFiles(string $base): array {
        $files = [];
        foreach (glob("{$base}/*.json") ?: [] as $f) {
            if (basename($f) === '_meta.json') {
                continue;
            }
            $contents = file_get_contents($f);
            if ($contents !== false) {
                $files[basename($f)] = $contents;
            }
        }
        foreach (glob("{$base}/refs/*.json") ?: [] as $f) {
            $contents = file_get_contents($f);
            if ($contents !== false) {
                $files['refs/' . basename($f)] = $contents;
            }
        }
        return $files;
    }

    private function rmRecursive(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (glob("{$dir}/*") ?: [] as $item) {
            is_dir($item) ? $this->rmRecursive($item) : unlink($item);
        }
        rmdir($dir);
    }
}
