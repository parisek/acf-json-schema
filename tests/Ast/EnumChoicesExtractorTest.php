<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Ast;

use Parisek\AcfJsonSchema\Ast\EnumChoicesExtractor;
use PHPUnit\Framework\TestCase;

final class EnumChoicesExtractorTest extends TestCase {

    public function test_extracts_choices_from_real_acf_pro_field_link(): void {
        $path = '/Users/pari/Sites/wordpress/keypers/wp-content/plugins/advanced-custom-fields-pro/includes/fields/class-acf-field-link.php';
        if (!file_exists($path)) {
            $this->markTestSkipped('Real ACF Pro source not found at expected path.');
        }

        $extractor = new EnumChoicesExtractor();
        $choices = $extractor->extract($path);

        $this->assertArrayHasKey('return_format', $choices);
        $this->assertContains('array', $choices['return_format']);
        $this->assertContains('url', $choices['return_format']);
    }

    public function test_extracts_choices_from_render_field_settings_acf_link(): void {
        $source = <<<'PHP'
<?php
class acf_field_link {
    function render_field_settings($field) {
        acf_render_field_setting($field, array(
            'label'         => __('Return Format'),
            'instructions'  => '',
            'type'          => 'radio',
            'name'          => 'return_format',
            'choices'       => array(
                'array' => __('Link Array'),
                'url'   => __('Link URL'),
            ),
        ));
    }
}
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'acf-link-test') . '.php';
        file_put_contents($tmp, $source);

        try {
            $extractor = new EnumChoicesExtractor();
            $choices = $extractor->extract($tmp);

            $this->assertArrayHasKey('return_format', $choices);
            $this->assertEquals(['array', 'url'], $choices['return_format']);
        } finally {
            unlink($tmp);
        }
    }
}
