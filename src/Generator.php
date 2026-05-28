<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema;

use Parisek\AcfJsonSchema\Emit;
use Parisek\AcfJsonSchema\Extract;

final class Generator {

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

        $fieldCount = count(glob("{$this->output}/refs/field-*.schema.json") ?: []);
        $utilityCount = count(glob("{$this->output}/refs/*.schema.json") ?: []) - $fieldCount;
        echo "Wrote " . (4 + $fieldCount + $utilityCount) . " schemas to {$this->output}/\n";
        // 4 root (block, cpt, taxonomy, acf) + N field refs (currently 36) + 4 utility refs
    }

    private function bootstrapWordPress(): void {
        ob_start();
        if (!defined('ABSPATH')) {
            define('WP_USE_THEMES', false);
        }
        require_once "{$this->wpRoot}/wp-load.php";
        ob_end_clean();
    }

    private function verifyAcfPro(): void {
        if (!function_exists('acf_get_field_types')) {
            throw new \RuntimeException('ACF (Pro) is not active or installed in this WP. Verify by visiting wp-admin/plugins.php.');
        }
        if (!class_exists('ACF_Post_Type')) {
            throw new \RuntimeException('ACF Pro 6.2+ required (ACF_Post_Type class missing).');
        }
    }

    private function writeMetaSidecar(): void {
        global $wp_version;
        $meta = [
            'generator' => 'parisek/acf-json-schema',
            'generator_version' => '0.1.0',
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
