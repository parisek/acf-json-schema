<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

/**
 * Parsed acf-lint command line. Unknown options are a hard error rather than
 * being silently treated as paths — a misspelled --strict must not degrade
 * the CI gate into an always-green no-op.
 */
final class CliOptions {

    private const FLAGS = ['--strict', '--fix', '--wpml', '--help', '--version'];

    public const FORMATS = ['text', 'json', 'github'];

    public const DEFAULT_MAX_ERRORS = 50;

    /** @param list<string> $paths */
    private function __construct(
        public readonly bool $strict,
        public readonly bool $fix,
        public readonly bool $wpml,
        public readonly bool $help,
        public readonly bool $version,
        public readonly string $format,
        public readonly int $maxErrors,
        public readonly array $paths,
        public readonly ?string $error,
    ) {}

    /** @param list<string> $args argv without the program name */
    public static function parse(array $args): self {
        $flags = array_fill_keys(self::FLAGS, false);
        $format = 'text';
        $maxErrors = self::DEFAULT_MAX_ERRORS;
        $paths = [];
        $error = null;
        $optionsDone = false;

        $fail = static function (string $message) use (&$error): void {
            $error ??= $message;
        };

        foreach ($args as $arg) {
            if (!$optionsDone) {
                if ($arg === '--') {
                    $optionsDone = true;
                    continue;
                }
                if (array_key_exists($arg, $flags)) {
                    $flags[$arg] = true;
                    continue;
                }
                if ($arg === '--format') {
                    $fail('option --format requires a value (--format=<text|json|github>)');
                    continue;
                }
                if ($arg === '--max-errors') {
                    $fail('option --max-errors requires a value (--max-errors=<N>)');
                    continue;
                }
                if (str_starts_with($arg, '--format=')) {
                    $value = substr($arg, strlen('--format='));
                    if (!in_array($value, self::FORMATS, true)) {
                        $fail("invalid --format value: {$value} (expected text, json or github)");
                        continue;
                    }
                    $format = $value;
                    continue;
                }
                if (str_starts_with($arg, '--max-errors=')) {
                    $value = substr($arg, strlen('--max-errors='));
                    if (!ctype_digit($value) || (int) $value < 1) {
                        $fail("invalid --max-errors value: {$value} (expected a positive integer)");
                        continue;
                    }
                    $maxErrors = (int) $value;
                    continue;
                }
                if (str_starts_with($arg, '-') && $arg !== '-') {
                    $fail("unknown option: {$arg}");
                    continue;
                }
            }
            $paths[] = $arg;
        }

        return new self(
            strict: $flags['--strict'],
            fix: $flags['--fix'],
            wpml: $flags['--wpml'],
            help: $flags['--help'],
            version: $flags['--version'],
            format: $format,
            maxErrors: $maxErrors,
            paths: $paths,
            error: $error,
        );
    }
}
