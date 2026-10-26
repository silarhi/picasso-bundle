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

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\AssetMapperLoader;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;

class AssetMapperLoaderTest extends TestCase
{
    public function testLoadsAMappedAsset(): void
    {
        $image = $this->loader()->load(new ImageReference('/images/photo.jpg'));

        self::assertSame('images/photo.jpg', $image->path);
        $stream = $image->resolveStream();
        self::assertIsResource($stream);
        self::assertSame(file_get_contents(__DIR__ . '/../Fixtures/photo.jpg'), stream_get_contents($stream));
    }

    public function testAMissingAssetHasNoStream(): void
    {
        $image = $this->loader()->load(new ImageReference('images/missing.jpg'));

        self::assertSame('images/missing.jpg', $image->path);
        self::assertNull($image->resolveStream());
    }

    public function testServesFromItsSource(): void
    {
        self::assertTrue($this->loader()->getSource()->exists('images/photo.jpg'));
    }

    private function loader(): AssetMapperLoader
    {
        $assetMapper = self::createStub(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturnCallback(
            static fn (string $logicalPath): ?MappedAsset => 'images/photo.jpg' === $logicalPath
                ? new MappedAsset($logicalPath, __DIR__ . '/../Fixtures/photo.jpg')
                : null,
        );

        return new AssetMapperLoader($assetMapper);
    }
}
