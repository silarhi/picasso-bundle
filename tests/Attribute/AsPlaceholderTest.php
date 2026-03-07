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

namespace Silarhi\PicassoBundle\Tests\Attribute;

use Attribute;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Silarhi\PicassoBundle\Attribute\AsPlaceholder;

class AsPlaceholderTest extends TestCase
{
    public function testStoresName(): void
    {
        $attribute = new AsPlaceholder('blur');

        self::assertSame('blur', $attribute->name);
    }

    public function testIsTargetClass(): void
    {
        $reflector = new ReflectionClass(AsPlaceholder::class);
        $attributes = $reflector->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);
        $instance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $instance->flags);
    }
}
