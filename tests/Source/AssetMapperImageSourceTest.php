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

namespace Silarhi\PicassoBundle\Tests\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Source\AssetMapperImageSource;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;

class AssetMapperImageSourceTest extends TestCase
{
    private const PHOTO = __DIR__ . '/../Fixtures/photo.jpg';

    public function testFindsAMappedAssetByItsLogicalPath(): void
    {
        $source = new AssetMapperImageSource($this->assetMapper());

        self::assertTrue($source->exists('images/photo.jpg'));
        self::assertTrue($source->exists('/images/photo.jpg'));
        self::assertFalse($source->exists('images/missing.jpg'));
    }

    public function testReadsTheAssetSourceFile(): void
    {
        $stream = (new AssetMapperImageSource($this->assetMapper()))->readStream('images/photo.jpg');

        self::assertSame(file_get_contents(self::PHOTO), stream_get_contents($stream));
        fclose($stream);
    }

    public function testReadingAMissingAssetThrows(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Image "images/missing.jpg" not found.');

        (new AssetMapperImageSource($this->assetMapper()))->readStream('images/missing.jpg');
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsAreMissingWithoutAskingAssetMapper(string $path): void
    {
        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->expects(self::never())->method('getAsset');

        self::assertFalse((new AssetMapperImageSource($assetMapper))->exists($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'empty' => [''];
        yield 'root' => ['/'];
        yield 'parent directory' => ['images/../../.env'];
        yield 'leading parent directory' => ['../.env'];
        yield 'backslashes' => ['images\\..\\..\\.env'];
        yield 'null byte' => ["images/photo.jpg\0.png"];
    }

    private function assetMapper(): AssetMapperInterface
    {
        $assetMapper = self::createStub(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturnCallback(
            static fn (string $logicalPath): ?MappedAsset => 'images/photo.jpg' === $logicalPath
                ? new MappedAsset($logicalPath, self::PHOTO)
                : null,
        );

        return $assetMapper;
    }
}
