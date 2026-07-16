<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema;

use Parisek\AcfJsonSchema\Emit;
use Parisek\AcfJsonSchema\Extract;

final class Generator {

    /**
     * Flips true once wp-load.php has returned and its output buffer is cleaned.
     * The bootstrap shutdown guard reads it to avoid mislabelling a later,
     * non-bootstrap fatal as a "WordPress bootstrap failed" diagnostic.
     */
    private bool $bootstrapComplete = false;

    public function __construct(
        private readonly string $wpRoot,
        private readonly string $output,
        private readonly bool $pretty = true,
    ) {}

    public function run(): void {
        $this->bootstrapWordPress();
        $this->verifyAcfPro();
        $this->writeMetaSidecar();
        $this->writeJson("{$this->output}/block.schema.json", (new Extract\BlockExtractor())->emit());
        $this->writeJson("{$this->output}/cpt.schema.json", (new Extract\CptExtractor())->emit());
        $this->writeJson("{$this->output}/taxonomy.schema.json", (new Extract\TaxonomyExtractor())->emit());

        $emitter = new Emit\SchemaEmitter();

        // Copy all hand-curated refs (utility refs + all 36 per-type field refs)
        // from src/templates/refs/ to the output directory. These ship verbatim —
        // richer constraints than runtime FieldExtractor can produce.
        //
        // FieldExtractor is retained in src/Extract/ as a discovery aid: invoke it
        // manually to inspect ACF's per-type defaults map and detect new field
        // types added by an ACF upgrade. v0.1.0 ships hand-curated refs because
        // runtime introspection yields only flat type inference ({type: [string,null]})
        // while the curated schemas carry patterns, $refs, enums, and nested objects.
        // See FIELD_TYPE_ORDER in SchemaEmitter for the add-a-new-type workflow.
        $emitter->copyStaticRefs($this->output);

        $this->writeJson("{$this->output}/acf.schema.json", $emitter->emitAcfSchema());
        $this->writeJson("{$this->output}/field-item.schema.json", $emitter->emitFieldItem());

        $fieldCount = count(glob("{$this->output}/refs/field-*.schema.json") ?: []);
        $utilityCount = count(glob("{$this->output}/refs/*.schema.json") ?: []) - $fieldCount;
        echo "Wrote " . (5 + $fieldCount + $utilityCount) . " schemas to {$this->output}/\n";
        // 5 root (block, cpt, taxonomy, acf, field-item) + N field refs (currently 36) + 4 utility refs
    }

    private function bootstrapWordPress(): void {
        if (!defined('ABSPATH')) {
            define('WP_USE_THEMES', false);
        }

        // WP prints notices/deprecations during load; buffer them so they don't
        // corrupt our output. But a fatal inside wp-load.php (DB down, corrupt
        // install) would otherwise be silently discarded by ob_end_clean(),
        // leaving the operator with no diagnostic. Register a shutdown guard that
        // forwards the buffered output to stderr only when the process is dying
        // on a fatal during bootstrap. The $booted flag scopes the guard to the
        // bootstrap window: once wp-load.php has returned and the buffer is
        // cleaned, a later fatal elsewhere in the run must NOT be mislabelled as
        // a bootstrap failure (its buffer would be empty/stale anyway).
        ob_start();
        register_shutdown_function(function (): void {
            if ($this->bootstrapComplete) {
                return;
            }
            $buffer = ob_get_level() > 0 ? (string) ob_get_clean() : '';
            $err = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($err !== null && in_array($err['type'], $fatal, true)) {
                fwrite(STDERR, "WordPress bootstrap failed:\n{$buffer}\n");
            }
        });

        require_once "{$this->wpRoot}/wp-load.php";
        ob_end_clean();
        $this->bootstrapComplete = true;
    }

    private function verifyAcfPro(): void {
        if (!function_exists('acf_get_field_types')) {
            throw new \RuntimeException('ACF (Pro) is not active or installed in this WP. Verify by visiting wp-admin/plugins.php.');
        }
        if (!class_exists('ACF_Post_Type')) {
            throw new \RuntimeException('ACF Pro 6.2+ required (ACF_Post_Type class missing).');
        }
    }

    /**
     * Installed package version for the _meta.json provenance sidecar.
     * Composer's runtime API covers both install modes: as a consumer
     * dependency it reports the locked version; in a standalone checkout the
     * root package reports its VCS-derived version (e.g. dev-main).
     */
    public static function packageVersion(): string {
        if (!\Composer\InstalledVersions::isInstalled('parisek/acf-json-schema')) {
            return 'unknown';
        }
        return \Composer\InstalledVersions::getPrettyVersion('parisek/acf-json-schema') ?? 'unknown';
    }

    private function writeMetaSidecar(): void {
        global $wp_version;
        $meta = [
            'generator' => 'parisek/acf-json-schema',
            'generator_version' => self::packageVersion(),
            'generated_at' => date('c'),
            'acf_version' => defined('ACF_VERSION') ? ACF_VERSION : 'unknown',
            'acf_edition' => defined('ACF_PRO_VERSION') ? 'Pro' : 'Free',
            'wordpress_version' => $wp_version ?? 'unknown',
            'schema_specification' => 'https://json-schema.org/draft/2020-12/schema',
        ];
        $this->writeJson("{$this->output}/_meta.json", $meta);
    }

    /** @param array<string, mixed> $data */
    public function writeJson(string $path, array $data): void {
        $flags = JSON_UNESCAPED_SLASHES;
        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        file_put_contents($path, json_encode($data, $flags) . "\n");
    }
}
