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

    /** @param list<string> $paths */
    private function __construct(
        public readonly bool $strict,
        public readonly bool $fix,
        public readonly bool $wpml,
        public readonly bool $help,
        public readonly bool $version,
        public readonly array $paths,
        public readonly ?string $error,
    ) {}

    /** @param list<string> $args argv without the program name */
    public static function parse(array $args): self {
        $flags = array_fill_keys(self::FLAGS, false);
        $paths = [];
        $error = null;
        $optionsDone = false;

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
                if (str_starts_with($arg, '-') && $arg !== '-') {
                    $error ??= "unknown option: {$arg}";
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
            paths: $paths,
            error: $error,
        );
    }
}
