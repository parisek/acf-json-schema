<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

use Parisek\AcfJsonSchema\Json;

/**
 * Renders a lint run in one of the supported output formats:
 *
 * - text:   human-oriented; findings on stderr, summary on stdout. ANSI
 *           colors only when the caller opts in (TTY + no NO_COLOR).
 * - json:   one machine-readable document on stdout, nothing on stderr.
 * - github: GitHub Actions workflow commands (::error) on stdout so findings
 *           annotate the PR diff, followed by the plain summary line.
 */
final class Reporter {

    public function __construct(private readonly bool $color) {}

    /**
     * @param list<FileLintResult> $results
     * @return array{stdout: string, stderr: string}
     */
    public function render(array $results, string $format): array {
        return match ($format) {
            'json' => $this->renderJson($results),
            'github' => $this->renderGithub($results),
            default => $this->renderText($results),
        };
    }

    /**
     * @param list<FileLintResult> $results
     * @return array{stdout: string, stderr: string}
     */
    private function renderText(array $results): array {
        $stderr = '';
        foreach ($results as $r) {
            if ($r->skipped || $r->valid) {
                continue;
            }
            $stderr .= $this->paint('✗', '31') . " {$r->path} ({$r->kind})\n";
            foreach ($r->errors as $pointer => $message) {
                $stderr .= '    ' . $this->paint((string) $pointer, '33') . ' — ' . self::stringify($message) . "\n";
            }
        }
        return ['stdout' => $this->summaryLine($results) . "\n", 'stderr' => $stderr];
    }

    /**
     * @param list<FileLintResult> $results
     * @return array{stdout: string, stderr: string}
     */
    private function renderJson(array $results): array {
        $files = [];
        foreach ($results as $r) {
            $errors = [];
            foreach ($r->errors as $pointer => $message) {
                $errors[(string) $pointer] = self::stringify($message);
            }
            $files[] = [
                'path' => $r->path,
                'kind' => $r->kind,
                'valid' => $r->valid,
                'skipped' => $r->skipped,
                'fixed' => $r->fixed,
                'errors' => $errors === [] ? new \stdClass() : $errors,
            ];
        }
        $s = $this->summarize($results);
        $doc = [
            'files' => $files,
            'summary' => [
                'scanned' => $s['scanned'],
                'ok' => $s['ok'],
                'filesWithErrors' => $s['errorFiles'],
                'errors' => $s['errors'],
                'fixed' => $s['fixed'],
                'skipped' => $s['skipped'],
            ],
        ];
        return ['stdout' => Json::encode($doc), 'stderr' => ''];
    }

    /**
     * @param list<FileLintResult> $results
     * @return array{stdout: string, stderr: string}
     */
    private function renderGithub(array $results): array {
        $stdout = '';
        foreach ($results as $r) {
            if ($r->skipped || $r->valid) {
                continue;
            }
            foreach ($r->errors as $pointer => $message) {
                $text = $pointer . ' — ' . self::stringify($message);
                $stdout .= '::error file=' . self::escapeGithubProperty($r->path)
                    . '::' . self::escapeGithubData($text) . "\n";
            }
        }
        $stdout .= $this->summaryLine($results, forceNoColor: true) . "\n";
        return ['stdout' => $stdout, 'stderr' => ''];
    }

    /** @param list<FileLintResult> $results */
    private function summaryLine(array $results, bool $forceNoColor = false): string {
        $s = $this->summarize($results);
        $paint = fn (string $text, string $code): string => $forceNoColor ? $text : $this->paint($text, $code);
        $line = "{$s['scanned']} files scanned, " . $paint("{$s['ok']} OK", '32');
        if ($s['errorFiles'] > 0) {
            $line .= ', ' . $paint("{$s['errorFiles']} with errors ({$s['errors']})", '31');
        }
        if ($s['fixed'] > 0) {
            $line .= ', ' . $paint("{$s['fixed']} fixed", '33');
        }
        if ($s['skipped'] > 0) {
            $line .= ", {$s['skipped']} skipped";
        }
        return $line;
    }

    /**
     * @param list<FileLintResult> $results
     * @return array{scanned: int, ok: int, errorFiles: int, errors: int, fixed: int, skipped: int}
     */
    private function summarize(array $results): array {
        $ok = $errorFiles = $errors = $fixed = $skipped = 0;
        foreach ($results as $r) {
            if ($r->fixed) {
                $fixed++;
            }
            if ($r->skipped) {
                $skipped++;
                continue;
            }
            if ($r->valid) {
                $ok++;
                continue;
            }
            $errorFiles++;
            $errors += max(1, count($r->errors));
        }
        return [
            'scanned' => count($results),
            'ok' => $ok,
            'errorFiles' => $errorFiles,
            'errors' => $errors,
            'fixed' => $fixed,
            'skipped' => $skipped,
        ];
    }

    private function paint(string $text, string $ansiCode): string {
        return $this->color ? "\033[{$ansiCode}m{$text}\033[0m" : $text;
    }

    private static function stringify(mixed $message): string {
        return is_string($message) ? $message : (string) json_encode($message);
    }

    /** Workflow-command property values escape %, CR, LF, : and , */
    private static function escapeGithubProperty(string $value): string {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }

    /** Workflow-command data escapes %, CR and LF. */
    private static function escapeGithubData(string $value): string {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }
}
