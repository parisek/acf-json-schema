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
    }
}
