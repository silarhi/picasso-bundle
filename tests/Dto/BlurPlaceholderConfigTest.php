<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\BlurPlaceholderConfig;

class BlurPlaceholderConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new BlurPlaceholderConfig();

        self::assertTrue($config->enabled);
        self::assertSame(10, $config->size);
        self::assertSame(50, $config->blur);
        self::assertSame(30, $config->quality);
    }

    public function testCustomValues(): void
    {
        $config = new BlurPlaceholderConfig(
            enabled: false,
            size: 20,
            blur: 80,
            quality: 50,
        );

        self::assertFalse($config->enabled);
        self::assertSame(20, $config->size);
        self::assertSame(80, $config->blur);
        self::assertSame(50, $config->quality);
    }

    public function testReadonlyProperties(): void
    {
        $config = new BlurPlaceholderConfig();

        $reflection = new \ReflectionClass($config);
        self::assertTrue($reflection->getProperty('enabled')->isReadOnly());
        self::assertTrue($reflection->getProperty('size')->isReadOnly());
        self::assertTrue($reflection->getProperty('blur')->isReadOnly());
        self::assertTrue($reflection->getProperty('quality')->isReadOnly());
    }
}
