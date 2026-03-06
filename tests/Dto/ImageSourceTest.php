<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

        $reflection = new ReflectionClass($source);
        self::assertTrue($reflection->getProperty('type')->isReadOnly());
        self::assertTrue($reflection->getProperty('srcset')->isReadOnly());
    }
}
