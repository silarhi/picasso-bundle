<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageDimensions;

class ImageDimensionsTest extends TestCase
{
    public function testConstructor(): void
    {
        $dims = new ImageDimensions(1920, 1080);

        self::assertSame(1920, $dims->width);
        self::assertSame(1080, $dims->height);
    }

    public function testReadonlyProperties(): void
    {
        $dims = new ImageDimensions(800, 600);

        $reflection = new \ReflectionClass($dims);
        self::assertTrue($reflection->getProperty('width')->isReadOnly());
        self::assertTrue($reflection->getProperty('height')->isReadOnly());
    }
}
