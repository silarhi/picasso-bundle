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

use Closure;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\VichMappingHelperInterface;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Source\FlysystemImageSource;
use Silarhi\PicassoBundle\Source\LocalImageSource;
use stdClass;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoaderTest extends TestCase
{
    private Stub&StorageInterface $storage;
    private MockObject&VichMappingHelperInterface $mappingHelper;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = self::createStub(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelperInterface::class);
    }

    public function testLoadWithoutEntityKeepsThePath(): void
    {
        $this->mappingHelper->expects(self::never())->method('resolveField');

        $image = $this->createLoader()->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertNull($image->stream);
    }

    public function testLoadResolvesTheFieldFromTheLoaderMapping(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::once())->method('resolveField')
            ->with($entity, 'product_image', null)
            ->willReturn('imageFile');
        $this->storage->method('resolvePath')->willReturnMap([
            [$entity, 'imageFile', null, true, '2024/february/photo.jpg'],
        ]);

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => $entity]));

        self::assertSame('2024/february/photo.jpg', $image->path);
    }

    public function testLoadPassesTheFieldContextKeyOn(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::once())->method('resolveField')
            ->with($entity, 'product_image', 'coverFile')
            ->willReturn('coverFile');
        $this->storage->method('resolvePath')->willReturnMap([
            [$entity, 'coverFile', null, true, 'covers/photo.jpg'],
        ]);

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => $entity, 'field' => 'coverFile']));

        self::assertSame('covers/photo.jpg', $image->path);
    }

    public function testLoadIgnoresANonStringFieldContextKey(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::once())->method('resolveField')
            ->with($entity, 'product_image', null)
            ->willReturn('imageFile');

        $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => $entity, 'field' => 42]));
    }

    public function testLoadLetsFieldResolutionErrorsThrough(): void
    {
        $failure = new InvalidImageReferenceException('Wrong mapping.');
        $this->mappingHelper->method('resolveField')->willThrowException($failure);

        $this->expectExceptionObject($failure);

        $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => new stdClass()]));
    }

    public function testLoadStripsLeadingSlash(): void
    {
        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->storage->method('resolvePath')->willReturn('/photo.jpg');

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => new stdClass()]));

        self::assertSame('photo.jpg', $image->path);
    }

    public function testLoadWithoutUploadedFileHasEmptyPath(): void
    {
        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->storage->method('resolvePath')->willReturn(null);

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => new stdClass()]));

        self::assertSame('', $image->path);
    }

    public function testLoadProvidesLazyStream(): void
    {
        $entity = new stdClass();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willReturnMap([[$entity, 'imageFile', null, $stream]]);

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => $entity]));

        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertSame($stream, ($image->stream)());
    }

    public function testLazyStreamReturnsNullOnException(): void
    {
        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willThrowException(new RuntimeException('Stream not available'));

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => new stdClass()]));

        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertNull($image->resolveStream());
    }

    public function testLoadWithMetadataReadsDimensionsAndMimeType(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->mappingHelper->expects(self::once())->method('readDimensions')->with($entity, 'imageFile')->willReturn([1024, 768]);
        $this->mappingHelper->expects(self::once())->method('readMimeType')->with($entity, 'imageFile')->willReturn('image/jpeg');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => $entity]), withMetadata: true);

        self::assertSame(1024, $image->width);
        self::assertSame(768, $image->height);
        self::assertSame('image/jpeg', $image->mimeType);
    }

    public function testLoadWithMetadataReturnsNullWhenAttributesNotConfigured(): void
    {
        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->mappingHelper->method('readDimensions')->willReturn(null);
        $this->mappingHelper->method('readMimeType')->willReturn(null);
        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => new stdClass()]), withMetadata: true);

        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
    }

    public function testLoadWithoutMetadataSkipsDimensionReading(): void
    {
        $this->mappingHelper->method('resolveField')->willReturn('imageFile');
        $this->mappingHelper->expects(self::never())->method('readDimensions');
        $this->mappingHelper->expects(self::never())->method('readMimeType');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->createLoader()->load(new ImageReference('photo.jpg', ['entity' => new stdClass()]));

        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testGetSourceReadsTheFlysystemStorageNamedByTheUploadDestination(): void
    {
        $filesystem = self::createStub(FilesystemOperator::class);
        $storages = self::createStub(ContainerInterface::class);
        $storages->method('has')->willReturnMap([['products.storage', true]]);
        $storages->method('get')->willReturnMap([['products.storage', $filesystem]]);

        $source = $this->createLoader('products.storage', new FlysystemRegistry($storages))->getSource();

        self::assertInstanceOf(FlysystemImageSource::class, $source);
        self::assertSame($filesystem, $source->getStorage());
    }

    public function testGetSourceReadsALocalUploadDestination(): void
    {
        $storages = self::createStub(ContainerInterface::class);
        $storages->method('has')->willReturn(false);

        $source = $this->createLoader('/var/uploads/products', new FlysystemRegistry($storages))->getSource();

        self::assertInstanceOf(LocalImageSource::class, $source);
        self::assertSame('/var/uploads/products', $source->getRoot());
    }

    public function testGetSourceReadsALocalUploadDestinationWithoutFlysystem(): void
    {
        $source = $this->createLoader('/var/uploads/products')->getSource();

        self::assertInstanceOf(LocalImageSource::class, $source);
        self::assertSame('/var/uploads/products', $source->getRoot());
    }

    public function testEachMappingReadsFromItsOwnUploadDestination(): void
    {
        $avatars = new VichUploaderLoader($this->storage, $this->mappingHelper, 'avatar_image', '/var/uploads/avatars');
        $covers = new VichUploaderLoader($this->storage, $this->mappingHelper, 'cover_image', '/var/uploads/covers');

        $avatarSource = $avatars->getSource();
        $coverSource = $covers->getSource();

        self::assertInstanceOf(LocalImageSource::class, $avatarSource);
        self::assertInstanceOf(LocalImageSource::class, $coverSource);
        self::assertSame('/var/uploads/avatars', $avatarSource->getRoot());
        self::assertSame('/var/uploads/covers', $coverSource->getRoot());
    }

    private function createLoader(string $uploadDestination = '/var/uploads', ?FlysystemRegistry $flysystemRegistry = null): VichUploaderLoader
    {
        return new VichUploaderLoader($this->storage, $this->mappingHelper, 'product_image', $uploadDestination, $flysystemRegistry);
    }
}
