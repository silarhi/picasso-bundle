<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

class ImageTransformationTest extends TestCase
{
    public function testDefaults(): void
    {
        $t = new ImageTransformation();

        self::assertNull($t->width);
        self::assertNull($t->height);
        self::assertNull($t->format);
        self::assertSame(75, $t->quality);
        self::assertSame('contain', $t->fit);
        self::assertNull($t->blur);
        self::assertNull($t->dpr);
    }

    public function testAllParams(): void
    {
        $t = new ImageTransformation(
            width: 300,
            height: 200,
            format: 'webp',
            quality: 90,
            fit: 'crop',
            blur: 50,
            dpr: 2,
        );

        self::assertSame(300, $t->width);
        self::assertSame(200, $t->height);
        self::assertSame('webp', $t->format);
        self::assertSame(90, $t->quality);
        self::assertSame('crop', $t->fit);
        self::assertSame(50, $t->blur);
        self::assertSame(2, $t->dpr);
    }

    public function testReadonlyProperties(): void
    {
        $t = new ImageTransformation(width: 100);
        $reflection = new \ReflectionClass($t);

        self::assertTrue($reflection->getProperty('width')->isReadOnly());
        self::assertTrue($reflection->getProperty('quality')->isReadOnly());
    }
}
