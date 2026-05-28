<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema;

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
        $fields = (new Extract\FieldExtractor())->emitAll();
        foreach ($fields as $type => $schema) {
            $this->writeJson("{$this->output}/refs/field-{$type}.schema.json", $schema);
        }
        echo "Wrote " . (3 + count($fields)) . " schemas to {$this->output}/\n";
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
