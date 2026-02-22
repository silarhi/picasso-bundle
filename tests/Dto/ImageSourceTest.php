<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageSource;

class ImageSourceTest extends TestCase
{
    public function testConstructor(): void
    {
        $source = new ImageSource('image/avif', '/img/photo.avif?w=640 640w');

        self::assertSame('image/avif', $source->type);
        self::assertSame('/img/photo.avif?w=640 640w', $source->srcset);
    }

    public function testReadonlyProperties(): void
    {
        $source = new ImageSource('image/webp', 'srcset-string');

        $reflection = new \ReflectionClass($source);
        self::assertTrue($reflection->getProperty('type')->isReadOnly());
        self::assertTrue($reflection->getProperty('srcset')->isReadOnly());
    }
}
