<?php

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\NullBlurHashGenerator;

class NullBlurHashGeneratorTest extends TestCase
{
    public function testIsEnabledReturnsFalse(): void
    {
        $generator = new NullBlurHashGenerator();

        self::assertFalse($generator->isEnabled());
    }

    public function testGenerateReturnsNull(): void
    {
        $generator = new NullBlurHashGenerator();

        self::assertNull($generator->generate('photo.jpg'));
        self::assertNull($generator->generate('photo.jpg', 1920, 1080));
    }
}
