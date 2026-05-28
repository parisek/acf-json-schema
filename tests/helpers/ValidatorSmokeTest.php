<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Helpers;

use PHPUnit\Framework\TestCase;

final class ValidatorSmokeTest extends TestCase {

    public function test_validator_instantiates(): void {
        $v = new Validator(__DIR__ . '/../../schemas/');
        $this->assertInstanceOf(Validator::class, $v);
    }
}
