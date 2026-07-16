<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Generator;
use PHPUnit\Framework\TestCase;

final class GeneratorVersionTest extends TestCase {

    public function test_package_version_comes_from_composer_runtime(): void {
        $version = Generator::packageVersion();
        $this->assertSame(
            \Composer\InstalledVersions::getPrettyVersion('parisek/acf-json-schema'),
            $version,
        );
        $this->assertNotSame('', $version);
        // The historical hardcoded literal must never come back unless the
        // installed package genuinely is 0.1.0.
        $this->assertNotSame('0.1.0', $version);
    }
}
