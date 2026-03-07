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

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\CacheKeyGenerator;

class CacheKeyGeneratorTest extends TestCase
{
    public function testGenerateIsDeterministic(): void
    {
        $key1 = CacheKeyGenerator::generate('metadata', ['filesystem:photo.jpg']);
        $key2 = CacheKeyGenerator::generate('metadata', ['filesystem:photo.jpg']);

        self::assertSame($key1, $key2);
    }

    public function testGenerateUsesNameAsPrefix(): void
    {
        $key = CacheKeyGenerator::generate('metadata', ['photo.jpg']);

        self::assertStringStartsWith('picasso_metadata_', $key);
    }

    public function testGenerateDiffersForDifferentNames(): void
    {
        $key1 = CacheKeyGenerator::generate('metadata', ['photo.jpg']);
        $key2 = CacheKeyGenerator::generate('blurhash', ['photo.jpg']);

        self::assertNotSame($key1, $key2);
    }

    public function testGenerateDiffersForDifferentArguments(): void
    {
        $key1 = CacheKeyGenerator::generate('blurhash', ['filesystem', 'photo.jpg', 100, 75]);
        $key2 = CacheKeyGenerator::generate('blurhash', ['flysystem', 'photo.jpg', 100, 75]);

        self::assertNotSame($key1, $key2);
    }

    public function testGenerateHandlesIntArguments(): void
    {
        $key = CacheKeyGenerator::generate('blurhash', ['fs', 'photo.jpg', 100, 75, 4, 3, 32]);

        self::assertStringStartsWith('picasso_blurhash_', $key);
    }
}
